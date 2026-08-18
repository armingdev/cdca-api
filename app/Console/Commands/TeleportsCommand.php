<?php

namespace App\Console\Commands;

use App\Game\Auth\LoginService;
use App\Game\Exceptions\GameException;
use App\Game\World\Navigator;
use App\Game\World\RoomGraph;
use App\Game\World\TeleportPlanner;
use App\Game\World\TeleportService;
use App\Models\Character;
use App\Models\Room;
use App\Models\TeleportAnchor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('outwar:teleports
    {action=list : list | sync | discover | go}
    {character? : Character id or name}
    {--room= : Destination room id (for go)}
    {--anchor= : Anchor id to jump with (for go, skips planning)}')]
#[Description("Manage a character's teleport anchors: list them, sync from the game, discover where they land, travel with them")]
class TeleportsCommand extends Command
{
    public function handle(LoginService $loginService): int
    {
        $character = $this->resolveCharacter();

        if ($character === null) {
            $this->error('Character not found.');

            return self::FAILURE;
        }

        if ($this->argument('action') !== 'list' && ! $character->rga->hasSession()) {
            $this->line('No session yet — logging in first…');
            $loginService->login($character->rga);
        }

        return match ($this->argument('action')) {
            'list' => $this->list($character),
            'sync' => $this->sync($character),
            'discover' => $this->discover($character),
            'go' => $this->go($character),
            default => $this->unknownAction(),
        };
    }

    private function unknownAction(): int
    {
        $this->error("Unknown action '{$this->argument('action')}'. Use list, sync, discover, or go.");

        return self::FAILURE;
    }

    private function list(Character $character): int
    {
        $links = $character->teleportAnchors()->with('anchor')->get()
            ->sortBy(fn ($link): string => $link->anchor->name);

        if ($links->isEmpty()) {
            $this->warn("No anchors known for {$character->name} — run: outwar:teleports sync {$character->name}");

            return self::SUCCESS;
        }

        $this->table(
            ['Anchor', 'ID', 'Kind', 'Lands in', 'Cost', 'Available'],
            $links->map(fn ($link): array => [
                $link->anchor->name,
                $link->anchor->id,
                $link->anchor->kind->value,
                $link->anchor->room_id !== null
                    ? $link->anchor->room_id.' '.(Room::find($link->anchor->room_id)?->name ?? '')
                    : '— unknown —',
                $link->anchor->isFree() ? 'free' : "{$link->anchor->rage_cost} rage / {$link->anchor->cooldown_minutes}m",
                $link->is_available ? 'yes' : 'no',
            ]),
        );

        $home = $character->home_tavern_room_id;
        $this->line($home !== null
            ? "Home tavern: {$home} ".(Room::find($home)?->name ?? '')
            : 'Home tavern: not known yet (run `go --room=` or teleport home once).');

        return self::SUCCESS;
    }

    private function sync(Character $character): int
    {
        $result = TeleportService::forCharacter($character)->syncAnchors();

        $this->info(sprintf(
            '%d anchors available: %d items, %d skill destinations.',
            $result->total(),
            $result->itemAnchors,
            $result->skillAnchors,
        ));

        $this->line(sprintf(
            '%d new to the catalog, %d no longer held, %d without a known landing room.',
            $result->discovered,
            $result->unavailable,
            $result->withoutDestination,
        ));

        if ($result->withoutDestination > 0) {
            $this->line("Run `outwar:teleports discover {$character->name}` to learn where they land.");
        }

        return self::SUCCESS;
    }

    /**
     * Activate every anchor whose landing room we have never seen. Items are
     * free, uncapped and not consumed, so this is a cheap one-off per anchor.
     */
    private function discover(Character $character): int
    {
        $service = TeleportService::forCharacter($character);
        $targets = $service->discoveryTargets();

        if ($targets === []) {
            $this->info('Every available anchor already has a known landing room.');

            return self::SUCCESS;
        }

        $this->info(count($targets).' anchors to discover.');

        foreach ($targets as $anchor) {
            try {
                $blob = $service->activateItem($anchor);
                $this->line(sprintf('%-32s → %d %s', $anchor->name, $blob->curRoom, $blob->name));
            } catch (GameException $exception) {
                $this->warn("{$anchor->name}: {$exception->getMessage()}");
            }
        }

        return self::SUCCESS;
    }

    private function go(Character $character): int
    {
        $service = TeleportService::forCharacter($character);

        if ($this->option('anchor') !== null) {
            $anchor = TeleportAnchor::find((int) $this->option('anchor'));

            if ($anchor === null) {
                $this->error('Anchor not found.');

                return self::FAILURE;
            }

            $blob = $service->jump($anchor);
            $this->info("Teleported to {$blob->curRoom} {$blob->name}.");

            return self::SUCCESS;
        }

        $destination = (int) $this->option('room');

        if ($destination <= 0) {
            $this->error('Pass --room={id} (or --anchor={id}).');

            return self::FAILURE;
        }

        $navigator = Navigator::forCharacter($character);
        $from = $navigator->loadCurrentRoom()->curRoom;

        $plan = new TeleportPlanner(RoomGraph::fromDatabase())->plan(
            $from,
            $destination,
            $service->usableAnchors(),
            $character->home_tavern_room_id,
        );

        if ($plan === null) {
            $this->error("No route from {$from} to {$destination} — not even with a teleport.");

            return self::FAILURE;
        }

        if ($plan->anchor !== null) {
            $this->line("Teleporting with {$plan->anchor->name}…");
            $service->jump($plan->anchor);
        } elseif ($plan->useHomeTavern) {
            $this->line('Teleporting to the home tavern…');
            $service->toHomeTavern();
        }

        $steps = $plan->steps();

        if ($steps > 0) {
            $this->line(sprintf('Walking %d %s…', $steps, str('room')->plural($steps)));
            $navigator->walk($plan->walkPath);
        }

        $this->info(sprintf(
            'Arrived in room %d (%d requests: %s%d %s).',
            $destination,
            $plan->cost(),
            $plan->usesTeleport() ? 'jump + ' : '',
            $steps,
            str('step')->plural($steps),
        ));

        return self::SUCCESS;
    }

    private function resolveCharacter(): ?Character
    {
        $identifier = (string) $this->argument('character');

        if ($identifier === '') {
            return null;
        }

        return is_numeric($identifier)
            ? Character::find((int) $identifier)
            : Character::where('name', $identifier)->first();
    }
}
