<?php

namespace App\Console\Commands;

use App\Jobs\ClaimChallengeTokensJob;
use App\Models\Rga;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('outwar:challenge-tokens-claim')]
#[Description('Queue a daily challenge-token claim for every RGA that has auto-claim on and is due (scheduled every 30 minutes)')]
class ChallengeTokensClaimCommand extends Command
{
    public function handle(): int
    {
        $queued = 0;

        Rga::query()
            ->where('auto_claim_tokens', true)
            ->where('status', Rga::STATUS_ACTIVE)
            ->whereNotNull('cookies')
            ->where(fn ($query) => $query->whereNull('challenge_claim_at')->orWhere('challenge_claim_at', '<=', now()))
            ->orderBy('id')
            ->each(function (Rga $rga) use (&$queued): void {
                ClaimChallengeTokensJob::dispatch($rga);
                $queued++;
            });

        $this->info("Queued {$queued} challenge-token check(s).");

        return self::SUCCESS;
    }
}
