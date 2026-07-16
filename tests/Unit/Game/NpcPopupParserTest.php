<?php

use App\Game\Parsers\NpcPopupParser;

it('extracts available quests from mob_talk links carrying a questid', function () {
    // Matches the verified link format (quest_flow.json): Stella, id 59293.
    $html = <<<'HTML'
    <div class="available-quests">
      <a href="mob_talk.php?id=59293&stepid=3071&userspawn=&questid=672">Maglious Demise</a>
      <a href="mob_talk.php?id=59293&stepid=3377&userspawn=&questid=742">Street Crawler</a>
      <a href="mob.php?id=59293&h=X">Go Back</a>
    </div>
    HTML;

    $quests = new NpcPopupParser()->parse($html);

    expect($quests)->toHaveCount(2)
        ->and($quests[0]->questId)->toBe(672)
        ->and($quests[0]->firstStepId)->toBe(3071)
        ->and($quests[0]->npcId)->toBe(59293)
        ->and($quests[0]->name)->toBe('Maglious Demise')
        ->and($quests[1]->questId)->toBe(742)
        ->and($quests[1]->name)->toBe('Street Crawler');
});

it('does not crash on the sanitized popup fragment', function () {
    // The live capture came through truncated; assert graceful handling.
    expect(new NpcPopupParser()->parse(gameFixture('quest/mob_npc_popup.html')))->toBeArray();
});

it('returns an empty list when there are no quest links', function () {
    expect(new NpcPopupParser()->parse('<html><body>no quests here</body></html>'))->toBe([]);
});
