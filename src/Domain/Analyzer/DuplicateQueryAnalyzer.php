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

final class DuplicateQueryAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly int $minCount = 3,
    ) {}

    /**
     * @param  QueryEvent[]  $events
     * @return Issue[]
     */
    public function analyze(array $events): array
    {
        // Group by exact SQL (not fingerprint — we want exact duplicates)
        /** @var array<string, QueryEvent[]> $grouped */
        $grouped = [];

        foreach ($events as $event) {
            $key = $event->sql.'|'.serialize($event->bindings);
            $grouped[$key][] = $event;
        }

        $issues = [];

        foreach ($grouped as $group) {
            $count = count($group);

            if ($count < $this->minCount) {
                continue;
            }

            $first = $group[0];
            $fingerprint = QueryFingerprint::fromSql($first->sql);
            $totalTime = array_sum(array_map(static fn (QueryEvent $e) => $e->timeMs, $group));
            $samples = array_slice($group, 0, 10);

            $issues[] = new Issue(
                id: Issue::generateId($this->type(), $fingerprint, $first->contextId),
                type: $this->type(),
                severity: $this->determineSeverity($count),
                confidence: 1.0, // exact duplicates are deterministic
                title: sprintf('Duplicate query (%dx): %s', $count, $this->truncateSql($first->sql)),
                description: sprintf(
                    'This exact query runs %d times in a single context. '
                    .'Total time wasted: %.1fms. Cache the result or restructure to query once.',
                    $count,
                    $totalTime,
                ),
                evidence: new Evidence(
                    queries: $samples,
                    queryCount: $count,
                    totalTimeMs: $totalTime,
                    fingerprint: $fingerprint,
                ),
                recommendation: new Recommendation(
                    action: 'Cache the result or eliminate the duplicate call.',
                    code: "Cache::remember('key', 60, fn () => DB::...)",
                    docsUrl: 'https://laravel.com/docs/cache',
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
        return IssueType::Duplicate;
    }

    private function determineSeverity(int $count): Severity
    {
        return match (true) {
            $count >= 20 => Severity::High,
            $count >= 10 => Severity::Medium,
            default => Severity::Low,
        };
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
