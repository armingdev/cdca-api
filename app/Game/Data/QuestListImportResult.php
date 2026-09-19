<?php

namespace App\Game\Data;

use App\Models\QuestList;

/**
 * What an import produced: the list, plus the lines that could not be placed
 * with certainty so the user can fix them by hand instead of wondering why a
 * quest is missing.
 */
final readonly class QuestListImportResult
{
    /**
     * @param  list<string>  $unmatched  lines with no quest of that name or game id in the catalog
     * @param  list<string>  $ambiguous  names more than one catalog quest carries; the lowest-level one was used
     */
    public function __construct(
        public QuestList $questList,
        public array $unmatched,
        public array $ambiguous,
    ) {}
}
