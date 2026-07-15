<?php

namespace App\Game\Data;

/**
 * One row of the accounts.php?ac_serverid= character list.
 */
final readonly class AccountCharacter
{
    public function __construct(
        public int $suid,
        public int $serverId,
        public string $name,
        public ?int $level,
        public ?string $crew,
    ) {}
}
