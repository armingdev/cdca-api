<?php

namespace App\Console\Commands;

use App\Game\Auth\LoginService;
use App\Game\Data\MobSighting;
use App\Game\Http\GameClient;
use App\Game\World\Navigator;
use App\Models\Character;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Captures raw quest HTML through the game client so we can build the
 * mob_talk / questHelper parsers against real fixtures (the project guardrail:
 * every HTML parser gets a fixture test). Saves under storage/app/captures/quest;
 * sanitize player-identifying strings before promoting a capture to a
 * committed fixture in docs/game-api/samples + tests/Fixtures.
 */
#[Signature('outwar:quest-capture {character : Character id or name}
    {--npc-name= : Capture the NPC popup (available quests) for this mob in the CURRENT room}
    {--npc= : NPC mob id for a mob_talk step capture}
    {--step= : stepid to capture}
    {--questid= : questid (pass when starting a quest)}
    {--finish : also capture the finish=1 variant of the step}
    {--helper : capture world_questHelper.php (all active quests)}
    {--label= : filename label for the saved capture(s)}')]
#[Description('Capture raw quest HTML (NPC popup, mob_talk steps, questHelper) for building parsers')]
class QuestCaptureCommand extends Command
{
    private const string DIR = 'captures/quest';

    public function handle(LoginService $loginService): int
    {
        $character = $this->resolveCharacter($this->argument('character'));

        if ($character === null) {
            $this->error('Character not found.');

            return self::FAILURE;
        }

        if (! $character->rga->hasSession()) {
            $this->line('No session yet — logging in first…');
            $loginService->login($character->rga);
        }

        $client = GameClient::forCharacter($character);
        $label = $this->option('label') ?? 'capture';
        $captured = 0;

        if ($this->option('helper')) {
            $this->save("questhelper_{$label}.html", $client->get('world_questHelper.php')->body());
            $captured++;
        }

        if ($this->option('npc-name') !== null) {
            $sighting = $this->findNpcInRoom($character, $this->option('npc-name'));

            if ($sighting === null) {
                $this->error("No mob named \"{$this->option('npc-name')}\" in the character's current room — move there first.");

                return self::FAILURE;
            }

            $body = $client->get('mob.php', ['id' => $sighting->spawnId, 'h' => $sighting->hash])->body();
            $this->save("npc_{$label}.html", $body);
            $this->line("NPC popup captured (mobId {$sighting->mobId}, spawnId {$sighting->spawnId}).");
            $captured++;
        }

        if ($this->option('step') !== null) {
            $captured += $this->captureStep($client, $label);
        }

        if ($captured === 0) {
            $this->warn('Nothing captured — pass --helper, --npc-name, or --step.');

            return self::FAILURE;
        }

        $this->info("{$captured} capture(s) saved under storage/app/".self::DIR.'.');
        $this->line('Sanitize player-identifying text before promoting any of these to a committed fixture.');

        return self::SUCCESS;
    }

    private function captureStep(GameClient $client, string $label): int
    {
        $npc = $this->option('npc');

        if ($npc === null) {
            $this->error('--step needs --npc={mobId}.');

            return 0;
        }

        $baseQuery = array_filter([
            'id' => $npc,
            'stepid' => $this->option('step'),
            'userspawn' => '',
            'questid' => $this->option('questid'),
        ], fn ($value) => $value !== null);

        $this->save("step_{$label}_view.html", $client->get('mob_talk.php', $baseQuery)->body());
        $count = 1;

        if ($this->option('finish')) {
            $this->save("step_{$label}_finish.html", $client->get('mob_talk.php', [...$baseQuery, 'finish' => 1])->body());
            $count++;
        }

        return $count;
    }

    private function findNpcInRoom(Character $character, string $name): ?MobSighting
    {
        foreach (Navigator::forCharacter($character)->loadCurrentRoom()->mobs as $sighting) {
            if (Str::lower($sighting->name) === Str::lower($name)) {
                return $sighting;
            }
        }

        return null;
    }

    private function save(string $filename, string $body): void
    {
        Storage::disk('local')->put(self::DIR."/{$filename}", $body);
        $this->line(sprintf('  → %s (%s bytes)', $filename, number_format(strlen($body))));
    }

    private function resolveCharacter(string $identifier): ?Character
    {
        return is_numeric($identifier)
            ? Character::find((int) $identifier)
            : Character::where('name', $identifier)->first();
    }
}
