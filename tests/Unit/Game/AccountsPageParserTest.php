<?php

use App\Game\Parsers\AccountsPageParser;

it('parses the captured accounts page fixture', function () {
    $characters = new AccountsPageParser()->parse(gameFixture('accounts_enumeration.html'));

    expect($characters)->toHaveCount(1);

    $character = $characters[0];

    expect($character->suid)->toBe(21980)
        ->and($character->serverId)->toBe(2)
        ->and($character->name)->toBe('LinuXX')
        ->and($character->level)->toBe(19)
        ->and($character->crew)->toBe('LinuXXisl33t');
});

it('parses multiple table rows and tolerates a missing crew', function () {
    $html = <<<'HTML'
    <table>
      <tr>
        <td><font color="#FFFF00"><b>AlphaChar</b></font></td>
        <td><font color="#FFFFFF"><b>85</b></font></td>
        <td><font color="#999999"><b>Team NoA</b></font></td>
        <td><a href="http://sigil.outwar.com/world.php?suid=2403&serverid=1"><b>PLAY!</b></a></td>
      </tr>
      <tr>
        <td><font color="#FFFF00"><b>BetaChar</b></font></td>
        <td><font color="#FFFFFF"><b>7</b></font></td>
        <td><font color="#999999"><b></b></font></td>
        <td><a href="http://sigil.outwar.com/world.php?suid=12976&serverid=1"><b>PLAY!</b></a></td>
      </tr>
    </table>
    HTML;

    $characters = new AccountsPageParser()->parse($html);

    expect($characters)->toHaveCount(2)
        ->and($characters[0]->name)->toBe('AlphaChar')
        ->and($characters[0]->suid)->toBe(2403)
        ->and($characters[0]->serverId)->toBe(1)
        ->and($characters[1]->name)->toBe('BetaChar')
        ->and($characters[1]->level)->toBe(7)
        ->and($characters[1]->crew)->toBeNull();
});

it('returns an empty list for a page without characters', function () {
    expect(new AccountsPageParser()->parse('<html><body>No characters</body></html>'))->toBe([]);
});
