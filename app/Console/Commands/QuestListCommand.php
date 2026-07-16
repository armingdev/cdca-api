<?php

namespace App\Console\Commands;

use App\Models\QuestList;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('outwar:questlist
    {action=show : create | add | remove | delete | show}
    {name? : Quest list name (required for every action except a bare show)}
    {--quest= : (add) Quest id}
    {--npc= : (add) Exact quest-giver mob name}
    {--label= : (add) Human-readable quest name}
    {--position= : (remove) Item position to remove}')]
#[Description('Manage quest lists: create, add quests, remove, delete, or show')]
class QuestListCommand extends Command
{
    public function handle(): int
    {
        return match ($this->argument('action')) {
            'create' => $this->create(),
            'add' => $this->add(),
            'remove' => $this->remove(),
            'delete' => $this->delete(),
            'show' => $this->show(),
            default => $this->invalidAction(),
        };
    }

    private function create(): int
    {
        $name = $this->argument('name');

        if ($name === null) {
            $this->error('Pass a list name: outwar:questlist create "Armins List".');

            return self::FAILURE;
        }

        if (QuestList::where('name', $name)->exists()) {
            $this->error("Quest list '{$name}' already exists.");

            return self::FAILURE;
        }

        $list = QuestList::create(['name' => $name]);
        $this->info("Created quest list '{$list->name}' (#{$list->id}).");

        return self::SUCCESS;
    }

    private function add(): int
    {
        $list = $this->resolveList();

        if ($list === null) {
            return self::FAILURE;
        }

        if ($this->option('quest') === null || $this->option('npc') === null) {
            $this->error('Add needs --quest={id} and --npc="Giver Name".');

            return self::FAILURE;
        }

        $item = $list->addQuest(
            questId: (int) $this->option('quest'),
            npcName: (string) $this->option('npc'),
            label: $this->option('label'),
        );

        $this->info("Added {$item->displayName()} at position {$item->position} of '{$list->name}'.");

        return self::SUCCESS;
    }

    private function remove(): int
    {
        $list = $this->resolveList();

        if ($list === null) {
            return self::FAILURE;
        }

        if ($this->option('position') === null) {
            $this->error('Remove needs --position={n}.');

            return self::FAILURE;
        }

        if (! $list->removePosition((int) $this->option('position'))) {
            $this->error("No item at position {$this->option('position')}.");

            return self::FAILURE;
        }

        $this->info("Removed position {$this->option('position')} from '{$list->name}'.");

        return self::SUCCESS;
    }

    private function delete(): int
    {
        $list = $this->resolveList();

        if ($list === null) {
            return self::FAILURE;
        }

        $list->delete();
        $this->info("Deleted quest list '{$list->name}'.");

        return self::SUCCESS;
    }

    private function show(): int
    {
        if ($this->argument('name') === null) {
            $this->table(
                ['ID', 'Name', 'Quests'],
                QuestList::withCount('items')->get()->map(fn (QuestList $list) => [
                    $list->id, $list->name, $list->items_count,
                ]),
            );

            return self::SUCCESS;
        }

        $list = $this->resolveList();

        if ($list === null) {
            return self::FAILURE;
        }

        $this->info("Quest list '{$list->name}':");
        $this->table(
            ['#', 'Quest', 'Quest ID', 'Giver'],
            $list->items->map(fn ($item) => [$item->position, $item->displayName(), $item->quest_id, $item->npc_name]),
        );

        return self::SUCCESS;
    }

    private function resolveList(): ?QuestList
    {
        $name = $this->argument('name');

        if ($name === null) {
            $this->error('Pass the quest list name.');

            return null;
        }

        $list = QuestList::with('items')->where('name', $name)->first();

        if ($list === null) {
            $this->error("Quest list '{$name}' not found.");
        }

        return $list;
    }

    private function invalidAction(): int
    {
        $this->error("Unknown action '{$this->argument('action')}'. Use create, add, remove, delete, or show.");

        return self::FAILURE;
    }
}
