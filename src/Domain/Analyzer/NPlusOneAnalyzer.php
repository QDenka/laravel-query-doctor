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

final class NPlusOneAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly int $minRepetitions = 5,
        private readonly float $minTotalMs = 20.0,
    ) {}

    /**
     * @param  QueryEvent[]  $events
     * @return Issue[]
     */
    public function analyze(array $events): array
    {
        // Group events by fingerprint
        /** @var array<string, array{fingerprint: QueryFingerprint, events: QueryEvent[]}> $groups */
        $groups = [];

        foreach ($events as $event) {
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

            if ($count < $this->minRepetitions) {
                continue;
            }

            // N+1 requires varying bindings — if all bindings are identical, it's a duplicate, not N+1
            if ($this->allBindingsIdentical($groupEvents)) {
                continue;
            }

            $totalTime = array_sum(array_map(static fn (QueryEvent $e) => $e->timeMs, $groupEvents));

            if ($totalTime < $this->minTotalMs) {
                continue;
            }

            $fingerprint = $group['fingerprint'];
            $first = $groupEvents[0];
            $samples = array_slice($groupEvents, 0, 10);

            $issues[] = new Issue(
                id: Issue::generateId($this->type(), $fingerprint, $first->contextId),
                type: $this->type(),
                severity: $this->determineSeverity($count, $totalTime),
                confidence: $this->calculateConfidence($count, $fingerprint),
                title: sprintf('N+1 query (%dx): %s', $count, $this->truncateSql($first->sql)),
                description: sprintf(
                    'This query pattern runs %d times in a single context with different bindings. '
                    .'Total time: %.1fms. This is typically caused by accessing a relationship inside a loop '
                    .'without eager loading.',
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
                    action: "Add eager loading with ->with('relation') or ->load('relation').",
                    code: "// Before:\n\$users = User::all();\nforeach (\$users as \$user) {\n    \$user->posts; // N+1!\n}\n\n// After:\n\$users = User::with('posts')->get();",
                    docsUrl: 'https://laravel.com/docs/eloquent-relationships#eager-loading',
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
        return IssueType::NPlusOne;
    }

    /**
     * N+1 requires varying bindings. If every event has identical bindings,
     * it's a duplicate query, not N+1.
     *
     * @param  QueryEvent[]  $events
     */
    private function allBindingsIdentical(array $events): bool
    {
        if (count($events) <= 1) {
            return true;
        }

        $firstBindings = serialize($events[0]->bindings);

        for ($i = 1, $count = count($events); $i < $count; $i++) {
            if (serialize($events[$i]->bindings) !== $firstBindings) {
                return false;
            }
        }

        return true;
    }

    /**
     * Confidence formula from ANALYZERS.md:
     * base = 0.6
     * repetition_bonus = min(0.3, (count - min_repetitions) / 50)
     * pattern_bonus = 0.1 if WHERE clause contains single-column equality (user_id = ?)
     */
    private function calculateConfidence(int $count, QueryFingerprint $fingerprint): float
    {
        $base = 0.6;
        $repetitionBonus = min(0.3, ($count - $this->minRepetitions) / 50);

        // Pattern bonus: WHERE clause with single-column equality like "where user_id = ?"
        // This regex looks for a WHERE ... column = ? pattern, typical of relationship queries
        $patternBonus = preg_match('/\bwhere\s+\w+\s*=\s*\?/i', $fingerprint->value) === 1
            ? 0.1
            : 0.0;

        return min(1.0, $base + $repetitionBonus + $patternBonus);
    }

    private function determineSeverity(int $count, float $totalTimeMs): Severity
    {
        return match (true) {
            $count >= 50 && $totalTimeMs >= 500.0 => Severity::Critical,
            $count >= 20 && $totalTimeMs >= 200.0 => Severity::High,
            $count >= 10 && $totalTimeMs >= 50.0 => Severity::Medium,
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
