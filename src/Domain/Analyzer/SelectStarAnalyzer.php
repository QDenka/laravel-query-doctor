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

final class SelectStarAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly int $minOccurrences = 3,
    ) {}

    /**
     * @param  QueryEvent[]  $events
     * @return Issue[]
     */
    public function analyze(array $events): array
    {
        // Group SELECT * events by fingerprint
        /** @var array<string, array{fingerprint: QueryFingerprint, events: QueryEvent[]}> $groups */
        $groups = [];

        foreach ($events as $event) {
            if (! $this->isSelectStar($event->sql)) {
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

            $fingerprint = $group['fingerprint'];
            $first = $groupEvents[0];
            $totalTime = array_sum(array_map(static fn (QueryEvent $e) => $e->timeMs, $groupEvents));
            $hasJoin = $this->hasJoin($first->sql);
            $samples = array_slice($groupEvents, 0, 10);

            $issues[] = new Issue(
                id: Issue::generateId($this->type(), $fingerprint, $first->contextId),
                type: $this->type(),
                severity: $this->determineSeverity($count, $hasJoin),
                confidence: $this->calculateConfidence($count, $totalTime, $hasJoin),
                title: sprintf('SELECT * (%dx): %s', $count, $this->truncateSql($first->sql)),
                description: sprintf(
                    'This query uses SELECT * and runs %d times. '
                    .'Fetching all columns increases memory usage and network transfer.%s',
                    $count,
                    $hasJoin ? ' This is especially wasteful in queries with JOINs.' : '',
                ),
                evidence: new Evidence(
                    queries: $samples,
                    queryCount: $count,
                    totalTimeMs: $totalTime,
                    fingerprint: $fingerprint,
                ),
                recommendation: new Recommendation(
                    action: 'Specify only the columns you need.',
                    code: "// Before:\n\$users = User::all();\n\n// After:\n\$users = User::select(['id', 'name', 'email'])->get();",
                    docsUrl: 'https://laravel.com/docs/queries#select-statements',
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
        return IssueType::SelectStar;
    }

    /**
     * Detect SELECT * or SELECT table.* patterns.
     */
    private function isSelectStar(string $sql): bool
    {
        // Match "select *" or "select table.*" at word boundaries
        // Avoids matching inside comments or strings (already stripped by fingerprint,
        // but we check raw SQL here)
        return preg_match('/\bselect\s+(\w+\.)?\*/i', $sql) === 1;
    }

    private function hasJoin(string $sql): bool
    {
        return preg_match('/\b(inner|left|right|cross|full)\s+join\b/i', $sql) === 1
            || preg_match('/\bjoin\b/i', $sql) === 1;
    }

    /**
     * Confidence formula from ANALYZERS.md:
     * base = 0.4
     * frequency_bonus = min(0.3, occurrences / 20)
     * time_bonus = 0.2 if total_time > 100ms
     * join_bonus = 0.1 if query has JOIN
     */
    private function calculateConfidence(int $count, float $totalTimeMs, bool $hasJoin): float
    {
        $base = 0.4;
        $frequencyBonus = min(0.3, $count / 20);
        $timeBonus = $totalTimeMs > 100.0 ? 0.2 : 0.0;
        $joinBonus = $hasJoin ? 0.1 : 0.0;

        return min(1.0, $base + $frequencyBonus + $timeBonus + $joinBonus);
    }

    /**
     * Always Low unless it's a JOIN with high frequency, then Medium.
     */
    private function determineSeverity(int $count, bool $hasJoin): Severity
    {
        if ($hasJoin && $count >= 10) {
            return Severity::Medium;
        }

        return Severity::Low;
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
