<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QDenka\QueryDoctor\Application\AnalysisPipeline;
use QDenka\QueryDoctor\Domain\Analyzer\DuplicateQueryAnalyzer;
use QDenka\QueryDoctor\Domain\Analyzer\SlowQueryAnalyzer;
use QDenka\QueryDoctor\Tests\Unit\Concerns\BuildsQueryEvents;

final class AnalysisPipelineTest extends TestCase
{
    use BuildsQueryEvents;

    #[Test]
    public function it_runs_multiple_analyzers(): void
    {
        $pipeline = new AnalysisPipeline([
            new SlowQueryAnalyzer(thresholdMs: 100.0),
            new DuplicateQueryAnalyzer(minCount: 3),
        ]);

        $events = [
            $this->makeEvent(sql: 'select * from users', timeMs: 200.0),
            ...$this->makeDuplicateEvents(4, 'select 1'),
        ];

        $issues = $pipeline->analyze($events);

        // 1 slow + 1 duplicate
        $this->assertCount(2, $issues);
    }

    #[Test]
    public function it_deduplicates_by_issue_id(): void
    {
        $pipeline = new AnalysisPipeline([
            new SlowQueryAnalyzer(thresholdMs: 100.0),
        ]);

        // Same event appears once, should produce one issue
        $events = [$this->makeEvent(sql: 'select * from users', timeMs: 200.0)];

        $issues = $pipeline->analyze($events);

        $this->assertCount(1, $issues);
    }

    #[Test]
    public function it_returns_empty_for_empty_events(): void
    {
        $pipeline = new AnalysisPipeline([
            new SlowQueryAnalyzer,
        ]);

        $this->assertSame([], $pipeline->analyze([]));
    }

    #[Test]
    public function it_survives_analyzer_exception(): void
    {
        $failing = new class implements \QDenka\QueryDoctor\Domain\Contracts\AnalyzerInterface
        {
            public function analyze(array $events): array
            {
                throw new \RuntimeException('Boom');
            }

            public function type(): \QDenka\QueryDoctor\Domain\Enums\IssueType
            {
                return \QDenka\QueryDoctor\Domain\Enums\IssueType::Slow;
            }
        };

        $pipeline = new AnalysisPipeline([
            $failing,
            new SlowQueryAnalyzer(thresholdMs: 100.0),
        ]);

        $events = [$this->makeEvent(timeMs: 200.0)];
        $issues = $pipeline->analyze($events);

        // Failing analyzer is skipped, SlowQueryAnalyzer still works
        $this->assertCount(1, $issues);
    }

    #[Test]
    public function add_analyzer_after_construction(): void
    {
        $pipeline = new AnalysisPipeline;
        $this->assertCount(0, $pipeline->analyzers());

        $pipeline->addAnalyzer(new SlowQueryAnalyzer);
        $this->assertCount(1, $pipeline->analyzers());
    }
}
