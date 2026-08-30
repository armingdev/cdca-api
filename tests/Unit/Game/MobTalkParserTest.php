<?php

use App\Game\Enums\QuestObjectiveType;
use App\Game\Exceptions\ParseException;
use App\Game\Parsers\MobTalkParser;

function questFixture(string $name): string
{
    return gameFixture("quest/{$name}");
}

it('parses an unmet kill step: objective incomplete and NO finish link', function () {
    $page = new MobTalkParser()->parse(questFixture('mob_talk_kill_incomplete.html'));

    expect($page->npcName)->toBe('Stella')
        ->and($page->questTitle)->toBe('Street Crawler Attack')
        ->and($page->dialog)->toContain('defeat the Street Crawlers')
        ->and($page->canAdvance())->toBeFalse()
        ->and($page->finishLink)->toBeNull()
        ->and($page->objectives)->toHaveCount(1);

    $objective = $page->objectives[0];

    expect($objective->type)->toBe(QuestObjectiveType::Kill)
        ->and($objective->target)->toBe('Street Crawler')
        ->and($objective->current)->toBe(0)
        ->and($objective->required)->toBe(5)
        ->and($objective->complete)->toBeFalse()
        ->and($objective->remaining())->toBe(5)
        ->and($page->unmetObjectives())->toHaveCount(1);
});

it('parses a met kill step: objective complete and finish link PRESENT', function () {
    $page = new MobTalkParser()->parse(questFixture('mob_talk_kill_complete.html'));

    expect($page->canAdvance())->toBeTrue()
        ->and($page->finishLink)->toContain('finish=1')
        ->and($page->npcId)->toBe(59293)
        ->and($page->stepId)->toBe(3378)
        ->and($page->objectives[0]->complete)->toBeTrue()
        ->and($page->objectives[0]->current)->toBe(5)
        ->and($page->unmetObjectives())->toBe([]);
});

it('parses the finish/turn-in reward text', function () {
    $page = new MobTalkParser()->parse(questFixture('mob_talk_step_finish.html'));

    expect($page->expReward)->toBe(300)
        ->and($page->rewards)->toContain('You have received 300 experience!')
        ->and(collect($page->rewards)->contains(fn ($r) => str_contains($r, 'Gem Stone Belt')))->toBeTrue()
        ->and($page->objectives)->toBe([]);
});

it('reflects the verified finish-link contrast between the two captures', function () {
    $incomplete = new MobTalkParser()->parse(questFixture('mob_talk_kill_incomplete.html'));
    $complete = new MobTalkParser()->parse(questFixture('mob_talk_kill_complete.html'));

    // Same step, same objective target — only completion differs.
    expect($incomplete->objectives[0]->target)->toBe($complete->objectives[0]->target)
        ->and($incomplete->canAdvance())->toBeFalse()
        ->and($complete->canAdvance())->toBeTrue();
});

it('classifies a collect objective (no "killed" suffix)', function () {
    $html = '<div class="mob-dialog-container"><div class="quest-objective incomplete">'
        .'<strong>Thief Dagger:</strong> 1/3</div>'
        .'<a href="mob.php?id=59293&h=X">Go Back</a></div>';

    $page = new MobTalkParser()->parse($html);

    expect($page->objectives[0]->type)->toBe(QuestObjectiveType::Collect)
        ->and($page->objectives[0]->target)->toBe('Thief Dagger')
        ->and($page->objectives[0]->required)->toBe(3)
        ->and($page->canAdvance())->toBeFalse();
});

it('throws on a page that is not a mob_talk step', function () {
    new MobTalkParser()->parse('<html><body>Some other page</body></html>');
})->throws(ParseException::class);

it('parses every objective of a multi-objective kill step', function () {
    $page = (new MobTalkParser)->parse(gameFixture('quest/mob_talk_multi_objective_incomplete.html'));

    expect($page->objectives)->toHaveCount(2)
        ->and($page->unmetObjectives())->toHaveCount(2)
        ->and($page->objectives[0]->target)->toBe('Street Crawler')
        ->and($page->objectives[0]->required)->toBe(5)
        ->and($page->objectives[1]->target)->toBe('Alley Rat')
        ->and($page->objectives[1]->required)->toBe(3)
        ->and($page->canAdvance())->toBeFalse();
});

it('leaves the second objective unmet once the first is done', function () {
    $page = (new MobTalkParser)->parse(gameFixture('quest/mob_talk_multi_objective_one_complete.html'));

    expect($page->objectives)->toHaveCount(2)
        ->and($page->unmetObjectives())->toHaveCount(1)
        ->and($page->unmetObjectives()[0]->target)->toBe('Alley Rat');
});

it('reports no unmet objectives when every counter is full', function () {
    $page = (new MobTalkParser)->parse(gameFixture('quest/mob_talk_multi_objective_all_complete.html'));

    expect($page->objectives)->toHaveCount(2)
        ->and($page->unmetObjectives())->toBe([])
        ->and($page->canAdvance())->toBeFalse();
});

it('reads a talk row without an n/m counter as an objective, not a hole', function () {
    // A null in this list used to reach a typed closure and raise a TypeError,
    // which no GameException handler catches — taking the whole run down.
    $page = (new MobTalkParser)->parse(gameFixture('quest/mob_talk_mixed_talk_row.html'));

    expect($page->objectives)->toHaveCount(2)
        ->and($page->objectives[0]->type)->toBe(QuestObjectiveType::Kill)
        ->and($page->objectives[1]->type)->toBe(QuestObjectiveType::Talk)
        ->and($page->objectives[1]->target)->toBe('Speak to the Watch Captain')
        ->and($page->unmetObjectives())->toHaveCount(2);
});

it('picks the onward link belonging to the quest being run', function () {
    $page = (new MobTalkParser)->parse(gameFixture('quest/mob_talk_turn_in_foreign_link.html'));

    expect($page->continueLinks)->toHaveCount(2)
        // First in page order belongs to a different quest; following it would
        // abandon the quest in hand.
        ->and($page->continueLink())->toContain('questid=888')
        ->and($page->continueLinkFor(742))->toContain('stepid=4002')
        ->and($page->continueLinkFor(999))->toBeNull();
});
