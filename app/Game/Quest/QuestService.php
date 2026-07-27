<?php

namespace App\Game\Quest;

use App\Game\Data\AvailableQuest;
use App\Game\Data\QuestHelperToggle;
use App\Game\Data\QuestStepPage;
use App\Game\Http\GameClient;
use App\Game\Parsers\MobTalkParser;
use App\Game\Parsers\NpcPopupParser;
use App\Game\Parsers\WorldQuestHelperParser;
use App\Models\Character;

/**
 * The HTTP + parse layer for quests: NPC popup (available quests), mob_talk
 * step views / finishes, and the quest-helper tracker + "find my target"
 * toggle. Stateless beyond the client — the QuestRunner owns the
 * walk-and-advance logic.
 */
class QuestService
{
    public function __construct(
        private readonly GameClient $client,
        private readonly NpcPopupParser $popupParser,
        private readonly MobTalkParser $stepParser,
        private readonly WorldQuestHelperParser $helperParser,
    ) {}

    public static function forCharacter(Character $character): self
    {
        return new self(
            GameClient::forCharacter($character),
            app(NpcPopupParser::class),
            app(MobTalkParser::class),
            app(WorldQuestHelperParser::class),
        );
    }

    /**
     * The NPC quest-giver popup — needs the mob's spawn id + per-render hash
     * from the current room's sighting.
     *
     * @return list<AvailableQuest>
     */
    public function availableQuests(int $spawnId, string $hash): array
    {
        return $this->popupParser->parse(
            $this->client->get('mob.php', ['id' => $spawnId, 'h' => $hash])->body()
        );
    }

    /**
     * View a quest step. Pass $questId only on the first view of a quest (to
     * disambiguate which of the NPC's quests to start); later views omit it.
     */
    public function viewStep(int $npcId, int $stepId, ?int $questId = null): QuestStepPage
    {
        $query = ['id' => $npcId, 'stepid' => $stepId, 'userspawn' => ''];

        if ($questId !== null) {
            $query['questid'] = $questId;
        }

        return $this->stepParser->parse($this->client->get('mob_talk.php', $query)->body());
    }

    /**
     * Complete a step by following its finish link (a full mob_talk.php href).
     */
    public function finishStep(string $finishHref): QuestStepPage
    {
        return $this->stepParser->parse($this->client->get($finishHref)->body());
    }

    /**
     * The "find my target" toggles of every active-quest objective.
     *
     * @return list<QuestHelperToggle>
     */
    public function helperToggles(): array
    {
        return $this->helperParser->parse($this->client->get('world_questHelper.php')->body());
    }

    /**
     * Toggle "find my target" for one objective. While on, every room blob
     * carries the compass (RoomBlob::questHelpDirection).
     */
    public function setQuestHelp(QuestHelperToggle $toggle, bool $on): void
    {
        $this->client->get('quest_help.php', [
            'questid' => $toggle->questId,
            'mobid' => $toggle->mobId,
            'itemname' => $toggle->itemName,
            'stepid' => $toggle->stepId,
            'conditionid' => $toggle->conditionId,
            'state' => $on ? 1 : 0,
            'json' => 1,
        ]);
    }
}
