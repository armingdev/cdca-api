<?php

namespace App\Game\Data;

/**
 * One character in the ajax/trusteeList.php dropdown — either one of the
 * RGA's own characters or a trustee (a character from another RGA shared
 * with this one for limited control).
 */
final readonly class TrusteeListEntry
{
    public function __construct(
        public int $suid,
        public string $name,
        public bool $isTrustee,
    ) {}
}
