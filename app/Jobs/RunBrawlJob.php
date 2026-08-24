<?php

namespace App\Jobs;

use App\Game\Combat\Targets\BrawlTargetSource;
use App\Game\Combat\Targets\PvpTargetSource;
use App\Game\Engine\PvpRunConfig;
use App\Models\Character;
use App\Models\RunParticipant;

/**
 * Brawl modes. Adds the event-specific pre-flight the list modes do not need:
 * enter the round when asked, and only attack while the window is open.
 *
 * **The in-window attack mechanics are unverified.** The 2026-08-22 capture
 * caught both brawls dormant, so we do not know whether attacks route through
 * the usual somethingelse.php, whether the 60-minute cooldown is suspended
 * (it must be, for 10 hits per opponent in 12 hours), or where the
 * hits-remaining counter lives. Rather than fire a guessed request at a live
 * event, this job reads and enters only, and refuses to attack until a live
 * capture confirms the mechanics. Flip `outwar.brawl.attacks_verified` once
 * it does.
 */
class RunBrawlJob extends RunPvpJob
{
    private string $skipReason = 'Nothing to do this pass.';

    protected function targetSource(
        Character $character,
        RunParticipant $participant,
        PvpRunConfig $config,
    ): ?PvpTargetSource {
        $type = $participant->run->mode->brawlType();
        $source = BrawlTargetSource::forType($character, $type);
        $page = $source->page();

        if ($config->autoEnterBrawl && ! $source->isEntered() && $page->canEnter) {
            $source->enter();
        }

        if (! $source->isEntered()) {
            $this->skipReason = "Not entered in the {$type->label()}"
                .($config->autoEnterBrawl ? ' — entry was refused.' : ' (auto-enter is off).');

            return null;
        }

        if (! config('outwar.brawl.attacks_verified', false)) {
            $this->skipReason = "Entered the {$type->label()}; in-window attack mechanics are not yet "
                .'verified, so no attacks were made. Capture a live brawl, then enable '
                .'outwar.brawl.attacks_verified.';

            return null;
        }

        return $source;
    }

    protected function skipReason(): string
    {
        return $this->skipReason;
    }
}
