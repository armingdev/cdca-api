<?php

namespace App\Console\Commands;

use App\Game\Quest\QuestMapper;
use App\Models\Quest;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('outwar:quests:map
    {--from=1 : First quest id to fetch}
    {--to=2900 : Last quest id to fetch}
    {--server=1 : Server id to crawl (1 = sigil, 2 = torax — catalogs are identical)}')]
#[Description('Crawl the public show_quest.php catalog by id and store quests with their steps')]
class QuestsMapCommand extends Command
{
    public function handle(): int
    {
        $from = (int) $this->option('from');
        $to = (int) $this->option('to');

        if ($from < 1 || $to < $from) {
            $this->error('Invalid id range.');

            return self::FAILURE;
        }

        $this->info("Mapping quests {$from}–{$to} (no session needed — public endpoint).");

        $summary = QuestMapper::forServer((int) $this->option('server'))->map(
            fromId: $from,
            toId: $to,
            log: fn (string $message) => $this->line($message),
        );

        $this->info(sprintf(
            'Done. %d quests mapped, %d ids unused, %d failed — %d quests in the database.',
            $summary['mapped'],
            $summary['missing'],
            $summary['failed'],
            Quest::count(),
        ));

        return $summary['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
