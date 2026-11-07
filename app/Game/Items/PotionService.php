<?php

namespace App\Game\Items;

use App\Game\Data\ActiveEffect;
use App\Game\Data\BackpackItem;
use App\Game\Data\PotionActivation;
use App\Game\Enums\PotionActivationOutcome;
use App\Game\Enums\PotionKind;
use App\Game\Http\GameClient;
use App\Game\Parsers\BackpackContentsParser;
use App\Game\Parsers\PotionEffectParser;
use App\Game\Parsers\SkillsPageParser;
use App\Models\Character;
use App\Models\CharacterPotion;
use App\Models\Potion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One character's potions: what the potion tab holds, which are active, and
 * drinking one.
 *
 * Drinking is the teleport items' verb — POST ajax/backpack_action.php
 * action=activate — but each use hands the stack's next item a new iid, so
 * the tab is re-read right before every activation. Active potions show in
 * cast_skills.php's Current Effects panel next to the skills, with the real
 * time left (which can run past the catalog's duration).
 */
class PotionService
{
    public function __construct(
        private readonly Character $character,
        private readonly GameClient $client,
        private readonly BackpackContentsParser $contentsParser,
        private readonly SkillsPageParser $skillsPageParser,
        private readonly PotionEffectParser $effectParser,
        private readonly BackpackService $backpack,
    ) {}

    public static function forCharacter(Character $character): self
    {
        return new self(
            $character,
            GameClient::forCharacter($character),
            app(BackpackContentsParser::class),
            app(SkillsPageParser::class),
            app(PotionEffectParser::class),
            BackpackService::forCharacter($character),
        );
    }

    /**
     * Read the potion tab and the active effects from the game and store
     * both. A potion no longer in the tab is kept at quantity 0.
     *
     * @return Collection<int, CharacterPotion> keyed by potion_id
     */
    public function sync(): Collection
    {
        $held = $this->readTab();

        CharacterPotion::where('character_id', $this->character->id)
            ->whereNotIn('potion_id', $held->keys())
            ->update(['quantity' => 0, 'iid' => null, 'synced_at' => now()]);

        foreach ($held as $potionId => $item) {
            CharacterPotion::updateOrCreate(
                ['character_id' => $this->character->id, 'potion_id' => $potionId],
                ['quantity' => $item->qty, 'iid' => $item->iid, 'synced_at' => now()],
            );
        }

        $this->syncEffects();

        return $this->stacks();
    }

    /**
     * Store the time left of every active potion; a potion missing from the
     * Current Effects panel is not active (a reading, not a gap).
     */
    public function syncEffects(): void
    {
        $effects = collect($this->skillsPageParser->parse($this->client->get('cast_skills.php')->body())->currentEffects)
            ->keyBy(fn (ActiveEffect $effect): string => mb_strtolower($effect->name));

        $active = Potion::query()
            ->whereIn(DB::raw('lower(name)'), $effects->keys()->all())
            ->get();

        foreach ($active as $potion) {
            CharacterPotion::firstOrCreate(
                ['character_id' => $this->character->id, 'potion_id' => $potion->id],
                ['quantity' => 0],
            );
        }

        foreach ($this->stacks() as $stack) {
            $effect = $effects->get(mb_strtolower($stack->potion->name));

            $stack->update([
                'active_until' => $effect === null ? null : now()->addMinutes($effect->minutesLeft),
                'active_synced_at' => now(),
            ]);
        }
    }

    /**
     * Drink one of this potion. The tab is read first because the stack's
     * instance id changes after every use.
     */
    public function activate(Potion $potion): PotionActivation
    {
        $item = $this->readTab()->get($potion->id);

        $stack = CharacterPotion::firstOrNew(['character_id' => $this->character->id, 'potion_id' => $potion->id]);
        $stack->setRelation('potion', $potion);

        if ($item === null) {
            $stack->fill(['quantity' => 0, 'iid' => null, 'synced_at' => now()])->save();

            return new PotionActivation(PotionActivationOutcome::OutOfStock, "No {$potion->name} left.", $stack);
        }

        $response = $this->client->post('ajax/backpack_action.php', [
            'action' => 'activate',
            'itemids' => [$item->iid],
        ]);

        $status = (string) $response->json('status', '');
        $error = (string) $response->json('error', '');

        if (str_contains($status, 'activated')) {
            $stack->fill([
                'quantity' => max($item->qty - 1, 0),
                // The next item of the stack has a new iid; the next read has it.
                'iid' => null,
                'last_activated_at' => now(),
                'active_until' => $potion->kind === PotionKind::Buff && $potion->duration_minutes !== null
                    ? now()->addMinutes($potion->duration_minutes)
                    : null,
                'refusals' => 0,
                'refused_until' => null,
                'synced_at' => now(),
            ])->save();

            return new PotionActivation(PotionActivationOutcome::Activated, strip_tags($status), $stack);
        }

        $stack->fill(['quantity' => $item->qty, 'iid' => $item->iid, 'synced_at' => now()])->save();

        if (str_contains($error, 'already consumed')) {
            // Nothing was used. Ask the game how long it has left instead of guessing.
            $this->syncEffects();

            return new PotionActivation(PotionActivationOutcome::AlreadyActive, $error, $stack->refresh());
        }

        $stack->recordRefusal();
        $message = $error !== '' ? $error : (strip_tags($status) ?: substr($response->body(), 0, 200));

        return new PotionActivation(PotionActivationOutcome::Refused, $message, $stack);
    }

    /**
     * @return Collection<int, CharacterPotion> keyed by potion_id
     */
    public function stacks(): Collection
    {
        return CharacterPotion::with('potion')
            ->where('character_id', $this->character->id)
            ->get()
            ->keyBy('potion_id');
    }

    /**
     * The potion tab, keyed by catalog potion id. A potion the catalog does
     * not know yet is learned from its tooltip.
     *
     * @return Collection<int, BackpackItem>
     */
    private function readTab(): Collection
    {
        $response = $this->client->get('ajax/backpackcontents.php', ['tab' => 'potion']);
        $items = collect($this->contentsParser->parse($response->body())->items)
            ->filter(fn (BackpackItem $item): bool => $item->gameItemId !== null);

        $known = Potion::whereIn('game_item_id', $items->pluck('gameItemId'))->get()->keyBy('game_item_id');

        return $items->mapWithKeys(function (BackpackItem $item) use ($known): array {
            $potion = $known->get($item->gameItemId) ?? $this->learn($item);

            return [$potion->id => $item];
        });
    }

    private function learn(BackpackItem $item): Potion
    {
        $description = $this->backpack->itemDetail($item->iid)->description ?? '';
        $effect = $this->effectParser->parse($item->name, $description);

        return Potion::firstOrCreate(['game_item_id' => $item->gameItemId], [
            'name' => $item->name,
            // The tab says "/images/items/x.jpg"; the catalog stores "items/x.jpg".
            'image' => preg_replace('#^/?images/#', '', $item->image) ?: null,
            'description' => $description,
            'kind' => $effect->kind,
            'duration_minutes' => $effect->durationMinutes,
            'rage_amount' => $effect->rageAmount,
        ]);
    }
}
