<?php

namespace App\Jobs;

use App\Game\Auth\ChallengeTokenService;
use App\Game\Exceptions\GameException;
use App\Models\Rga;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Claim one RGA's daily challenge tokens when they are due.
 *
 * A background convenience, so it never logs the account in (that would boot
 * the player's browser) and never fails the queue: a dead session is left
 * marked invalid by GameClient, anything else is retried at the next check.
 */
class ClaimChallengeTokensJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable, SerializesModels;

    /** A failed check waits this long before the scheduler picks the RGA again. */
    private const int RETRY_AFTER_MINUTES = 60;

    /** Without characters there is nothing to select; a character sync will add some. */
    private const int NO_CHARACTER_RETRY_HOURS = 6;

    public int $timeout = 60;

    public int $tries = 1;

    public function __construct(public Rga $rga) {}

    public function uniqueId(): string
    {
        return (string) $this->rga->id;
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    public function handle(): void
    {
        $rga = $this->rga->fresh();

        if ($rga === null || ! $rga->auto_claim_tokens || ! $rga->hasSession()) {
            return;
        }

        $service = ChallengeTokenService::forRga($rga);

        if ($service === null) {
            $rga->update(['challenge_claim_at' => now()->addHours(self::NO_CHARACTER_RETRY_HOURS)]);

            return;
        }

        try {
            $status = $service->status();

            if (! $status->claimable()) {
                return;
            }

            $result = $service->claim();

            Log::info('Challenge tokens claim', [
                'rga_id' => $rga->id,
                'claimed' => $result->claimed,
                'message' => $result->message,
            ]);
        } catch (GameException $exception) {
            Log::warning('Challenge tokens check failed', ['rga_id' => $rga->id, 'error' => $exception->getMessage()]);

            $rga->update(['challenge_claim_at' => now()->addMinutes(self::RETRY_AFTER_MINUTES)]);
        }
    }
}
