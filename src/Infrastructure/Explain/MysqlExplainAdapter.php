<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Infrastructure\Explain;

use Illuminate\Support\Facades\DB;
use QDenka\QueryDoctor\Domain\Contracts\ExplainInterface;
use QDenka\QueryDoctor\Domain\ExplainResult;

final class MysqlExplainAdapter implements ExplainInterface
{
    public function explain(string $sql, array $bindings, string $connection): ?ExplainResult
    {
        // Only EXPLAIN SELECT/INSERT/UPDATE/DELETE/REPLACE — not DDL or SHOW
        if (! $this->isExplainable($sql)) {
            return null;
        }

        try {
            /** @var array<int, object> $rows */
            $rows = DB::connection($connection)->select('EXPLAIN '.$sql, $bindings);

            if ($rows === []) {
                return null;
            }

            return $this->parseExplainOutput($rows);
        } catch (\Throwable) {
            // EXPLAIN can fail for various reasons (permissions, syntax with CTEs, etc.)
            return null;
        }
    }

    public function supports(string $driver): bool
    {
        return $driver === 'mysql' || $driver === 'mariadb';
    }

    /**
     * @param  array<int, object>  $rows
     */
    private function parseExplainOutput(array $rows): ExplainResult
    {
        // MySQL EXPLAIN returns one row per table in the query.
        // We focus on the first (or worst) row for the primary signal.
        $first = $rows[0];
        /** @var array<string, mixed> $raw */
        $raw = ['rows' => array_map(static fn (object $r) => (array) $r, $rows)];

        $type = $this->normalizeType((string) ($first->type ?? 'ALL'));
        $possibleKeys = $this->parseKeyList((string) ($first->possible_keys ?? ''));
        $usedKey = $this->nullIfEmpty((string) ($first->key ?? ''));
        $estimatedRows = (int) ($first->rows ?? 0);
        $extra = $this->parseExtra((string) ($first->Extra ?? ''));

        return new ExplainResult(
            scanType: $type,
            possibleKeys: $possibleKeys,
            usedKey: $usedKey,
            estimatedRows: $estimatedRows,
            extra: $extra,
            raw: $raw,
        );
    }

    /**
     * Normalize MySQL EXPLAIN type to our standard scan types.
     *
     * MySQL types (best to worst): system, const, eq_ref, ref, range,
     * index, ALL. We map them to simpler categories.
     */
    private function normalizeType(string $mysqlType): string
    {
        return match (strtolower($mysqlType)) {
            'all' => 'full_scan',
            'index' => 'index_scan',
            'range' => 'range_scan',
            'ref', 'eq_ref', 'ref_or_null' => 'ref',
            'const', 'system' => 'const',
            'fulltext' => 'fulltext',
            default => strtolower($mysqlType),
        };
    }

    /**
     * @return string[]
     */
    private function parseKeyList(string $keys): array
    {
        if ($keys === '' || $keys === 'NULL') {
            return [];
        }

        return array_map('trim', explode(',', $keys));
    }

    private function nullIfEmpty(string $value): ?string
    {
        if ($value === '' || $value === 'NULL') {
            return null;
        }

        return $value;
    }

    /**
     * Parse MySQL's Extra field into individual items.
     *
     * @return string[]
     */
    private function parseExtra(string $extra): array
    {
        if ($extra === '' || $extra === 'NULL') {
            return [];
        }

        // MySQL Extra field contains semicolon-separated or comma-separated items
        // Common values: "Using where", "Using filesort", "Using temporary",
        // "Using index", "Using index condition", "Using join buffer"
        return array_map('trim', preg_split('/[;,]/', $extra) ?: [$extra]);
    }

    private function isExplainable(string $sql): bool
    {
        $trimmed = ltrim($sql);

        // EXPLAIN only works on DML statements
        return preg_match('/^(select|insert|update|delete|replace)\b/i', $trimmed) === 1;
    }
}
