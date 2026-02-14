<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Tests\Feature;

use QDenka\QueryDoctor\Application\BaselineService;
use QDenka\QueryDoctor\Domain\Contracts\StorageInterface;
use QDenka\QueryDoctor\Domain\Enums\IssueType;
use QDenka\QueryDoctor\Domain\Enums\Severity;
use QDenka\QueryDoctor\Domain\Evidence;
use QDenka\QueryDoctor\Domain\Issue;
use QDenka\QueryDoctor\Domain\QueryFingerprint;
use QDenka\QueryDoctor\Domain\Recommendation;
use QDenka\QueryDoctor\Domain\SourceContext;
use QDenka\QueryDoctor\Tests\TestCase;

/**
 * End-to-end baseline flow: seed issues → create baseline → add new issues
 * → verify old issues excluded + new issues included.
 */
final class BaselineFlowTest extends TestCase
{
    private StorageInterface $storage;

    private BaselineService $baseline;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = $this->app->make(StorageInterface::class);
        $this->baseline = $this->app->make(BaselineService::class);
    }

    private function makeIssue(string $id, IssueType $type, Severity $severity, string $sql): Issue
    {
        $fingerprint = QueryFingerprint::fromSql($sql);

        return new Issue(
            id: $id,
            type: $type,
            severity: $severity,
            confidence: 0.9,
            title: $type->label().': '.$sql,
            description: 'Test issue',
            evidence: new Evidence(
                queries: [],
                queryCount: 3,
                totalTimeMs: 150.0,
                fingerprint: $fingerprint,
            ),
            recommendation: new Recommendation(action: 'Fix it'),
            sourceContext: new SourceContext(route: 'GET /test', file: null, line: null, controller: null),
            createdAt: new \DateTimeImmutable,
        );
    }

    public function test_full_baseline_flow_excludes_old_and_includes_new(): void
    {
        // Step 1: Seed initial issues
        $slowIssue = $this->makeIssue('slow-1', IssueType::Slow, Severity::High, 'select * from reports where date > ?');
        $dupeIssue = $this->makeIssue('dupe-1', IssueType::Duplicate, Severity::Medium, "select * from config where key = 'app.name'");

        $this->storage->storeIssue($slowIssue);
        $this->storage->storeIssue($dupeIssue);

        // Step 2: Create baseline — should capture both issues
        $baselinedCount = $this->baseline->create();
        $this->assertSame(2, $baselinedCount);

        // Step 3: Verify baselined issue IDs
        $baselinedIds = $this->storage->getBaselinedIssueIds();
        $this->assertContains('slow-1', $baselinedIds);
        $this->assertContains('dupe-1', $baselinedIds);

        // Step 4: Add a new issue (not in baseline)
        $newIssue = $this->makeIssue('nplus1-1', IssueType::NPlusOne, Severity::High, 'select * from posts where user_id = ?');
        $this->storage->storeIssue($newIssue);

        // Step 5: Get all issues from storage
        $allIssues = $this->storage->getIssues();
        $this->assertCount(3, $allIssues);

        // Step 6: Filter baselined — only the new issue should remain
        $filtered = $this->baseline->filterBaselined($allIssues);
        $this->assertCount(1, $filtered);
        $this->assertSame('nplus1-1', $filtered[0]->id);
    }

    public function test_baseline_clear_makes_all_issues_visible_again(): void
    {
        // Seed and baseline
        $issue = $this->makeIssue('issue-x', IssueType::Slow, Severity::High, 'select * from big_table');
        $this->storage->storeIssue($issue);
        $this->baseline->create();

        // Issues are baselined
        $filtered = $this->baseline->filterBaselined($this->storage->getIssues());
        $this->assertCount(0, $filtered);

        // Clear baseline
        $this->baseline->clear();

        // Issues are visible again
        $filteredAfterClear = $this->baseline->filterBaselined($this->storage->getIssues());
        $this->assertCount(1, $filteredAfterClear);
        $this->assertSame('issue-x', $filteredAfterClear[0]->id);
    }

    public function test_ci_report_exits_0_for_baselined_issues_and_1_for_new(): void
    {
        // Seed an issue and baseline it
        $oldIssue = $this->makeIssue('old-1', IssueType::Slow, Severity::Critical, 'select * from legacy_table');
        $this->storage->storeIssue($oldIssue);
        $this->artisan('doctor:baseline')->assertExitCode(0);

        // CI report with baseline flag should pass (old issue is baselined)
        $this->artisan('doctor:ci-report', ['--fail-on' => 'high', '--baseline' => true])
            ->assertExitCode(0);

        // Add a new high-severity issue
        $newIssue = $this->makeIssue('new-1', IssueType::NPlusOne, Severity::Critical, 'select * from comments where post_id = ?');
        $this->storage->storeIssue($newIssue);

        // CI report with baseline should now fail (new issue is not baselined)
        $this->artisan('doctor:ci-report', ['--fail-on' => 'high', '--baseline' => true])
            ->assertExitCode(1);
    }

    public function test_ci_report_without_baseline_flag_sees_all_issues(): void
    {
        // Seed and baseline
        $issue = $this->makeIssue('issue-1', IssueType::Slow, Severity::High, 'select * from slow_table');
        $this->storage->storeIssue($issue);
        $this->artisan('doctor:baseline')->assertExitCode(0);

        // Without --baseline flag, the baselined issue is still reported
        $this->artisan('doctor:ci-report', ['--fail-on' => 'high'])
            ->assertExitCode(1);
    }

    public function test_ignore_then_baseline_flow(): void
    {
        // Seed two issues
        $issue1 = $this->makeIssue('ign-1', IssueType::Duplicate, Severity::Low, "select * from settings where key = 'x'");
        $issue2 = $this->makeIssue('ign-2', IssueType::Slow, Severity::High, 'select * from reports');
        $this->storage->storeIssue($issue1);
        $this->storage->storeIssue($issue2);

        // Ignore one issue
        $this->storage->ignoreIssue('ign-1');

        // Non-ignored issues
        $visible = $this->storage->getIssues(['is_ignored' => false]);
        $this->assertCount(1, $visible);
        $this->assertSame('ign-2', $visible[0]->id);

        // Baseline all current issues (including ignored ones)
        $baselinedCount = $this->baseline->create();
        $this->assertSame(2, $baselinedCount);

        // Both should be in baseline
        $baselinedIds = $this->storage->getBaselinedIssueIds();
        $this->assertContains('ign-1', $baselinedIds);
        $this->assertContains('ign-2', $baselinedIds);
    }
}
