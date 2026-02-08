<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Tests\Unit\Domain\Analyzer;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QDenka\QueryDoctor\Domain\Analyzer\SlowQueryAnalyzer;
use QDenka\QueryDoctor\Domain\Enums\IssueType;
use QDenka\QueryDoctor\Domain\Enums\Severity;
use QDenka\QueryDoctor\Tests\Unit\Concerns\BuildsQueryEvents;

final class SlowQueryAnalyzerTest extends TestCase
{
    use BuildsQueryEvents;

    #[Test]
    public function it_flags_queries_above_threshold(): void
    {
        $analyzer = new SlowQueryAnalyzer(thresholdMs: 100.0);
        $events = [$this->makeEvent(timeMs: 150.0)];

        $issues = $analyzer->analyze($events);

        $this->assertCount(1, $issues);
        $this->assertSame(IssueType::Slow, $issues[0]->type);
        $this->assertSame(1.0, $issues[0]->confidence);
    }

    #[Test]
    public function it_ignores_queries_below_threshold(): void
    {
        $analyzer = new SlowQueryAnalyzer(thresholdMs: 100.0);
        $events = [$this->makeEvent(timeMs: 50.0)];

        $issues = $analyzer->analyze($events);

        $this->assertCount(0, $issues);
    }

    #[Test]
    public function it_flags_at_exact_threshold(): void
    {
        $analyzer = new SlowQueryAnalyzer(thresholdMs: 100.0);
        $events = [$this->makeEvent(timeMs: 100.0)];

        $issues = $analyzer->analyze($events);

        $this->assertCount(1, $issues);
    }

    #[Test]
    public function severity_is_critical_above_5000ms(): void
    {
        $analyzer = new SlowQueryAnalyzer(thresholdMs: 100.0);
        $events = [$this->makeEvent(timeMs: 6000.0)];

        $issues = $analyzer->analyze($events);

        $this->assertSame(Severity::Critical, $issues[0]->severity);
    }

    #[Test]
    public function severity_is_high_above_1000ms(): void
    {
        $analyzer = new SlowQueryAnalyzer(thresholdMs: 100.0);
        $events = [$this->makeEvent(timeMs: 1500.0)];

        $issues = $analyzer->analyze($events);

        $this->assertSame(Severity::High, $issues[0]->severity);
    }

    #[Test]
    public function severity_is_medium_above_500ms(): void
    {
        $analyzer = new SlowQueryAnalyzer(thresholdMs: 100.0);
        $events = [$this->makeEvent(timeMs: 700.0)];

        $issues = $analyzer->analyze($events);

        $this->assertSame(Severity::Medium, $issues[0]->severity);
    }

    #[Test]
    public function severity_is_low_at_threshold(): void
    {
        $analyzer = new SlowQueryAnalyzer(thresholdMs: 100.0);
        $events = [$this->makeEvent(timeMs: 200.0)];

        $issues = $analyzer->analyze($events);

        $this->assertSame(Severity::Low, $issues[0]->severity);
    }

    #[Test]
    public function it_flags_multiple_slow_queries_independently(): void
    {
        $analyzer = new SlowQueryAnalyzer(thresholdMs: 100.0);
        $events = [
            $this->makeEvent(sql: 'select * from users', timeMs: 200.0),
            $this->makeEvent(sql: 'select * from posts', timeMs: 50.0),
            $this->makeEvent(sql: 'select * from orders', timeMs: 300.0),
        ];

        $issues = $analyzer->analyze($events);

        $this->assertCount(2, $issues);
    }

    #[Test]
    public function it_returns_empty_for_no_events(): void
    {
        $analyzer = new SlowQueryAnalyzer;

        $this->assertSame([], $analyzer->analyze([]));
    }

    #[Test]
    public function custom_threshold_works(): void
    {
        $analyzer = new SlowQueryAnalyzer(thresholdMs: 50.0);
        $events = [$this->makeEvent(timeMs: 60.0)];

        $issues = $analyzer->analyze($events);

        $this->assertCount(1, $issues);
    }

    #[Test]
    public function issue_has_correct_evidence(): void
    {
        $analyzer = new SlowQueryAnalyzer(thresholdMs: 100.0);
        $events = [$this->makeEvent(sql: 'select * from users where id = ?', timeMs: 250.0)];

        $issues = $analyzer->analyze($events);

        $this->assertSame(1, $issues[0]->evidence->queryCount);
        $this->assertSame(250.0, $issues[0]->evidence->totalTimeMs);
        $this->assertCount(1, $issues[0]->evidence->queries);
    }

    #[Test]
    public function type_returns_slow(): void
    {
        $analyzer = new SlowQueryAnalyzer;

        $this->assertSame(IssueType::Slow, $analyzer->type());
    }
}
