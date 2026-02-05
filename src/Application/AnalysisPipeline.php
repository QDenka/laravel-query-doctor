<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Application;

use QDenka\QueryDoctor\Domain\Contracts\AnalyzerInterface;
use QDenka\QueryDoctor\Domain\Issue;
use QDenka\QueryDoctor\Domain\QueryEvent;

final class AnalysisPipeline
{
    /** @var AnalyzerInterface[] */
    private array $analyzers = [];

    /**
     * @param  AnalyzerInterface[]  $analyzers
     */
    public function __construct(array $analyzers = [])
    {
        foreach ($analyzers as $analyzer) {
            $this->addAnalyzer($analyzer);
        }
    }

    public function addAnalyzer(AnalyzerInterface $analyzer): void
    {
        $this->analyzers[] = $analyzer;
    }

    /**
     * Run all registered analyzers against a batch of query events.
     *
     * @param  QueryEvent[]  $events  Events from a single context (request/job/command)
     * @return Issue[] Deduplicated issues from all analyzers
     */
    public function analyze(array $events): array
    {
        if ($events === []) {
            return [];
        }

        $allIssues = [];

        foreach ($this->analyzers as $analyzer) {
            try {
                $issues = $analyzer->analyze($events);

                foreach ($issues as $issue) {
                    // Deduplicate by issue ID
                    $allIssues[$issue->id] = $issue;
                }
            } catch (\Throwable $e) {
                // Analyzer failure must not crash the pipeline.
                // In production this would log, but domain layer has no logger dependency.
                // The infrastructure layer wraps this with logging.
                continue;
            }
        }

        return array_values($allIssues);
    }

    /**
     * @return AnalyzerInterface[]
     */
    public function analyzers(): array
    {
        return $this->analyzers;
    }
}
