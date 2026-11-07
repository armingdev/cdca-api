<?php

namespace App\Game\Parsers;

use App\Game\Data\ChallengeClaimResult;
use App\Game\Data\ChallengeTokenStatus;
use App\Game\Exceptions\ParseException;

/**
 * The two /challenge AJAX answers. Both are JSON served as text/html, with
 * the counts as strings: {"result":true,"tokens":"30","p_tokens":"5","timeleft":-9581808}
 * and {"result":false,"message":"You have reached your max of 30 challenge tokens!"}.
 */
class ChallengeTokenParser
{
    public function parseStatus(string $body): ChallengeTokenStatus
    {
        $data = json_decode($body, true);

        if (! is_array($data) || ! isset($data['tokens'], $data['timeleft']) || ! is_numeric($data['tokens']) || ! is_numeric($data['timeleft'])) {
            throw ParseException::unexpected('challenge_status.php is not the expected JSON', $body);
        }

        return new ChallengeTokenStatus(
            tokens: (int) $data['tokens'],
            purchasedTokens: is_numeric($data['p_tokens'] ?? null) ? (int) $data['p_tokens'] : 0,
            secondsUntilClaim: (int) $data['timeleft'],
        );
    }

    public function parseClaim(string $body): ChallengeClaimResult
    {
        $data = json_decode($body, true);

        if (! is_array($data) || ! array_key_exists('result', $data)) {
            throw ParseException::unexpected('challenge_claim.php is not the expected JSON', $body);
        }

        $message = is_string($data['message'] ?? null) ? trim($data['message']) : '';

        return new ChallengeClaimResult(
            claimed: $data['result'] === true,
            message: $message !== '' ? $message : ($data['result'] === true ? 'Challenge tokens claimed.' : 'The game refused the claim.'),
        );
    }
}
