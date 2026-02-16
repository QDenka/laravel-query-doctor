<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Domain;

/**
 * Masks PII in query bindings before storage.
 *
 * Three strategies:
 * 1. Column-based: If the SQL references a sensitive column (e.g. password, token),
 *    the corresponding positional binding is replaced with [MASKED].
 * 2. Pattern-based: If a binding value matches a known PII pattern (email, phone),
 *    it's replaced with [MASKED] regardless of column.
 * 3. Hash-only: Storage layer stores bindings_hash (SHA-256) instead of raw values,
 *    handled separately in SqliteStore.
 */
final class BindingMasker
{
    public const MASKED = '[MASKED]';

    /** @var array<int, string> Lowercase column names to mask */
    private readonly array $sensitiveColumns;

    /** @var array<int, string> Regex patterns for PII values */
    private readonly array $valuePatterns;

    /**
     * @param  string[]  $columns  Column names whose bindings should be masked
     * @param  string[]  $valuePatterns  Regex patterns for PII value detection
     */
    public function __construct(
        array $columns = [],
        array $valuePatterns = [],
    ) {
        $this->sensitiveColumns = array_map('strtolower', array_values($columns));
        $this->valuePatterns = array_values($valuePatterns);
    }

    /**
     * Mask sensitive bindings in a query.
     *
     * @param  string  $sql  SQL with ? placeholders
     * @param  array<int, mixed>  $bindings  Positional bindings
     * @return array<int, mixed> Bindings with sensitive values replaced by [MASKED]
     */
    public function mask(string $sql, array $bindings): array
    {
        if ($bindings === []) {
            return $bindings;
        }

        $masked = $bindings;

        // Layer 1: Column-based masking.
        // Extract column names that appear before = ? or LIKE ? or IN (?) placeholders,
        // then mask the corresponding positional bindings.
        if ($this->sensitiveColumns !== []) {
            $masked = $this->maskByColumns($sql, $masked);
        }

        // Layer 2: Pattern-based masking.
        // Check remaining unmasked string bindings against PII regex patterns.
        if ($this->valuePatterns !== []) {
            $masked = $this->maskByPatterns($masked);
        }

        return $masked;
    }

    /**
     * Extract WHERE clause column-to-placeholder mappings and mask sensitive ones.
     *
     * Handles: `column = ?`, `column != ?`, `column > ?`, `column < ?`,
     * `column >= ?`, `column <= ?`, `column <> ?`, `column LIKE ?`,
     * `column IN (?, ?, ...)`, `column BETWEEN ? AND ?`, `column IS ?`.
     *
     * @param  array<int, mixed>  $bindings
     * @return array<int, mixed>
     */
    private function maskByColumns(string $sql, array $bindings): array
    {
        // Find the positional index of each ? placeholder in the SQL
        $placeholderPositions = $this->findPlaceholderPositions($sql);

        // Match patterns like: column_name = ?, column_name LIKE ?, column_name IN (?, ?)
        // The regex captures the column name and the operator region containing ?
        $sensitivePositions = $this->findSensitivePositions($sql, $placeholderPositions);

        foreach ($sensitivePositions as $index) {
            if (isset($bindings[$index])) {
                $bindings[$index] = self::MASKED;
            }
        }

        return $bindings;
    }

    /**
     * Find byte positions of all ? placeholders in SQL, ignoring those inside string literals.
     *
     * @return array<int, int> Map of placeholder index (0-based) to byte position in SQL
     */
    private function findPlaceholderPositions(string $sql): array
    {
        $positions = [];
        $len = strlen($sql);
        $inSingleQuote = false;
        $inDoubleQuote = false;

        for ($i = 0; $i < $len; $i++) {
            $char = $sql[$i];

            if ($char === "'" && ! $inDoubleQuote) {
                // Handle escaped quotes \'
                if ($i > 0 && $sql[$i - 1] === '\\') {
                    continue;
                }
                $inSingleQuote = ! $inSingleQuote;
            } elseif ($char === '"' && ! $inSingleQuote) {
                if ($i > 0 && $sql[$i - 1] === '\\') {
                    continue;
                }
                $inDoubleQuote = ! $inDoubleQuote;
            } elseif ($char === '?' && ! $inSingleQuote && ! $inDoubleQuote) {
                $positions[count($positions)] = $i;
            }
        }

        return $positions;
    }

    /**
     * Determine which placeholder indices correspond to sensitive columns.
     *
     * @param  array<int, int>  $placeholderPositions  Map of index to byte position
     * @return int[] Indices of bindings that should be masked
     */
    private function findSensitivePositions(string $sql, array $placeholderPositions): array
    {
        $sensitive = [];

        // Match column references before comparison operators:
        // Handles `table`.`column`, `column`, table.column, column
        // followed by = ? / != ? / <> ? / > ? / < ? / >= ? / <= ? / LIKE ? / IS ?
        $comparisonPattern = '/(?:`?(\w+)`?\.)?`?(\w+)`?\s*(?:!=|<>|>=|<=|=|>|<|(?:NOT\s+)?LIKE|IS(?:\s+NOT)?)\s*\?/i';

        if (preg_match_all($comparisonPattern, $sql, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $i => $match) {
                $column = strtolower($matches[2][$i][0]);
                if (in_array($column, $this->sensitiveColumns, true)) {
                    // Find which placeholder index this ? belongs to
                    $questionMarkPos = strpos($match[0], '?');
                    if ($questionMarkPos !== false) {
                        $absolutePos = $match[1] + $questionMarkPos;
                        $placeholderIndex = array_search($absolutePos, $placeholderPositions, true);
                        if ($placeholderIndex !== false) {
                            $sensitive[] = $placeholderIndex;
                        }
                    }
                }
            }
        }

        // Handle IN (?, ?, ...) — all placeholders inside belong to the same column
        $inPattern = '/(?:`?(\w+)`?\.)?`?(\w+)`?\s+(?:NOT\s+)?IN\s*\(([^)]+)\)/i';

        if (preg_match_all($inPattern, $sql, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $i => $match) {
                $column = strtolower($matches[2][$i][0]);
                if (in_array($column, $this->sensitiveColumns, true)) {
                    // Find all ? inside the IN (...) group
                    $inContent = $matches[3][$i][0];
                    $inStart = $matches[3][$i][1];

                    $offset = 0;
                    while (($pos = strpos($inContent, '?', $offset)) !== false) {
                        $absolutePos = $inStart + $pos;
                        $placeholderIndex = array_search($absolutePos, $placeholderPositions, true);
                        if ($placeholderIndex !== false) {
                            $sensitive[] = $placeholderIndex;
                        }
                        $offset = $pos + 1;
                    }
                }
            }
        }

        // Handle BETWEEN ? AND ?
        $betweenPattern = '/(?:`?(\w+)`?\.)?`?(\w+)`?\s+(?:NOT\s+)?BETWEEN\s+\?\s+AND\s+\?/i';

        if (preg_match_all($betweenPattern, $sql, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $i => $match) {
                $column = strtolower($matches[2][$i][0]);
                if (in_array($column, $this->sensitiveColumns, true)) {
                    $matchStr = $match[0];
                    $matchStart = $match[1];

                    // Find both ? in "BETWEEN ? AND ?"
                    $offset = 0;
                    while (($pos = strpos($matchStr, '?', $offset)) !== false) {
                        $absolutePos = $matchStart + $pos;
                        $placeholderIndex = array_search($absolutePos, $placeholderPositions, true);
                        if ($placeholderIndex !== false) {
                            $sensitive[] = $placeholderIndex;
                        }
                        $offset = $pos + 1;
                    }
                }
            }
        }

        return array_unique($sensitive);
    }

    /**
     * Mask string bindings that match PII value patterns (email, phone, SSN, etc.).
     *
     * @param  array<int, mixed>  $bindings
     * @return array<int, mixed>
     */
    private function maskByPatterns(array $bindings): array
    {
        foreach ($bindings as $index => $value) {
            if ($value === self::MASKED) {
                continue;
            }

            if (! is_string($value)) {
                continue;
            }

            foreach ($this->valuePatterns as $pattern) {
                if (preg_match($pattern, $value) === 1) {
                    $bindings[$index] = self::MASKED;
                    break;
                }
            }
        }

        return $bindings;
    }
}
