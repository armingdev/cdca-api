<?php

use App\Game\Auth\LoginService;
use App\Game\Enums\RunStatus;
use App\Models\BattleEvent;
use App\Models\Character;
use App\Models\Mob;
use App\Models\Rga;
use App\Models\Run;
use App\Models\RunParticipant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
    $this->rga = Rga::factory()->for($this->user)->withSession()->create();
});

function dropped(Character $character, string $item, array $attributes = []): BattleEvent
{
    return BattleEvent::factory()->for($character)->create(['drop_name' => $item, ...$attributes]);
}

describe('fleet-wide drop totals', function () {
    it('adds up each item across all of the user\'s characters and runs', function () {
        [$first, $second] = Character::factory()->for($this->rga)->count(2)->create();
        dropped($first, 'Amdir Potion');
        dropped($second, 'Amdir Potion');
        dropped($second, 'Tincture');
        BattleEvent::factory()->for($first)->create(); // a win that dropped nothing

        $this->getJson('/api/v1/stats/drops')
            ->assertOk()
            ->assertExactJson([
                'drops' => [
                    ['drop_name' => 'Amdir Potion', 'count' => 2],
                    ['drop_name' => 'Tincture', 'count' => 1],
                ],
                'total' => 3,
            ]);
    });

    it('never counts drops of another user\'s characters', function () {
        dropped(Character::factory()->for(Rga::factory()->for(User::factory()))->create(), 'Amdir Potion');

        $this->getJson('/api/v1/stats/drops')
            ->assertOk()
            ->assertExactJson(['drops' => [], 'total' => 0]);
    });

    it('does not reveal another user\'s drops through the character or run filter', function () {
        $theirs = Character::factory()->for(Rga::factory()->for(User::factory()))->create();
        $theirRun = Run::factory()->create();
        dropped($theirs, 'Amdir Potion', ['run_id' => $theirRun->id]);

        $this->getJson("/api/v1/stats/drops?character_id={$theirs->id}")->assertOk()->assertJsonPath('total', 0);
        $this->getJson("/api/v1/stats/drops?run_id={$theirRun->id}")->assertOk()->assertJsonPath('total', 0);
    });

    it('narrows the totals by date range, character, mob and run', function () {
        $character = Character::factory()->for($this->rga)->create();
        $other = Character::factory()->for($this->rga)->create();
        $harvester = Mob::factory()->create(['name' => 'Amdir Harvester']);
        $run = Run::factory()->for($this->user)->create();

        dropped($character, 'Amdir Potion', ['mob_id' => $harvester->id, 'run_id' => $run->id, 'occurred_at' => '2026-09-10 12:00:00']);
        dropped($other, 'Amdir Potion', ['occurred_at' => '2026-09-01 12:00:00']);

        $this->getJson('/api/v1/stats/drops?from=2026-09-05&to=2026-09-15')->assertJsonPath('total', 1);
        $this->getJson("/api/v1/stats/drops?character_id={$other->id}")->assertJsonPath('total', 1);
        $this->getJson("/api/v1/stats/drops?mob_id={$harvester->id}")->assertJsonPath('total', 1);
        $this->getJson("/api/v1/stats/drops?run_id={$run->id}")->assertJsonPath('total', 1);
        $this->getJson('/api/v1/stats/drops')->assertJsonPath('total', 2);
    });

    it('splits each item by the mob that dropped it when grouped by mob', function () {
        $character = Character::factory()->for($this->rga)->create();
        $harvester = Mob::factory()->create(['name' => 'Amdir Harvester']);
        $guard = Mob::factory()->create(['name' => 'Amdir Guard']);
        dropped($character, 'Amdir Potion', ['mob_id' => $harvester->id]);
        dropped($character, 'Amdir Potion', ['mob_id' => $harvester->id]);
        dropped($character, 'Amdir Potion', ['mob_id' => $guard->id]);

        $this->getJson('/api/v1/stats/drops?group_by=mob')
            ->assertOk()
            ->assertExactJson([
                'drops' => [
                    ['drop_name' => 'Amdir Potion', 'mob_id' => $harvester->id, 'mob_name' => 'Amdir Harvester', 'count' => 2],
                    ['drop_name' => 'Amdir Potion', 'mob_id' => $guard->id, 'mob_name' => 'Amdir Guard', 'count' => 1],
                ],
                'total' => 3,
            ]);
    });

    it('returns 422 for a date range that ends before it starts', function () {
        $this->getJson('/api/v1/stats/drops?from=2026-09-10&to=2026-09-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');
    });
});

describe('a run\'s own battles and drops', function () {
    it('tags every battle a run fights with that run', function () {
        config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);
        seedCombatWorld();
        fakeCombatWorld();
        $participant = RunParticipant::factory()
            ->for(Run::factory()->for($this->user)->state([
                'status' => RunStatus::Running,
                'config' => ['mob_names' => ['Kix Harvester'], 'run_count' => 1],
            ]))
            ->for(Character::factory()->for($this->rga))
            ->create();

        makeRunJob($participant)->handle(app(LoginService::class));

        expect(BattleEvent::sole()->run_id)->toBe($participant->run_id);

        $this->getJson("/api/v1/runs/{$participant->run_id}/drops")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('drops.0.drop_name', 'Kix Potion')
            ->assertJsonPath('drops.0.mob_name', 'Kix Harvester');
    });

    it('lists only the battles of this run, not the characters\' other fights', function () {
        $character = Character::factory()->for($this->rga)->create();
        $run = Run::factory()->for($this->user)->create();
        RunParticipant::factory()->for($run)->for($character)->create();
        $mine = dropped($character, 'Tincture', ['run_id' => $run->id]);
        dropped($character, 'Amdir Potion', ['run_id' => Run::factory()->for($this->user)->create()->id]);

        $this->getJson("/api/v1/runs/{$run->id}/battles")
            ->assertOk()
            ->assertJsonPath('data.*.id', [$mine->id]);
    });

    it('falls back to the characters\' battles since the run began for runs recorded before tagging', function () {
        $character = Character::factory()->for($this->rga)->create();
        $run = Run::factory()->for($this->user)->create(['created_at' => '2026-09-01 10:00:00']);
        RunParticipant::factory()->for($run)->for($character)->create();
        dropped($character, 'Before The Run', ['occurred_at' => '2026-08-31 09:00:00']);
        $during = dropped($character, 'During The Run', ['occurred_at' => '2026-09-01 11:00:00']);

        $this->getJson("/api/v1/runs/{$run->id}/battles")
            ->assertOk()
            ->assertJsonPath('data.*.id', [$during->id]);
    });

    it('returns 403 for the drops of another user\'s run', function () {
        $run = Run::factory()->for(User::factory())->create();

        $this->getJson("/api/v1/runs/{$run->id}/drops")->assertForbidden();
    });
});
