<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Tests\Unit\Domain\Analyzer;

use PHPUnit\Framework\TestCase;
use QDenka\QueryDoctor\Domain\Analyzer\NPlusOneAnalyzer;
use QDenka\QueryDoctor\Domain\Enums\IssueType;
use QDenka\QueryDoctor\Domain\Enums\Severity;
use QDenka\QueryDoctor\Tests\Unit\Concerns\BuildsQueryEvents;

final class NPlusOneAnalyzerTest extends TestCase
{
    use BuildsQueryEvents;

    private NPlusOneAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new NPlusOneAnalyzer(minRepetitions: 5, minTotalMs: 20.0);
    }

    public function test_type_returns_n_plus_one(): void
    {
        $this->assertSame(IssueType::NPlusOne, $this->analyzer->type());
    }

    public function test_detects_n_plus_one_with_varying_bindings(): void
    {
        $events = $this->makeNPlusOneEvents(10, 'select * from posts where user_id = ?');
        $issues = $this->analyzer->analyze($events);

        $this->assertCount(1, $issues);
        $this->assertSame(IssueType::NPlusOne, $issues[0]->type);
        $this->assertStringContainsString('N+1', $issues[0]->title);
        $this->assertStringContainsString('10x', $issues[0]->title);
    }

    public function test_ignores_below_min_repetitions(): void
    {
        $events = $this->makeNPlusOneEvents(4, 'select * from posts where user_id = ?');
        $issues = $this->analyzer->analyze($events);

        $this->assertCount(0, $issues);
    }

    public function test_ignores_identical_bindings_as_duplicates(): void
    {
        // Identical bindings = duplicate, not N+1
        $events = $this->makeDuplicateEvents(10, "select * from settings where key = 'app.name'");
        $issues = $this->analyzer->analyze($events);

        $this->assertCount(0, $issues);
    }

    public function test_ignores_below_min_total_time(): void
    {
        // 5 events at 1ms each = 5ms total, below threshold of 20ms
        $events = $this->makeNPlusOneEvents(5, 'select * from posts where user_id = ?', timeMsEach: 1.0);
        $issues = $this->analyzer->analyze($events);

        $this->assertCount(0, $issues);
    }

    public function test_severity_low_for_few_repetitions(): void
    {
        $events = $this->makeNPlusOneEvents(5, 'select * from posts where user_id = ?', timeMsEach: 5.0);
        $issues = $this->analyzer->analyze($events);

        $this->assertCount(1, $issues);
        $this->assertSame(Severity::Low, $issues[0]->severity);
    }

    public function test_severity_medium_for_moderate_repetitions(): void
    {
        $events = $this->makeNPlusOneEvents(10, 'select * from posts where user_id = ?', timeMsEach: 10.0);
        $issues = $this->analyzer->analyze($events);

        $this->assertCount(1, $issues);
        $this->assertSame(Severity::Medium, $issues[0]->severity);
    }

    public function test_severity_high_for_many_repetitions(): void
    {
        $events = $this->makeNPlusOneEvents(20, 'select * from posts where user_id = ?', timeMsEach: 15.0);
        $issues = $this->analyzer->analyze($events);

        $this->assertCount(1, $issues);
        $this->assertSame(Severity::High, $issues[0]->severity);
    }

    public function test_severity_critical_for_extreme_repetitions(): void
    {
        $events = $this->makeNPlusOneEvents(50, 'select * from posts where user_id = ?', timeMsEach: 15.0);
        $issues = $this->analyzer->analyze($events);

        $this->assertCount(1, $issues);
        $this->assertSame(Severity::Critical, $issues[0]->severity);
    }

    public function test_confidence_includes_pattern_bonus_for_equality(): void
    {
        // "where user_id = ?" should get pattern bonus of 0.1
        $events = $this->makeNPlusOneEvents(5, 'select * from posts where user_id = ?', timeMsEach: 5.0);
        $issues = $this->analyzer->analyze($events);

        $this->assertCount(1, $issues);
        // base 0.6 + repetition_bonus 0.0 + pattern_bonus 0.1 = 0.7
        $this->assertEqualsWithDelta(0.7, $issues[0]->confidence, 0.01);
    }

    public function test_confidence_without_pattern_bonus(): void
    {
        // Complex WHERE without simple "column = ?" pattern
        $events = $this->makeNPlusOneEvents(5, 'select * from posts where id in (?)', timeMsEach: 5.0);
        $issues = $this->analyzer->analyze($events);

        $this->assertCount(1, $issues);
        // base 0.6 + repetition_bonus 0.0 + no pattern bonus = 0.6
        $this->assertEqualsWithDelta(0.6, $issues[0]->confidence, 0.01);
    }

    public function test_confidence_increases_with_repetitions(): void
    {
        $events = $this->makeNPlusOneEvents(55, 'select * from posts where user_id = ?', timeMsEach: 5.0);
        $issues = $this->analyzer->analyze($events);

        $this->assertCount(1, $issues);
        // base 0.6 + repetition_bonus min(0.3, (55-5)/50) = 0.6 + 1.0 capped at 0.3 = 0.9
        // + pattern_bonus 0.1 = 1.0
        $this->assertEqualsWithDelta(1.0, $issues[0]->confidence, 0.01);
    }

    public function test_groups_different_fingerprints_separately(): void
    {
        $events = array_merge(
            $this->makeNPlusOneEvents(6, 'select * from posts where user_id = ?', timeMsEach: 5.0),
            $this->makeNPlusOneEvents(6, 'select * from comments where post_id = ?', timeMsEach: 5.0),
        );

        $issues = $this->analyzer->analyze($events);
        $this->assertCount(2, $issues);
    }

    public function test_empty_events_returns_no_issues(): void
    {
        $this->assertSame([], $this->analyzer->analyze([]));
    }

    public function test_evidence_contains_sample_queries(): void
    {
        $events = $this->makeNPlusOneEvents(12, 'select * from posts where user_id = ?', timeMsEach: 5.0);
        $issues = $this->analyzer->analyze($events);

        $this->assertCount(1, $issues);
        // Samples capped at 10
        $this->assertCount(10, $issues[0]->evidence->queries);
        $this->assertSame(12, $issues[0]->evidence->queryCount);
    }

    public function test_recommendation_suggests_eager_loading(): void
    {
        $events = $this->makeNPlusOneEvents(5, 'select * from posts where user_id = ?', timeMsEach: 5.0);
        $issues = $this->analyzer->analyze($events);

        $this->assertStringContainsString('eager loading', $issues[0]->recommendation->action);
        $this->assertNotNull($issues[0]->recommendation->code);
        $this->assertNotNull($issues[0]->recommendation->docsUrl);
    }

    public function test_custom_thresholds(): void
    {
        $analyzer = new NPlusOneAnalyzer(minRepetitions: 3, minTotalMs: 5.0);

        $events = $this->makeNPlusOneEvents(3, 'select * from posts where user_id = ?', timeMsEach: 2.0);
        $issues = $analyzer->analyze($events);

        $this->assertCount(1, $issues);
    }
}
