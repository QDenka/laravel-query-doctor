<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Application;

use QDenka\QueryDoctor\Domain\Contracts\ReporterInterface;
use QDenka\QueryDoctor\Domain\Contracts\StorageInterface;
use QDenka\QueryDoctor\Domain\Enums\Severity;
use QDenka\QueryDoctor\Domain\Issue;

final class ReportService
{
    /** @var array<string, ReporterInterface> */
    private array $reporters = [];

    public function __construct(
        private readonly StorageInterface $storage,
        private readonly AnalysisPipeline $pipeline,
    ) {}

    public function addReporter(ReporterInterface $reporter): void
    {
        $this->reporters[$reporter->format()] = $reporter;
    }

    /**
     * Run analysis on stored events and generate a report.
     * Falls back to already-stored issues if no events are available.
     *
     * @param  array<string, mixed>  $filters
     * @return Issue[]
     */
    public function analyzeStored(array $filters = []): array
    {
        $events = $this->storage->getEvents($filters);

        if ($events !== []) {
            // Group events by context and analyze each context
            /** @var array<string, \QDenka\QueryDoctor\Domain\QueryEvent[]> $byContext */
            $byContext = [];
            foreach ($events as $event) {
                $byContext[$event->contextId][] = $event;
            }

            $allIssues = [];
            foreach ($byContext as $contextEvents) {
                $issues = $this->pipeline->analyze($contextEvents);
                foreach ($issues as $issue) {
                    $allIssues[$issue->id] = $issue;
                }
            }

            // Persist issues so the dashboard can display them
            foreach ($allIssues as $issue) {
                $this->storage->storeIssue($issue);
            }

            return array_values($allIssues);
        }

        // No events — return already-stored issues (from previous analysis runs)
        return $this->storage->getIssues($filters);
    }

    /**
     * Generate a formatted report string.
     *
     * @param  Issue[]  $issues
     */
    public function render(array $issues, string $format): string
    {
        $reporter = $this->reporters[$format] ?? null;

        if ($reporter === null) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown report format "%s". Available: %s',
                $format,
                implode(', ', array_keys($this->reporters)),
            ));
        }

        return $reporter->render($issues);
    }

    /**
     * Check if any issue meets or exceeds the given severity threshold.
     *
     * @param  Issue[]  $issues
     */
    public function hasIssuesAtOrAbove(array $issues, Severity $threshold): bool
    {
        foreach ($issues as $issue) {
            if ($issue->severity->isAtLeast($threshold)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Count issues grouped by severity.
     *
     * @param  Issue[]  $issues
     * @return array<string, int>
     */
    public function countBySeverity(array $issues): array
    {
        $counts = [
            'critical' => 0,
            'high' => 0,
            'medium' => 0,
            'low' => 0,
        ];

        foreach ($issues as $issue) {
            $counts[$issue->severity->value]++;
        }

        return $counts;
    }

    /**
     * @return string[]
     */
    public function availableFormats(): array
    {
        return array_keys($this->reporters);
    }
}
