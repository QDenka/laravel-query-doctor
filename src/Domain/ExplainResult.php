<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Domain;

final readonly class ExplainResult
{
    /**
     * @param  string  $scanType  Normalized scan type: full_scan, index_scan, range_scan, ref, const
     * @param  string[]  $possibleKeys  Indexes that could be used
     * @param  string|null  $usedKey  Index actually used, null if none
     * @param  int  $estimatedRows  Estimated row count from the optimizer
     * @param  string[]  $extra  Additional info (e.g. "Using filesort", "Using temporary")
     * @param  array<string, mixed>  $raw  Original EXPLAIN output for debugging
     */
    public function __construct(
        public string $scanType,
        public array $possibleKeys,
        public ?string $usedKey,
        public int $estimatedRows,
        public array $extra,
        public array $raw,
    ) {}

    public function isFullScan(): bool
    {
        return $this->scanType === 'full_scan';
    }

    public function usesIndex(): bool
    {
        return $this->usedKey !== null;
    }

    public function hasFilesort(): bool
    {
        return in_array('Using filesort', $this->extra, true);
    }

    public function hasTemporaryTable(): bool
    {
        return in_array('Using temporary', $this->extra, true);
    }
}
