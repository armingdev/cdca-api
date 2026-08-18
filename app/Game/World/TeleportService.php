<?php

namespace App\Game\World;

use App\Game\Data\BackpackItem;
use App\Game\Data\RoomBlob;
use App\Game\Data\TeleportSyncResult;
use App\Game\Enums\TeleportKind;
use App\Game\Exceptions\DesyncException;
use App\Game\Exceptions\GameException;
use App\Game\Http\GameClient;
use App\Game\Items\BackpackService;
use App\Game\Parsers\TeleportDestinationsParser;
use App\Models\Character;
use App\Models\CharacterSkill;
use App\Models\CharacterTeleportAnchor;
use App\Models\TeleportAnchor;
use Illuminate\Support\Facades\Log;

/**
 * Teleports for one character. Three kinds of jump reach a room without
 * walking: an activatable key-tab item (free, no cooldown, not consumed), the
 * Teleport skill (100 rage, 60 min, only if trained), and the free return to
 * the character's home tavern.
 *
 * Availability is per character — items depend on level and quest progress,
 * the skill on having trained it — so anchors are always read from the game
 * per character and only the *catalog* half is shared globally.
 *
 * See docs/game-api/teleports.md (VERIFIED 2026-08-16).
 */
class TeleportService
{
    /** The Teleport skill. Single-level, 100 rage, 60 minute cooldown. */
    public const int TELEPORT_SKILL_ID = 27;

    public function __construct(
        private readonly Character $character,
        private readonly GameClient $client,
        private readonly BackpackService $backpack,
        private readonly Navigator $navigator,
        private readonly TeleportDestinationsParser $destinationsParser,
    ) {}

    public static function forCharacter(Character $character): self
    {
        return new self(
            $character,
            GameClient::forCharacter($character),
            BackpackService::forCharacter($character),
            Navigator::forCharacter($character),
            app(TeleportDestinationsParser::class),
        );
    }

    /**
     * Read what this character can teleport with: the key tab's activatable
     * items, plus the Teleport skill's destination list when it is trained.
     * Anchors that vanished since the last sync are flagged unavailable
     * rather than deleted — the catalog row stays useful to other characters.
     */
    public function syncAnchors(): TeleportSyncResult
    {
        $discovered = 0;
        $seen = [];

        foreach ($this->activatableItems() as $item) {
            $anchor = $this->anchorForItem($item, $discovered);
            $this->linkToCharacter($anchor, $item->iid);
            $seen[] = $anchor->id;
        }

        $skillAnchors = $this->syncSkillDestinations($discovered, $seen);

        $unavailable = CharacterTeleportAnchor::query()
            ->where('character_id', $this->character->id)
            ->when($seen !== [], fn ($query) => $query->whereNotIn('teleport_anchor_id', $seen))
            ->where('is_available', true)
            ->update(['is_available' => false, 'synced_at' => now()]);

        return new TeleportSyncResult(
            itemAnchors: count($seen) - $skillAnchors,
            skillAnchors: $skillAnchors,
            discovered: $discovered,
            unavailable: $unavailable,
            withoutDestination: TeleportAnchor::query()
                ->whereIn('id', $seen)
                ->whereNull('room_id')
                ->count(),
        );
    }

    /**
     * Teleport with an anchor, whichever kind it is.
     */
    public function jump(TeleportAnchor $anchor): RoomBlob
    {
        return match ($anchor->kind) {
            TeleportKind::Item => $this->activateItem($anchor),
            TeleportKind::Skill => $this->castTeleport($anchor->room_id ?? 0),
        };
    }

    /**
     * Activate a teleport item. Free, uncapped, and not consumed — the item
     * stays in the backpack, so this is the engine's cheap fast travel.
     *
     * The response carries only a status string; the move is already applied
     * server-side, so the landing room comes from a plain room load (the
     * browser's /world redirect is not needed).
     */
    public function activateItem(TeleportAnchor $anchor): RoomBlob
    {
        $link = $this->availableLink($anchor);

        if ($link->iid === null) {
            throw new GameException("Teleport item {$anchor->name} has no instance id for character {$this->character->name}; sync anchors first.");
        }

        $response = $this->client->post('ajax/backpack_action.php', [
            'action' => 'activate',
            'itemids' => [$link->iid],
        ]);

        if (! str_contains($response->body(), 'activated')) {
            throw new GameException("Activating {$anchor->name} was not confirmed: ".substr($response->body(), 0, 200));
        }

        $blob = $this->navigator->loadCurrentRoom();

        $this->recordDestination($anchor, $blob->curRoom);
        $link->update(['last_used_at' => now()]);

        return $blob;
    }

    /**
     * Cast the Teleport skill to one of its dropdown rooms. Unlike an item
     * this is expensive (100 rage) and burns a 60-minute cooldown, so the
     * caller should only reach for it when no item covers the destination.
     */
    public function castTeleport(int $destinationRoomId): RoomBlob
    {
        if ($destinationRoomId <= 0) {
            throw new GameException('Teleport needs a destination room id.');
        }

        $state = $this->teleportSkillState();

        if ($state === null || ! $state->isCastable()) {
            throw new GameException("Character {$this->character->name} has not trained Teleport.");
        }

        if ($state->isOnCooldown()) {
            throw new GameException("Teleport is on cooldown until {$state->cooldownEndsAt()}.");
        }

        $cost = $state->current_rage_cost ?? $state->skill->rage_cost ?? 0;

        if ($this->character->rage < $cost) {
            throw new GameException("Teleport needs {$cost} rage, character has {$this->character->rage}.");
        }

        // The captured form posts to the extensionless /cast_skills route with
        // the extra `dest` field; `dest` exists only in skills_info.php?id=27.
        $this->client->post('cast_skills', [
            'dest' => $destinationRoomId,
            'castskillid' => self::TELEPORT_SKILL_ID,
            'cast' => 'Cast Skill',
        ]);

        $state->update([
            'last_cast_at' => now(),
            'recharge_until' => null,
            'buff_until' => null,
        ]);

        $blob = $this->navigator->loadCurrentRoom();

        if ($blob->curRoom !== $destinationRoomId) {
            throw DesyncException::positionMismatch($destinationRoomId, $blob->curRoom);
        }

        return $blob;
    }

    /**
     * The free return trip: world.php?teleport=1 lands in the character's home
     * tavern. Where that is only becomes knowable by arriving, so the landing
     * room is written back to the character.
     */
    public function toHomeTavern(): RoomBlob
    {
        $this->client->get('world.php', ['teleport' => 1]);

        $blob = $this->navigator->loadCurrentRoom();

        $this->character->update(['home_tavern_room_id' => $blob->curRoom]);

        return $blob;
    }

    /**
     * Re-home the free teleport anchor to a tavern the character has reached
     * (the link the room blob serves as tavernData).
     */
    public function setHomeTavern(int $roomId): void
    {
        $this->client->get('world.php', ['teleportupdate' => 1, 'tavern' => $roomId]);

        $this->character->update(['home_tavern_room_id' => $roomId]);
    }

    /**
     * Anchors this character can use right now for planning: available, and
     * with a known landing room.
     *
     * @return list<TeleportAnchor>
     */
    public function usableAnchors(): array
    {
        return TeleportAnchor::query()
            ->whereNotNull('room_id')
            ->whereHas('characterAnchors', fn ($query) => $query
                ->where('character_id', $this->character->id)
                ->where('is_available', true))
            ->get()
            ->all();
    }

    /**
     * Usable anchors that cost nothing: item jumps. Runs plan with these only —
     * rage is the run's fuel, so a run must never spend 100 of it (and a
     * 60-minute cooldown) on travel behind the operator's back.
     *
     * @return list<TeleportAnchor>
     */
    public function freeAnchors(): array
    {
        return array_values(array_filter(
            $this->usableAnchors(),
            fn (TeleportAnchor $anchor): bool => $anchor->isFree(),
        ));
    }

    /**
     * Execute a plan: take its jump (if any), then walk the rest.
     */
    public function travel(TeleportPlan $plan): RoomBlob
    {
        if ($plan->anchor !== null) {
            $this->jump($plan->anchor);
        } elseif ($plan->useHomeTavern) {
            $this->toHomeTavern();
        }

        return count($plan->walkPath) > 1
            ? $this->navigator->walk($plan->walkPath)
            : $this->navigator->loadCurrentRoom();
    }

    /**
     * Available anchors whose landing room has never been observed. Activating
     * each one once is how the catalog learns where it goes — safe to repeat,
     * since items are free and not consumed.
     *
     * @return list<TeleportAnchor>
     */
    public function discoveryTargets(): array
    {
        return TeleportAnchor::query()
            ->whereNull('room_id')
            ->where('kind', TeleportKind::Item)
            ->whereHas('characterAnchors', fn ($query) => $query
                ->where('character_id', $this->character->id)
                ->where('is_available', true))
            ->get()
            ->all();
    }

    /**
     * @return list<BackpackItem>
     */
    private function activatableItems(): array
    {
        return array_values(array_filter(
            $this->backpack->contents('key')->items,
            fn (BackpackItem $item): bool => $item->canActivate() && $item->gameItemId !== null,
        ));
    }

    /**
     * The catalog row for an item, created on first sight anywhere. The
     * rollover is only fetched for a genuinely new item — it is one HTTP call
     * per item and nothing on it changes afterwards.
     */
    private function anchorForItem(BackpackItem $item, int &$discovered): TeleportAnchor
    {
        $anchor = TeleportAnchor::query()->firstWhere([
            'kind' => TeleportKind::Item,
            'game_item_id' => $item->gameItemId,
        ]);

        if ($anchor !== null) {
            $anchor->update(['last_verified_at' => now()]);

            return $anchor;
        }

        $detail = $this->backpack->itemDetail($item->iid);
        $discovered++;

        return TeleportAnchor::create([
            'kind' => TeleportKind::Item,
            'game_item_id' => $item->gameItemId,
            'name' => $item->name,
            'room_id' => null,
            'required_level' => $detail->requiredLevel,
            'rage_cost' => 0,
            'cooldown_minutes' => 0,
            'description' => $detail->description,
            'source' => 'observed',
            'first_seen_at' => now(),
            'last_verified_at' => now(),
        ]);
    }

    /**
     * One anchor per row of the Teleport skill's dropdown. These already know
     * their destination — the option value *is* the room id.
     *
     * @param  list<int>  $seen
     */
    private function syncSkillDestinations(int &$discovered, array &$seen): int
    {
        $state = $this->teleportSkillState();

        if ($state === null || ! $state->isCastable()) {
            return 0;
        }

        $response = $this->client->get('skills_info.php', ['id' => self::TELEPORT_SKILL_ID]);
        $destinations = $this->destinationsParser->parse($response->body());
        $rageCost = $state->current_rage_cost ?? $state->skill->rage_cost ?? 100;
        $cooldown = $state->current_cooldown_minutes ?? $state->skill->cooldown_minutes ?? 60;

        foreach ($destinations as $destination) {
            $key = [
                'kind' => TeleportKind::Skill,
                'skill_id' => self::TELEPORT_SKILL_ID,
                'room_id' => $destination->roomId,
            ];

            $existing = TeleportAnchor::query()->firstWhere($key);

            if ($existing === null) {
                $discovered++;
            }

            $anchor = TeleportAnchor::query()->updateOrCreate($key, [
                'name' => $destination->name,
                'rage_cost' => $rageCost,
                'cooldown_minutes' => $cooldown,
                'source' => 'observed',
                'first_seen_at' => $existing?->first_seen_at ?? now(),
                'last_verified_at' => now(),
            ]);

            $this->linkToCharacter($anchor, null);
            $seen[] = $anchor->id;
        }

        return count($destinations);
    }

    private function linkToCharacter(TeleportAnchor $anchor, ?int $iid): CharacterTeleportAnchor
    {
        return CharacterTeleportAnchor::updateOrCreate(
            [
                'character_id' => $this->character->id,
                'teleport_anchor_id' => $anchor->id,
            ],
            [
                'iid' => $iid,
                'is_available' => true,
                'synced_at' => now(),
            ],
        );
    }

    private function availableLink(TeleportAnchor $anchor): CharacterTeleportAnchor
    {
        $link = CharacterTeleportAnchor::query()
            ->where('character_id', $this->character->id)
            ->where('teleport_anchor_id', $anchor->id)
            ->first();

        if ($link === null || ! $link->is_available) {
            throw new GameException("Character {$this->character->name} cannot use teleport anchor {$anchor->name}.");
        }

        return $link;
    }

    /**
     * Fill in a landing room the first time it is observed. A *changed* one
     * means the game moved the destination: keep the newer reading, but say so
     * loudly — plans built on the old room are wrong from here on.
     */
    private function recordDestination(TeleportAnchor $anchor, int $roomId): void
    {
        if ($anchor->room_id !== null && $anchor->room_id !== $roomId) {
            Log::warning('Teleport anchor destination changed', [
                'anchor' => $anchor->name,
                'was' => $anchor->room_id,
                'now' => $roomId,
                'character_id' => $this->character->id,
            ]);
        }

        $anchor->update([
            'room_id' => $roomId,
            'last_verified_at' => now(),
        ]);
    }

    private function teleportSkillState(): ?CharacterSkill
    {
        return CharacterSkill::with('skill')
            ->where('character_id', $this->character->id)
            ->where('skill_id', self::TELEPORT_SKILL_ID)
            ->first();
    }
}
