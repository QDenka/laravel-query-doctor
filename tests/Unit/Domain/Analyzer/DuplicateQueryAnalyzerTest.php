<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Tests\Unit\Domain\Analyzer;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QDenka\QueryDoctor\Domain\Analyzer\DuplicateQueryAnalyzer;
use QDenka\QueryDoctor\Domain\Enums\IssueType;
use QDenka\QueryDoctor\Domain\Enums\Severity;
use QDenka\QueryDoctor\Tests\Unit\Concerns\BuildsQueryEvents;

final class DuplicateQueryAnalyzerTest extends TestCase
{
    use BuildsQueryEvents;

    #[Test]
    public function it_flags_exact_duplicates_above_threshold(): void
    {
        $analyzer = new DuplicateQueryAnalyzer(minCount: 3);
        $events = $this->makeDuplicateEvents(5);

        $issues = $analyzer->analyze($events);

        $this->assertCount(1, $issues);
        $this->assertSame(IssueType::Duplicate, $issues[0]->type);
        $this->assertSame(1.0, $issues[0]->confidence);
    }

    #[Test]
    public function it_ignores_below_threshold(): void
    {
        $analyzer = new DuplicateQueryAnalyzer(minCount: 3);
        $events = $this->makeDuplicateEvents(2);

        $issues = $analyzer->analyze($events);

        $this->assertCount(0, $issues);
    }

    #[Test]
    public function it_flags_at_exact_threshold(): void
    {
        $analyzer = new DuplicateQueryAnalyzer(minCount: 3);
        $events = $this->makeDuplicateEvents(3);

        $issues = $analyzer->analyze($events);

        $this->assertCount(1, $issues);
    }

    #[Test]
    public function same_fingerprint_different_bindings_are_not_duplicates(): void
    {
        $analyzer = new DuplicateQueryAnalyzer(minCount: 3);

        // These have the same SQL structure but different bindings
        $events = $this->makeNPlusOneEvents(5);

        $issues = $analyzer->analyze($events);

        // Should NOT flag as duplicate — each has unique bindings
        $this->assertCount(0, $issues);
    }

    #[Test]
    public function severity_is_high_above_20(): void
    {
        $analyzer = new DuplicateQueryAnalyzer(minCount: 3);
        $events = $this->makeDuplicateEvents(25);

        $issues = $analyzer->analyze($events);

        $this->assertSame(Severity::High, $issues[0]->severity);
    }

    #[Test]
    public function severity_is_medium_above_10(): void
    {
        $analyzer = new DuplicateQueryAnalyzer(minCount: 3);
        $events = $this->makeDuplicateEvents(12);

        $issues = $analyzer->analyze($events);

        $this->assertSame(Severity::Medium, $issues[0]->severity);
    }

    #[Test]
    public function severity_is_low_below_10(): void
    {
        $analyzer = new DuplicateQueryAnalyzer(minCount: 3);
        $events = $this->makeDuplicateEvents(5);

        $issues = $analyzer->analyze($events);

        $this->assertSame(Severity::Low, $issues[0]->severity);
    }

    #[Test]
    public function it_detects_multiple_groups_of_duplicates(): void
    {
        $analyzer = new DuplicateQueryAnalyzer(minCount: 3);

        $events = [
            ...$this->makeDuplicateEvents(4, "select * from settings where key = 'app.name'"),
            ...$this->makeDuplicateEvents(3, 'select count(*) from users'),
        ];

        $issues = $analyzer->analyze($events);

        $this->assertCount(2, $issues);
    }

    #[Test]
    public function evidence_has_correct_count_and_time(): void
    {
        $analyzer = new DuplicateQueryAnalyzer(minCount: 3);
        $events = $this->makeDuplicateEvents(5);

        $issues = $analyzer->analyze($events);

        $this->assertSame(5, $issues[0]->evidence->queryCount);
        $this->assertSame(7.5, $issues[0]->evidence->totalTimeMs); // 5 * 1.5ms
    }

    #[Test]
    public function evidence_has_at_most_10_sample_queries(): void
    {
        $analyzer = new DuplicateQueryAnalyzer(minCount: 3);
        $events = $this->makeDuplicateEvents(20);

        $issues = $analyzer->analyze($events);

        $this->assertCount(10, $issues[0]->evidence->queries);
        $this->assertSame(20, $issues[0]->evidence->queryCount);
    }

    #[Test]
    public function it_returns_empty_for_no_events(): void
    {
        $analyzer = new DuplicateQueryAnalyzer;

        $this->assertSame([], $analyzer->analyze([]));
    }

    #[Test]
    public function type_returns_duplicate(): void
    {
        $analyzer = new DuplicateQueryAnalyzer;

        $this->assertSame(IssueType::Duplicate, $analyzer->type());
    }
}
