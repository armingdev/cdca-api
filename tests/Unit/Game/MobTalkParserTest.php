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
