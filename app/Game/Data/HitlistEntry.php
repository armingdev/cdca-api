<?php

namespace App\Game\Data;

/**
 * One row of `/myhitlist` or `/crew_hitlist`. Both pages share a layout; the
 * crew list adds the crew-wide hit tally and who posted the entry.
 */
final readonly class HitlistEntry
{
    public function __construct(
        public AttackTarget $target,
        public string $reason = '',
        public ?int $hits = null,
        public ?int $postedById = null,
        public ?string $postedByName = null,
    ) {}
}
