<?php

namespace App\Game\Items;

use App\Game\Data\BackpackContents;
use App\Game\Data\EquipmentSet;
use App\Game\Data\ItemDetail;
use App\Game\Exceptions\GameException;
use App\Game\Http\GameClient;
use App\Game\Parsers\BackpackContentsParser;
use App\Game\Parsers\EquipmentPageParser;
use App\Game\Parsers\ItemRolloverParser;
use App\Models\Character;

/**
 * Backpack and equipment actions for one character. All mutations go through
 * POST ajax/backpack_action.php and answer {"status":"ok"}. Deleting items is
 * gated by the RGA's security question — the stored answer is always sent
 * (the challenge fires intermittently; sending it unconditionally is safe).
 */
class BackpackService
{
    /** The verified backpackcontents.php tabs. */
    public const array TABS = ['regular', 'quest', 'orb', 'potion', 'key', 'utility'];

    public function __construct(
        private readonly Character $character,
        private readonly GameClient $client,
        private readonly BackpackContentsParser $contentsParser,
        private readonly ItemRolloverParser $rolloverParser,
        private readonly EquipmentPageParser $equipmentParser,
    ) {}

    public static function forCharacter(Character $character): self
    {
        return new self(
            $character,
            GameClient::forCharacter($character),
            app(BackpackContentsParser::class),
            app(ItemRolloverParser::class),
            app(EquipmentPageParser::class),
        );
    }

    public function contents(string $tab = 'regular'): BackpackContents
    {
        $response = $this->client->get('ajax/backpackcontents.php', ['tab' => $tab]);

        return $this->contentsParser->parse($response->body());
    }

    /**
     * The character's worn gear — equipped items never appear in the backpack
     * tabs, so this is the only way to see what a slot already holds.
     */
    public function equipped(): EquipmentSet
    {
        $response = $this->client->get('equipment.php');

        return $this->equipmentParser->parse($response->body());
    }

    public function itemDetail(int $iid): ItemDetail
    {
        $response = $this->client->get('item_rollover.php', [
            'id' => $iid,
            'data' => 0,
            'r' => random_int(1, 999999),
            'itemserver' => '',
            'pr' => 0,
            'gems' => 0,
        ]);

        return $this->rolloverParser->parse($response->body());
    }

    /**
     * @param  list<int>  $iids
     */
    public function equip(array $iids): void
    {
        $this->action('equip', $iids);
    }

    /**
     * @param  list<int>  $iids
     */
    public function unequip(array $iids): void
    {
        $this->action('unequip', $iids);
    }

    /**
     * Delete (drop/destroy) items. Requires the RGA's security answer.
     *
     * @param  list<int>  $iids
     */
    public function delete(array $iids, int $qty = 1): void
    {
        $answer = $this->character->rga->security_answer;

        if ($answer === null || $answer === '') {
            throw new GameException(
                "RGA {$this->character->rga->username} has no security answer configured — required to delete items."
            );
        }

        $this->action('delete', $iids, ['answer' => $answer, 'qty' => $qty]);
    }

    /**
     * @param  list<int>  $iids
     * @param  array<string, mixed>  $extra
     */
    private function action(string $action, array $iids, array $extra = []): void
    {
        // PHP's form encoding sends itemids[0]=…&itemids[1]=…; the game's PHP
        // backend parses that identically to the browser's itemids[]=….
        $response = $this->client->post('ajax/backpack_action.php', [
            'action' => $action,
            'itemids' => $iids,
            ...$extra,
        ]);

        $status = $response->json('status');

        if ($status !== 'ok') {
            throw new GameException(
                "backpack_action {$action} failed for items [".implode(',', $iids).']: '.substr($response->body(), 0, 200)
            );
        }
    }
}
