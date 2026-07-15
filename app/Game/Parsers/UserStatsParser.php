<?php

namespace App\Game\Parsers;

use App\Game\Data\UserStats;
use App\Game\Exceptions\ParseException;

class UserStatsParser
{
    public function parse(string $body): UserStats
    {
        $data = json_decode($body, true);

        if (! is_array($data) || ! isset($data['rage'], $data['exp'], $data['level'])) {
            throw new ParseException('userstats.php response is not the expected JSON: '.substr($body, 0, 200));
        }

        return new UserStats(
            exp: $this->toInt($data['exp']),
            rage: $this->toInt($data['rage']),
            level: $this->toInt($data['level']),
        );
    }

    /**
     * Values arrive as comma-formatted strings, e.g. "80,906".
     */
    private function toInt(mixed $value): int
    {
        return (int) str_replace(',', '', (string) $value);
    }
}
