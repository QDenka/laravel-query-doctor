<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Domain\Contracts;

use QDenka\QueryDoctor\Domain\Enums\IssueType;
use QDenka\QueryDoctor\Domain\Issue;
use QDenka\QueryDoctor\Domain\QueryEvent;

interface AnalyzerInterface
{
    /**
     * Analyze a batch of query events from a single context (request/job/command)
     * and return any detected issues.
     *
     * @param  QueryEvent[]  $events  Captured queries from one context
     * @return Issue[] Detected problems, empty array if none found
     */
    public function analyze(array $events): array;

    /**
     * The type of issue this analyzer detects.
     */
    public function type(): IssueType;
}
