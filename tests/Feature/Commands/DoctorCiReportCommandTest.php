<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Tests\Feature\Commands;

use QDenka\QueryDoctor\Domain\Contracts\StorageInterface;
use QDenka\QueryDoctor\Domain\Enums\IssueType;
use QDenka\QueryDoctor\Domain\Enums\Severity;
use QDenka\QueryDoctor\Domain\Evidence;
use QDenka\QueryDoctor\Domain\Issue;
use QDenka\QueryDoctor\Domain\QueryFingerprint;
use QDenka\QueryDoctor\Domain\Recommendation;
use QDenka\QueryDoctor\Domain\SourceContext;
use QDenka\QueryDoctor\Tests\TestCase;

final class DoctorCiReportCommandTest extends TestCase
{
    private function seedIssue(
        string $id = 'test-issue-1',
        Severity $severity = Severity::High,
    ): void {
        $storage = $this->app->make(StorageInterface::class);
        $fingerprint = QueryFingerprint::fromSql('select * from users where id = ?');

        $storage->storeIssue(new Issue(
            id: $id,
            type: IssueType::Slow,
            severity: $severity,
            confidence: 1.0,
            title: 'Slow query: select * from users where id = ?',
            description: 'Query took 500ms',
            evidence: new Evidence(
                queries: [],
                queryCount: 1,
                totalTimeMs: 500.0,
                fingerprint: $fingerprint,
            ),
            recommendation: new Recommendation(action: 'Add an index'),
            sourceContext: new SourceContext(route: 'GET /users', file: null, line: null, controller: null),
            createdAt: new \DateTimeImmutable,
        ));
    }

    public function test_ci_report_exits_0_with_no_issues(): void
    {
        $this->artisan('doctor:ci-report')
            ->assertExitCode(0);
    }

    public function test_ci_report_exits_1_with_high_severity_issues(): void
    {
        $this->seedIssue('i-1', Severity::High);

        $this->artisan('doctor:ci-report', ['--fail-on' => 'high'])
            ->assertExitCode(1);
    }

    public function test_ci_report_exits_0_when_issues_below_threshold(): void
    {
        $this->seedIssue('i-1', Severity::Low);

        $this->artisan('doctor:ci-report', ['--fail-on' => 'high'])
            ->assertExitCode(0);
    }

    public function test_ci_report_exits_1_when_critical_at_critical_threshold(): void
    {
        $this->seedIssue('i-1', Severity::Critical);

        $this->artisan('doctor:ci-report', ['--fail-on' => 'critical'])
            ->assertExitCode(1);
    }

    public function test_ci_report_exits_0_when_high_at_critical_threshold(): void
    {
        $this->seedIssue('i-1', Severity::High);

        $this->artisan('doctor:ci-report', ['--fail-on' => 'critical'])
            ->assertExitCode(0);
    }

    public function test_ci_report_with_baseline_excludes_known_issues(): void
    {
        $this->seedIssue('i-1', Severity::High);

        // Create baseline
        $this->artisan('doctor:baseline')->assertExitCode(0);

        // CI report with baseline should exit 0 (issue is baselined)
        $this->artisan('doctor:ci-report', ['--fail-on' => 'high', '--baseline' => true])
            ->assertExitCode(0);
    }
}
