<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Domain;

final readonly class Evidence
{
    /**
     * @param  QueryEvent[]  $queries  Sample queries that triggered the issue (up to 10)
     * @param  int  $queryCount  Total number of matching queries
     * @param  float  $totalTimeMs  Sum of all matching query execution times
     * @param  QueryFingerprint  $fingerprint  The normalized query pattern
     * @param  ExplainResult|null  $explainResult  EXPLAIN output if available
     */
    public function __construct(
        public array $queries,
        public int $queryCount,
        public float $totalTimeMs,
        public QueryFingerprint $fingerprint,
        public ?ExplainResult $explainResult = null,
    ) {}
}
