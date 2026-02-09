<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Domain\Analyzer;

use QDenka\QueryDoctor\Domain\Contracts\AnalyzerInterface;
use QDenka\QueryDoctor\Domain\Enums\IssueType;
use QDenka\QueryDoctor\Domain\Enums\Severity;
use QDenka\QueryDoctor\Domain\Evidence;
use QDenka\QueryDoctor\Domain\Issue;
use QDenka\QueryDoctor\Domain\QueryEvent;
use QDenka\QueryDoctor\Domain\QueryFingerprint;
use QDenka\QueryDoctor\Domain\Recommendation;
use QDenka\QueryDoctor\Domain\SourceContext;

final class MissingIndexAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly int $minOccurrences = 5,
        private readonly float $minAvgMs = 50.0,
    ) {}

    /**
     * @param  QueryEvent[]  $events
     * @return Issue[]
     */
    public function analyze(array $events): array
    {
        // Group by fingerprint
        /** @var array<string, array{fingerprint: QueryFingerprint, events: QueryEvent[]}> $groups */
        $groups = [];

        foreach ($events as $event) {
            // Only analyze queries with WHERE or ORDER BY — those benefit from indexes
            if (! $this->hasIndexableClause($event->sql)) {
                continue;
            }

            $fingerprint = QueryFingerprint::fromSql($event->sql);

            if (! isset($groups[$fingerprint->hash])) {
                $groups[$fingerprint->hash] = [
                    'fingerprint' => $fingerprint,
                    'events' => [],
                ];
            }

            $groups[$fingerprint->hash]['events'][] = $event;
        }

        $issues = [];

        foreach ($groups as $group) {
            $groupEvents = $group['events'];
            $count = count($groupEvents);

            if ($count < $this->minOccurrences) {
                continue;
            }

            $totalTime = array_sum(array_map(static fn (QueryEvent $e) => $e->timeMs, $groupEvents));
            $avgTime = $totalTime / $count;

            if ($avgTime < $this->minAvgMs) {
                continue;
            }

            $fingerprint = $group['fingerprint'];
            $first = $groupEvents[0];
            $samples = array_slice($groupEvents, 0, 10);

            $tableAndColumns = $this->extractTableAndColumns($first->sql);
            $confidence = $this->calculateConfidence($count, $avgTime);

            $issues[] = new Issue(
                id: Issue::generateId($this->type(), $fingerprint, $first->contextId),
                type: $this->type(),
                severity: $this->determineSeverity($confidence, $avgTime),
                confidence: $confidence,
                title: sprintf('Possible missing index: %s', $this->truncateSql($first->sql)),
                description: sprintf(
                    'This query runs %d times with an average of %.1fms. '
                    .'The WHERE/ORDER BY clause suggests an index might help.%s',
                    $count,
                    $avgTime,
                    $tableAndColumns !== null
                        ? sprintf(' Consider indexing %s(%s).', $tableAndColumns['table'], implode(', ', $tableAndColumns['columns']))
                        : '',
                ),
                evidence: new Evidence(
                    queries: $samples,
                    queryCount: $count,
                    totalTimeMs: $totalTime,
                    fingerprint: $fingerprint,
                ),
                recommendation: new Recommendation(
                    action: $this->buildRecommendation($tableAndColumns),
                    code: $this->buildMigrationCode($tableAndColumns),
                    docsUrl: 'https://laravel.com/docs/migrations#creating-indexes',
                ),
                sourceContext: new SourceContext(
                    route: $first->route,
                    file: $first->stackExcerpt[0]['file'] ?? null,
                    line: $first->stackExcerpt[0]['line'] ?? null,
                    controller: $first->controller,
                ),
                createdAt: $first->timestamp,
            );
        }

        return $issues;
    }

    public function type(): IssueType
    {
        return IssueType::MissingIndex;
    }

    private function hasIndexableClause(string $sql): bool
    {
        return preg_match('/\b(where|order\s+by|group\s+by|having)\b/i', $sql) === 1;
    }

    /**
     * Confidence formula from ANALYZERS.md (without EXPLAIN data):
     * base = 0.3
     * frequency_bonus = min(0.2, occurrences / 100)
     * time_bonus = 0.1 if avg_time > 200ms
     */
    private function calculateConfidence(int $count, float $avgTimeMs): float
    {
        $base = 0.3;
        $frequencyBonus = min(0.2, $count / 100);
        $timeBonus = $avgTimeMs > 200.0 ? 0.1 : 0.0;

        return min(1.0, $base + $frequencyBonus + $timeBonus);
    }

    private function determineSeverity(float $confidence, float $avgTimeMs): Severity
    {
        return match (true) {
            $confidence >= 0.8 && $avgTimeMs >= 500.0 => Severity::High,
            $confidence >= 0.5 && $avgTimeMs >= 100.0 => Severity::Medium,
            default => Severity::Low,
        };
    }

    /**
     * Try to extract the table name and WHERE columns from SQL.
     * Returns null if parsing fails — this is best-effort heuristic.
     *
     * @return array{table: string, columns: string[]}|null
     */
    private function extractTableAndColumns(string $sql): ?array
    {
        // Extract table name from "FROM table" or "UPDATE table" or "DELETE FROM table"
        $table = null;
        if (preg_match('/\bfrom\s+[`"]?(\w+)[`"]?/i', $sql, $m)) {
            $table = $m[1];
        } elseif (preg_match('/\bupdate\s+[`"]?(\w+)[`"]?/i', $sql, $m)) {
            $table = $m[1];
        }

        if ($table === null) {
            return null;
        }

        // Extract column names from WHERE clause equality comparisons
        $columns = [];
        if (preg_match_all('/\bwhere\b.*?[`"]?(\w+)[`"]?\s*(?:=|>|<|>=|<=|<>|!=|like|in)\s/i', $sql, $matches)) {
            $columns = array_unique($matches[1]);
        }

        // Fallback: try simpler pattern for individual WHERE conditions
        if ($columns === []) {
            if (preg_match_all('/[`"]?(\w+)[`"]?\s*(?:=|>|<|>=|<=|<>|!=)\s*\?/i', $sql, $matches)) {
                $columns = array_values(array_unique($matches[1]));
            }
        }

        if ($columns === []) {
            return null;
        }

        // Filter out SQL keywords that might match as column names
        $sqlKeywords = ['select', 'from', 'where', 'and', 'or', 'not', 'in', 'is', 'null', 'like', 'between'];
        $columns = array_values(array_filter(
            $columns,
            static fn (string $col) => ! in_array(strtolower($col), $sqlKeywords, true),
        ));

        if ($columns === []) {
            return null;
        }

        return ['table' => $table, 'columns' => $columns];
    }

    /**
     * @param  array{table: string, columns: string[]}|null  $tableAndColumns
     */
    private function buildRecommendation(?array $tableAndColumns): string
    {
        if ($tableAndColumns === null) {
            return 'Consider adding an index. Run EXPLAIN on this query to identify which columns to index.';
        }

        return sprintf(
            'Add an index on %s(%s).',
            $tableAndColumns['table'],
            implode(', ', $tableAndColumns['columns']),
        );
    }

    /**
     * @param  array{table: string, columns: string[]}|null  $tableAndColumns
     */
    private function buildMigrationCode(?array $tableAndColumns): ?string
    {
        if ($tableAndColumns === null) {
            return null;
        }

        $table = $tableAndColumns['table'];
        $columns = $tableAndColumns['columns'];
        $indexName = 'idx_'.$table.'_'.implode('_', $columns);
        $columnList = count($columns) === 1
            ? "'".$columns[0]."'"
            : "['".implode("', '", $columns)."']";

        return sprintf(
            "// In a migration:\nSchema::table('%s', function (Blueprint \$table) {\n    \$table->index(%s, '%s');\n});",
            $table,
            $columnList,
            $indexName,
        );
    }

    private function truncateSql(string $sql, int $maxLength = 80): string
    {
        $sql = trim(preg_replace('/\s+/', ' ', $sql) ?? $sql);

        if (strlen($sql) <= $maxLength) {
            return $sql;
        }

        return substr($sql, 0, $maxLength - 3).'...';
    }
}
