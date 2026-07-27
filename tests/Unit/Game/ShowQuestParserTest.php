<?php

use App\Game\Enums\QuestObjectiveType;
use App\Game\Exceptions\ParseException;
use App\Game\Parsers\ShowQuestParser;

it('parses a kill-chain quest: header, giver, steps, conditions and exp rewards', function () {
    $quest = new ShowQuestParser()->parse(gameFixture('quest/show_quest_kill_steps.html'));

    expect($quest->gameQuestId)->toBe(947)
        ->and($quest->name)->toBe('Scouring the Forest')
        ->and($quest->requiredLevel)->toBe(67)
        ->and($quest->prerequisite)->toBeNull()
        ->and($quest->giver())->toBe('Hilliam')
        ->and(count($quest->steps))->toBe(9);

    // The intro step has no conditions and no exp — just the NPC reply.
    $intro = $quest->steps[0];

    expect($intro->npc)->toBe('Hilliam')
        ->and($intro->conditions)->toBe([])
        ->and($intro->expReward)->toBeNull()
        ->and($intro->reply)->toContain('Sickly Aequora');

    $second = $quest->steps[1];

    expect($second->conditions[0]->type)->toBe(QuestObjectiveType::Kill)
        ->and($second->conditions[0]->target)->toBe('Sickly Aequora')
        ->and($second->conditions[0]->amount)->toBe(50)
        ->and($second->expReward)->toBe(1_000_000);

    // Step 6 is the one collect condition in the chain.
    expect($quest->steps[5]->conditions[0]->type)->toBe(QuestObjectiveType::Collect)
        ->and($quest->steps[5]->conditions[0]->target)->toBe('Kix Tree Bark')
        ->and($quest->totalExp())->toBe(8_000_000);
});

it('parses multi-condition collect steps and item rewards', function () {
    $quest = new ShowQuestParser()->parse(gameFixture('quest/show_quest_collect_steps.html'));

    expect($quest->gameQuestId)->toBe(1449)
        ->and($quest->name)->toBe('Primal Elemental Rune')
        ->and($quest->requiredLevel)->toBe(75)
        ->and($quest->giver())->toBe('Rune Master of Resplendency')
        ->and(count($quest->steps))->toBe(4);

    // Step 2 requires all five elemental crystals at once.
    $crystals = $quest->steps[1]->conditions;

    expect(count($crystals))->toBe(5)
        ->and(collect($crystals)->every(fn ($c) => $c->type === QuestObjectiveType::Collect && $c->amount === 1))->toBeTrue()
        ->and($crystals[0]->target)->toBe('Holy Elemental Crystal');

    // The final step grants the rune item alongside exp.
    $final = $quest->steps[3];

    expect($final->expReward)->toBe(1_000_000)
        ->and(count($final->itemRewards))->toBe(1)
        ->and($final->itemRewards[0]->name)->toBe('Primal Elemental Rune')
        ->and($final->itemRewards[0]->amount)->toBe(1);
});

it('returns null for unknown quest ids', function () {
    expect(new ShowQuestParser()->parse(gameFixture('quest/show_quest_not_found.html')))->toBeNull();
});

it('returns null for unreleased placeholder quest ids', function () {
    expect(new ShowQuestParser()->parse('Quest under development'))->toBeNull();
});

it('rejects pages that are not a show_quest page', function () {
    new ShowQuestParser()->parse('<html><body>login please</body></html>');
})->throws(ParseException::class);
