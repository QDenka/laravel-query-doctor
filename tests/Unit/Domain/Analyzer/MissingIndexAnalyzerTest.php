<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Tests\Unit\Domain\Analyzer;

use PHPUnit\Framework\TestCase;
use QDenka\QueryDoctor\Domain\Analyzer\MissingIndexAnalyzer;
use QDenka\QueryDoctor\Domain\Enums\IssueType;
use QDenka\QueryDoctor\Domain\Enums\Severity;
use QDenka\QueryDoctor\Tests\Unit\Concerns\BuildsQueryEvents;

final class MissingIndexAnalyzerTest extends TestCase
{
    use BuildsQueryEvents;

    private MissingIndexAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new MissingIndexAnalyzer(minOccurrences: 5, minAvgMs: 50.0);
    }

    public function test_type_returns_missing_index(): void
    {
        $this->assertSame(IssueType::MissingIndex, $this->analyzer->type());
    }

    public function test_detects_slow_frequent_query_with_where(): void
    {
        $events = [];
        for ($i = 0; $i < 6; $i++) {
            $events[] = $this->makeEvent(
                sql: 'select * from orders where status = ?',
                timeMs: 100.0,
            );
        }

        $issues = $this->analyzer->analyze($events);

        $this->assertCount(1, $issues);
        $this->assertSame(IssueType::MissingIndex, $issues[0]->type);
        $this->assertStringContainsString('missing index', strtolower($issues[0]->title));
    }

    public function test_ignores_queries_without_where_or_order_by(): void
    {
        $events = [];
        for ($i = 0; $i < 10; $i++) {
            $events[] = $this->makeEvent(
                sql: 'select * from users',
                timeMs: 100.0,
            );
        }

        $issues = $this->analyzer->analyze($events);
        $this->assertCount(0, $issues);
    }

    public function test_ignores_below_min_occurrences(): void
    {
        $events = [];
        for ($i = 0; $i < 3; $i++) {
            $events[] = $this->makeEvent(
                sql: 'select * from orders where status = ?',
                timeMs: 100.0,
            );
        }

        $issues = $this->analyzer->analyze($events);
        $this->assertCount(0, $issues);
    }

    public function test_ignores_fast_queries(): void
    {
        $events = [];
        for ($i = 0; $i < 10; $i++) {
            $events[] = $this->makeEvent(
                sql: 'select * from orders where status = ?',
                timeMs: 5.0, // avg 5ms, below 50ms threshold
            );
        }

        $issues = $this->analyzer->analyze($events);
        $this->assertCount(0, $issues);
    }

    public function test_detects_queries_with_order_by(): void
    {
        $events = [];
        for ($i = 0; $i < 5; $i++) {
            $events[] = $this->makeEvent(
                sql: 'select id, name from users order by created_at desc',
                timeMs: 80.0,
            );
        }

        $issues = $this->analyzer->analyze($events);
        $this->assertCount(1, $issues);
    }

    public function test_detects_queries_with_group_by(): void
    {
        $events = [];
        for ($i = 0; $i < 5; $i++) {
            $events[] = $this->makeEvent(
                sql: 'select category_id, count(*) from products group by category_id',
                timeMs: 60.0,
            );
        }

        $issues = $this->analyzer->analyze($events);
        $this->assertCount(1, $issues);
    }

    public function test_severity_low_for_low_confidence(): void
    {
        $events = [];
        for ($i = 0; $i < 5; $i++) {
            $events[] = $this->makeEvent(
                sql: 'select * from orders where status = ?',
                timeMs: 55.0,
            );
        }

        $issues = $this->analyzer->analyze($events);
        $this->assertCount(1, $issues);
        $this->assertSame(Severity::Low, $issues[0]->severity);
    }

    public function test_severity_medium_for_moderate_conditions(): void
    {
        $analyzer = new MissingIndexAnalyzer(minOccurrences: 5, minAvgMs: 50.0);

        $events = [];
        // Need enough occurrences to push confidence >= 0.5 and avg time >= 100ms
        for ($i = 0; $i < 50; $i++) {
            $events[] = $this->makeEvent(
                sql: 'select * from orders where status = ?',
                timeMs: 150.0,
            );
        }

        $issues = $analyzer->analyze($events);
        $this->assertCount(1, $issues);
        $this->assertSame(Severity::Medium, $issues[0]->severity);
    }

    public function test_confidence_includes_time_bonus(): void
    {
        $events = [];
        for ($i = 0; $i < 5; $i++) {
            $events[] = $this->makeEvent(
                sql: 'select * from orders where status = ?',
                timeMs: 250.0, // avg > 200ms triggers time bonus
            );
        }

        $issues = $this->analyzer->analyze($events);
        $this->assertCount(1, $issues);
        // base 0.3 + frequency min(0.2, 5/100)=0.05 + time 0.1 = 0.45
        $this->assertGreaterThanOrEqual(0.4, $issues[0]->confidence);
    }

    public function test_extracts_table_and_columns_for_recommendation(): void
    {
        $events = [];
        for ($i = 0; $i < 5; $i++) {
            $events[] = $this->makeEvent(
                sql: 'select * from orders where status = ?',
                timeMs: 100.0,
            );
        }

        $issues = $this->analyzer->analyze($events);
        $this->assertCount(1, $issues);
        $this->assertStringContainsString('orders', $issues[0]->recommendation->action);
        $this->assertNotNull($issues[0]->recommendation->code);
        $this->assertStringContainsString('orders', $issues[0]->recommendation->code);
    }

    public function test_groups_different_fingerprints(): void
    {
        $events = [];
        for ($i = 0; $i < 5; $i++) {
            $events[] = $this->makeEvent(sql: 'select * from orders where status = ?', timeMs: 100.0);
        }
        for ($i = 0; $i < 5; $i++) {
            $events[] = $this->makeEvent(sql: 'select * from users where email = ?', timeMs: 100.0);
        }

        $issues = $this->analyzer->analyze($events);
        $this->assertCount(2, $issues);
    }

    public function test_empty_events_returns_no_issues(): void
    {
        $this->assertSame([], $this->analyzer->analyze([]));
    }

    public function test_recommendation_has_docs_url(): void
    {
        $events = [];
        for ($i = 0; $i < 5; $i++) {
            $events[] = $this->makeEvent(sql: 'select * from orders where status = ?', timeMs: 100.0);
        }

        $issues = $this->analyzer->analyze($events);
        $this->assertNotNull($issues[0]->recommendation->docsUrl);
        $this->assertStringContainsString('laravel.com', $issues[0]->recommendation->docsUrl);
    }

    public function test_custom_thresholds(): void
    {
        $analyzer = new MissingIndexAnalyzer(minOccurrences: 2, minAvgMs: 10.0);

        $events = [
            $this->makeEvent(sql: 'select * from orders where status = ?', timeMs: 15.0),
            $this->makeEvent(sql: 'select * from orders where status = ?', timeMs: 15.0),
        ];

        $issues = $analyzer->analyze($events);
        $this->assertCount(1, $issues);
    }
}
