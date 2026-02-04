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

final class SlowQueryAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly float $thresholdMs = 100.0,
    ) {}

    /**
     * @param  QueryEvent[]  $events
     * @return Issue[]
     */
    public function analyze(array $events): array
    {
        $issues = [];

        foreach ($events as $event) {
            if ($event->timeMs < $this->thresholdMs) {
                continue;
            }

            $fingerprint = QueryFingerprint::fromSql($event->sql);

            $issues[] = new Issue(
                id: Issue::generateId($this->type(), $fingerprint, $event->contextId),
                type: $this->type(),
                severity: $this->determineSeverity($event->timeMs),
                confidence: 1.0, // time is measured, not estimated
                title: sprintf('Slow query: %.0fms — %s', $event->timeMs, $this->truncateSql($event->sql)),
                description: sprintf(
                    'This query took %.1fms to execute, which exceeds the threshold of %.0fms.',
                    $event->timeMs,
                    $this->thresholdMs,
                ),
                evidence: new Evidence(
                    queries: [$event],
                    queryCount: 1,
                    totalTimeMs: $event->timeMs,
                    fingerprint: $fingerprint,
                ),
                recommendation: new Recommendation(
                    action: 'Optimize this query. Run EXPLAIN to identify bottlenecks.',
                    code: null,
                    docsUrl: 'https://laravel.com/docs/queries',
                ),
                sourceContext: new SourceContext(
                    route: $event->route,
                    file: $event->stackExcerpt[0]['file'] ?? null,
                    line: $event->stackExcerpt[0]['line'] ?? null,
                    controller: $event->controller,
                ),
                createdAt: $event->timestamp,
            );
        }

        return $issues;
    }

    public function type(): IssueType
    {
        return IssueType::Slow;
    }

    private function determineSeverity(float $timeMs): Severity
    {
        return match (true) {
            $timeMs >= 5000.0 => Severity::Critical,
            $timeMs >= 1000.0 => Severity::High,
            $timeMs >= 500.0 => Severity::Medium,
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
