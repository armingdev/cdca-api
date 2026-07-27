<?php

use App\Game\Enums\QuestObjectiveType;
use App\Models\Area;
use App\Models\BattleEvent;
use App\Models\Mob;
use App\Models\Quest;
use App\Models\QuestCondition;
use App\Models\QuestItem;
use App\Models\QuestStep;
use App\Models\Room;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());
});

it('lists quests paginated and sorted by name by default', function () {
    Quest::factory()->create(['name' => 'Bravo Quest']);
    Quest::factory()->create(['name' => 'Alpha Quest']);
    Quest::factory()->create(['name' => 'Charlie Quest']);

    $this->getJson('/api/v1/quests')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.name', 'Alpha Quest')
        ->assertJsonPath('meta.total', 3);
});

it('filters by level range and giver, and sorts by exp descending', function () {
    Quest::factory()->create(['name' => 'Low', 'required_level' => 10, 'giver' => 'Stella', 'total_exp' => 100]);
    Quest::factory()->create(['name' => 'Mid', 'required_level' => 25, 'giver' => 'Stella', 'total_exp' => 900]);
    Quest::factory()->create(['name' => 'Mid Other Giver', 'required_level' => 25, 'giver' => 'Hilliam', 'total_exp' => 500]);
    Quest::factory()->create(['name' => 'High', 'required_level' => 80, 'giver' => 'Stella', 'total_exp' => 999]);

    $this->getJson('/api/v1/quests?min_level=20&max_level=30&sort=total_exp&dir=desc')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'Mid');

    $this->getJson('/api/v1/quests?giver=Stella')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

it('searches by name or giver, case-insensitively', function () {
    Quest::factory()->create(['name' => 'Primal Elemental Rune', 'giver' => 'Rune Master of Resplendency']);
    Quest::factory()->create(['name' => 'Street Crawler', 'giver' => 'Stella']);

    $this->getJson('/api/v1/quests?search=elemental')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Primal Elemental Rune');

    $this->getJson('/api/v1/quests?search=STELLA')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Street Crawler');
});

it('rejects unknown sort columns and oversized pages', function () {
    $this->getJson('/api/v1/quests?sort=drop table')->assertUnprocessable();
    $this->getJson('/api/v1/quests?per_page=500')->assertUnprocessable();
});

it('shows the rich detail with giver, kill and collect locations', function () {
    $area = Area::create(['id' => 5, 'name' => 'Astral World']);
    Room::factory()->create(['id' => 10, 'name' => 'Sanctum', 'area_id' => $area->id]);
    Room::factory()->create(['id' => 11, 'name' => 'Crystal Cave', 'area_id' => $area->id]);

    Mob::factory()->create(['name' => 'Rune Master'])->rooms()->attach(10, ['last_seen_at' => now()]);
    $keeper = Mob::factory()->create(['name' => 'Holy Elemental Keeper', 'level' => 60]);
    $keeper->rooms()->attach(11, ['last_seen_at' => now()]);

    QuestItem::factory()->create([
        'name' => 'Holy Elemental Crystal',
        'source_mobs' => ['Holy Elemental Keeper'],
        'target_room_id' => 11,
        'helper_verified_at' => now(),
    ]);
    BattleEvent::factory()->create(['mob_id' => $keeper->id, 'drop_name' => 'Holy Elemental Crystal']);

    $quest = Quest::factory()->create(['name' => 'Primal Elemental Rune', 'giver' => 'Rune Master']);
    $step = QuestStep::factory()->for($quest)->create(['position' => 1, 'npc' => 'Rune Master']);
    QuestCondition::factory()->for($quest)->create([
        'quest_step_id' => $step->id, 'position' => 1,
        'type' => QuestObjectiveType::Kill, 'target' => 'Holy Elemental Keeper', 'amount' => 5,
    ]);
    QuestCondition::factory()->for($quest)->create([
        'quest_step_id' => $step->id, 'position' => 2,
        'type' => QuestObjectiveType::Collect, 'target' => 'Holy Elemental Crystal', 'amount' => 1,
    ]);

    $this->getJson("/api/v1/quests/{$quest->id}")
        ->assertOk()
        ->assertJsonPath('data.name', 'Primal Elemental Rune')
        ->assertJsonPath('data.giver_locations.0.room_id', 10)
        ->assertJsonPath('data.giver_locations.0.area', 'Astral World')
        ->assertJsonPath('data.steps.0.conditions.0.mob.name', 'Holy Elemental Keeper')
        ->assertJsonPath('data.steps.0.conditions.0.mob.locations.0.room_id', 11)
        ->assertJsonPath('data.steps.0.conditions.1.sources.target_room.room_id', 11)
        ->assertJsonPath('data.steps.0.conditions.1.sources.mobs.0.name', 'Holy Elemental Keeper')
        ->assertJsonPath('data.steps.0.conditions.1.sources.mobs.0.confirmed_drop', true);
});

it('returns empty sources honestly when nothing is known about an item', function () {
    $quest = Quest::factory()->create(['giver' => 'Nobody Mapped']);
    $step = QuestStep::factory()->for($quest)->create();
    QuestCondition::factory()->for($quest)->create([
        'quest_step_id' => $step->id,
        'type' => QuestObjectiveType::Collect, 'target' => 'Mystery Orb', 'amount' => 1,
    ]);

    $this->getJson("/api/v1/quests/{$quest->id}")
        ->assertOk()
        ->assertJsonPath('data.giver_locations', [])
        ->assertJsonPath('data.steps.0.conditions.0.sources.target_room', null)
        ->assertJsonPath('data.steps.0.conditions.0.sources.mobs', []);
});

it('embeds the prerequisite quest link in the detail', function () {
    $parent = Quest::factory()->create(['name' => 'Parent Quest']);
    $quest = Quest::factory()->create([
        'prerequisite' => 'Parent Quest',
        'prerequisite_quest_id' => $parent->id,
    ]);

    $this->getJson("/api/v1/quests/{$quest->id}")
        ->assertOk()
        ->assertJsonPath('data.prerequisite_quest.name', 'Parent Quest')
        ->assertJsonPath('data.prerequisite_quest.id', $parent->id);
});

it('lists distinct givers with quest counts', function () {
    Quest::factory()->count(2)->create(['giver' => 'Stella']);
    Quest::factory()->create(['giver' => 'Hilliam']);

    $this->getJson('/api/v1/quests/givers')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.giver', 'Hilliam')
        ->assertJsonPath('data.0.quests_count', 1)
        ->assertJsonPath('data.1.quests_count', 2);
});
