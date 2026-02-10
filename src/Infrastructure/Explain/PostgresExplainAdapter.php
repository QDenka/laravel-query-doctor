<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Infrastructure\Explain;

use Illuminate\Support\Facades\DB;
use QDenka\QueryDoctor\Domain\Contracts\ExplainInterface;
use QDenka\QueryDoctor\Domain\ExplainResult;

final class PostgresExplainAdapter implements ExplainInterface
{
    public function explain(string $sql, array $bindings, string $connection): ?ExplainResult
    {
        if (! $this->isExplainable($sql)) {
            return null;
        }

        try {
            // Use JSON format for structured output
            /** @var array<int, object> $rows */
            $rows = DB::connection($connection)->select('EXPLAIN (FORMAT JSON) '.$sql, $bindings);

            if ($rows === []) {
                return null;
            }

            // Postgres EXPLAIN (FORMAT JSON) returns a single row with a
            // "QUERY PLAN" column containing a JSON array
            $firstRow = (array) $rows[0];
            $jsonPlan = $firstRow['QUERY PLAN'] ?? $firstRow['query plan'] ?? null;

            if ($jsonPlan === null) {
                return null;
            }

            $plan = is_string($jsonPlan)
                ? json_decode($jsonPlan, true)
                : $jsonPlan;

            if (! is_array($plan) || $plan === []) {
                return null;
            }

            return $this->parsePlan($plan);
        } catch (\Throwable) {
            return null;
        }
    }

    public function supports(string $driver): bool
    {
        return $driver === 'pgsql';
    }

    /**
     * @param  array<int|string, mixed>  $plan
     */
    private function parsePlan(array $plan): ExplainResult
    {
        // Postgres EXPLAIN JSON structure: [{"Plan": {...}}]
        /** @var array<string, mixed> $node */
        $node = $plan[0]['Plan'] ?? $plan;
        $raw = $plan;

        $nodeType = (string) ($node['Node Type'] ?? '');
        $scanType = $this->normalizeNodeType($nodeType);
        $estimatedRows = (int) ($node['Plan Rows'] ?? 0);

        // Extract index info
        $indexName = $node['Index Name'] ?? null;
        $possibleKeys = $indexName !== null ? [(string) $indexName] : [];
        $usedKey = $indexName !== null ? (string) $indexName : null;

        // Collect extra info from the plan
        $extra = $this->collectExtra($node);

        return new ExplainResult(
            scanType: $scanType,
            possibleKeys: $possibleKeys,
            usedKey: $usedKey,
            estimatedRows: $estimatedRows,
            extra: $extra,
            raw: $raw,
        );
    }

    /**
     * Normalize Postgres node types to our standard scan types.
     *
     * Postgres types: Seq Scan, Index Scan, Index Only Scan, Bitmap Heap Scan,
     * Bitmap Index Scan, Nested Loop, Hash Join, Merge Join, Sort, etc.
     */
    private function normalizeNodeType(string $nodeType): string
    {
        return match (strtolower($nodeType)) {
            'seq scan' => 'full_scan',
            'index scan', 'index only scan' => 'index_scan',
            'bitmap heap scan', 'bitmap index scan' => 'range_scan',
            default => strtolower(str_replace(' ', '_', $nodeType)),
        };
    }

    /**
     * Collect noteworthy flags from the plan node.
     *
     * @param  array<string, mixed>  $node
     * @return string[]
     */
    private function collectExtra(array $node): array
    {
        $extra = [];

        // Check for sort operations (equivalent to MySQL's "Using filesort")
        if (isset($node['Sort Key'])) {
            $extra[] = 'Using filesort';
        }

        // Check filter condition (means rows scanned but filtered out)
        if (isset($node['Filter'])) {
            $extra[] = 'Using where';
        }

        // Check for hash or merge operations (can indicate temp tables)
        $nodeType = strtolower((string) ($node['Node Type'] ?? ''));
        if (str_contains($nodeType, 'hash') || str_contains($nodeType, 'materialize')) {
            $extra[] = 'Using temporary';
        }

        // Recursively check child plans for sort/temp indicators
        if (isset($node['Plans']) && is_array($node['Plans'])) {
            foreach ($node['Plans'] as $child) {
                if (is_array($child)) {
                    $childType = strtolower((string) ($child['Node Type'] ?? ''));
                    if ($childType === 'sort') {
                        $extra[] = 'Using filesort';
                    }
                    if (str_contains($childType, 'materialize') || str_contains($childType, 'hash')) {
                        $extra[] = 'Using temporary';
                    }
                }
            }
        }

        return array_values(array_unique($extra));
    }

    private function isExplainable(string $sql): bool
    {
        $trimmed = ltrim($sql);

        return preg_match('/^(select|insert|update|delete)\b/i', $trimmed) === 1;
    }
}
