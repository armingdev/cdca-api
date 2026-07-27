<?php

use App\Game\Enums\QuestObjectiveType;
use App\Game\Quest\QuestMapper;
use App\Models\Quest;
use App\Models\QuestCondition;
use App\Models\QuestStep;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['outwar.http.throttle_min_ms' => 0, 'outwar.http.throttle_max_ms' => 0]);

    Http::fake(function ($request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return Http::response(match ((int) ($query['quest'] ?? 0)) {
            947 => gameFixture('quest/show_quest_kill_steps.html'),
            1449 => gameFixture('quest/show_quest_collect_steps.html'),
            default => gameFixture('quest/show_quest_not_found.html'),
        });
    });
});

it('maps a range of quest ids into quests and steps', function () {
    $summary = QuestMapper::forServer(1)->map(946, 948);

    expect($summary)->toBe(['mapped' => 1, 'missing' => 2, 'failed' => 0]);

    $quest = Quest::where('game_quest_id', 947)->first();

    expect($quest->name)->toBe('Scouring the Forest')
        ->and($quest->required_level)->toBe(67)
        ->and($quest->giver)->toBe('Hilliam')
        ->and($quest->steps_count)->toBe(9)
        ->and($quest->total_exp)->toBe(8_000_000)
        ->and($quest->steps()->count())->toBe(9);

    $kill = $quest->steps[1]->conditions;

    expect($kill)->toHaveCount(1)
        ->and($kill[0]->type)->toBe(QuestObjectiveType::Kill)
        ->and($kill[0]->target)->toBe('Sickly Aequora')
        ->and($kill[0]->amount)->toBe(50)
        ->and($kill[0]->quest_id)->toBe($quest->id)
        ->and($quest->steps[1]->exp_reward)->toBe(1_000_000)
        ->and($quest->conditions()->count())->toBe(8);
});

it('re-mapping a quest replaces its steps and conditions instead of duplicating them', function () {
    QuestMapper::forServer(1)->map(1449, 1449);
    QuestMapper::forServer(1)->map(1449, 1449);

    $quest = Quest::where('game_quest_id', 1449)->first();

    expect(Quest::count())->toBe(1)
        ->and(QuestStep::count())->toBe(4)
        ->and(QuestCondition::count())->toBe(13)
        ->and($quest->steps[3]->item_rewards)->toBe([['name' => 'Primal Elemental Rune', 'amount' => 1]])
        ->and($quest->item_rewards)->toBe([['name' => 'Primal Elemental Rune', 'amount' => 1]]);
});

it('links prerequisites to catalog rows by name after mapping', function () {
    QuestMapper::forServer(1)->map(947, 947);

    Quest::factory()->create(['name' => 'Needs Scouring', 'prerequisite' => 'Scouring the Forest']);

    QuestMapper::forServer(1)->map(947, 947);

    $dependent = Quest::where('name', 'Needs Scouring')->first();

    expect($dependent->prerequisiteQuest->game_quest_id)->toBe(947);
});

it('drives the crawl through the outwar:quests:map command', function () {
    $this->artisan('outwar:quests:map', ['--from' => 947, '--to' => 949])
        ->assertSuccessful()
        ->expectsOutputToContain('Scouring the Forest')
        ->expectsOutputToContain('1 quests mapped, 2 ids unused');
});
