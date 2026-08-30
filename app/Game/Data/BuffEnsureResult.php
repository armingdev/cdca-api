<?php

namespace App\Game\Data;

use Carbon\CarbonInterface;

/**
 * What one "keep the selected buffs up" pass actually did. The per-skill
 * breakdown is the point: "cast 5 skill(s)" is exactly the report that made
 * a partial cast invisible, so every skill that did not go off carries the
 * reason it did not.
 */
final readonly class BuffEnsureResult
{
    public const string REASON_ACTIVE = 'active';

    public const string REASON_COOLDOWN = 'cooldown';

    public const string REASON_UNTRAINED = 'untrained';

    public const string REASON_RAGE = 'rage';

    public const string REASON_REFUSED = 'refused';

    /**
     * @param  list<array{skill_id: int, name: string}>  $cast
     * @param  list<array{skill_id: int, name: string, reason: string}>  $skipped
     * @param  list<array{skill_id: int, name: string, reason: string}>  $failed
     * @param  bool  $synced  whether this pass talked to the game at all
     */
    public function __construct(
        public array $cast = [],
        public array $skipped = [],
        public array $failed = [],
        public bool $circumspectActive = false,
        public ?CarbonInterface $circumspectExpiresAt = null,
        public bool $synced = false,
    ) {}

    /**
     * The no-op result of a pass that found every selected buff healthy.
     */
    public static function upToDate(bool $circumspectActive = false, ?CarbonInterface $circumspectExpiresAt = null): self
    {
        return new self(circumspectActive: $circumspectActive, circumspectExpiresAt: $circumspectExpiresAt);
    }

    public function castCount(): int
    {
        return count($this->cast);
    }

    /**
     * A one-line summary for the participant's live activity column.
     */
    public function summaryLine(): string
    {
        $parts = ['cast '.count($this->cast)];

        if ($this->skipped !== []) {
            $parts[] = count($this->skipped).' skipped';
        }

        if ($this->failed !== []) {
            $parts[] = count($this->failed).' failed';
        }

        return 'Buffs: '.implode(', ', $parts).'.';
    }
}
