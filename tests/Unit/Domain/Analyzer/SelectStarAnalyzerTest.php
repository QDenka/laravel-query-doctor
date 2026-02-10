<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Tests\Unit\Domain\Analyzer;

use PHPUnit\Framework\TestCase;
use QDenka\QueryDoctor\Domain\Analyzer\SelectStarAnalyzer;
use QDenka\QueryDoctor\Domain\Enums\IssueType;
use QDenka\QueryDoctor\Domain\Enums\Severity;
use QDenka\QueryDoctor\Tests\Unit\Concerns\BuildsQueryEvents;

final class SelectStarAnalyzerTest extends TestCase
{
    use BuildsQueryEvents;

    private SelectStarAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new SelectStarAnalyzer(minOccurrences: 3);
    }

    public function test_type_returns_select_star(): void
    {
        $this->assertSame(IssueType::SelectStar, $this->analyzer->type());
    }

    public function test_detects_select_star(): void
    {
        $events = [
            $this->makeEvent(sql: 'select * from users'),
            $this->makeEvent(sql: 'select * from users'),
            $this->makeEvent(sql: 'select * from users'),
        ];

        $issues = $this->analyzer->analyze($events);

        $this->assertCount(1, $issues);
        $this->assertSame(IssueType::SelectStar, $issues[0]->type);
        $this->assertStringContainsString('SELECT *', $issues[0]->title);
    }

    public function test_detects_select_table_star(): void
    {
        $events = [
            $this->makeEvent(sql: 'select users.* from users join posts on users.id = posts.user_id'),
            $this->makeEvent(sql: 'select users.* from users join posts on users.id = posts.user_id'),
            $this->makeEvent(sql: 'select users.* from users join posts on users.id = posts.user_id'),
        ];

        $issues = $this->analyzer->analyze($events);
        $this->assertCount(1, $issues);
    }

    public function test_ignores_explicit_column_selection(): void
    {
        $events = [
            $this->makeEvent(sql: 'select id, name, email from users'),
            $this->makeEvent(sql: 'select id, name, email from users'),
            $this->makeEvent(sql: 'select id, name, email from users'),
        ];

        $issues = $this->analyzer->analyze($events);
        $this->assertCount(0, $issues);
    }

    public function test_ignores_below_min_occurrences(): void
    {
        $events = [
            $this->makeEvent(sql: 'select * from users'),
            $this->makeEvent(sql: 'select * from users'),
        ];

        $issues = $this->analyzer->analyze($events);
        $this->assertCount(0, $issues);
    }

    public function test_severity_low_without_join(): void
    {
        $events = array_fill(0, 5, $this->makeEvent(sql: 'select * from users'));
        $issues = $this->analyzer->analyze($events);

        $this->assertCount(1, $issues);
        $this->assertSame(Severity::Low, $issues[0]->severity);
    }

    public function test_severity_medium_with_join_and_high_frequency(): void
    {
        $sql = 'select * from users inner join posts on users.id = posts.user_id';
        $events = array_fill(0, 10, $this->makeEvent(sql: $sql));
        $issues = $this->analyzer->analyze($events);

        $this->assertCount(1, $issues);
        $this->assertSame(Severity::Medium, $issues[0]->severity);
    }

    public function test_severity_low_with_join_but_low_frequency(): void
    {
        $sql = 'select * from users left join posts on users.id = posts.user_id';
        $events = array_fill(0, 3, $this->makeEvent(sql: $sql));
        $issues = $this->analyzer->analyze($events);

        $this->assertCount(1, $issues);
        $this->assertSame(Severity::Low, $issues[0]->severity);
    }

    public function test_confidence_increases_with_frequency(): void
    {
        $events = array_fill(0, 20, $this->makeEvent(sql: 'select * from users', timeMs: 1.0));
        $issues = $this->analyzer->analyze($events);

        $this->assertCount(1, $issues);
        // base 0.4 + frequency min(0.3, 20/20) = 0.4 + 0.3 = 0.7 (no time/join bonus at 1ms)
        $this->assertGreaterThanOrEqual(0.7, $issues[0]->confidence);
    }

    public function test_confidence_includes_time_bonus(): void
    {
        $events = array_fill(0, 3, $this->makeEvent(sql: 'select * from users', timeMs: 50.0));
        $issues = $this->analyzer->analyze($events);

        $this->assertCount(1, $issues);
        // total time = 150ms > 100ms, so time bonus = 0.2
        // base 0.4 + frequency min(0.3, 3/20)=0.15 + time 0.2 = 0.75
        $this->assertGreaterThanOrEqual(0.7, $issues[0]->confidence);
    }

    public function test_confidence_includes_join_bonus(): void
    {
        $sql = 'select * from users join posts on users.id = posts.user_id';
        $events = array_fill(0, 3, $this->makeEvent(sql: $sql, timeMs: 1.0));
        $issues = $this->analyzer->analyze($events);

        $this->assertCount(1, $issues);
        // base 0.4 + frequency min(0.3, 3/20)=0.15 + join 0.1 = 0.65
        $this->assertGreaterThanOrEqual(0.6, $issues[0]->confidence);
    }

    public function test_groups_different_queries_separately(): void
    {
        $events = array_merge(
            array_fill(0, 3, $this->makeEvent(sql: 'select * from users')),
            array_fill(0, 3, $this->makeEvent(sql: 'select * from posts')),
        );

        $issues = $this->analyzer->analyze($events);
        $this->assertCount(2, $issues);
    }

    public function test_empty_events_returns_no_issues(): void
    {
        $this->assertSame([], $this->analyzer->analyze([]));
    }

    public function test_recommendation_suggests_column_selection(): void
    {
        $events = array_fill(0, 3, $this->makeEvent(sql: 'select * from users'));
        $issues = $this->analyzer->analyze($events);

        $this->assertStringContainsString('columns you need', $issues[0]->recommendation->action);
        $this->assertNotNull($issues[0]->recommendation->code);
    }

    public function test_description_mentions_join_warning(): void
    {
        $sql = 'select * from users inner join posts on users.id = posts.user_id';
        $events = array_fill(0, 3, $this->makeEvent(sql: $sql));
        $issues = $this->analyzer->analyze($events);

        $this->assertStringContainsString('JOIN', $issues[0]->description);
    }

    public function test_custom_min_occurrences(): void
    {
        $analyzer = new SelectStarAnalyzer(minOccurrences: 1);

        $events = [$this->makeEvent(sql: 'select * from users')];
        $issues = $analyzer->analyze($events);

        $this->assertCount(1, $issues);
    }
}
