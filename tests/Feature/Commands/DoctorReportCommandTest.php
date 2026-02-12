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

final class DoctorReportCommandTest extends TestCase
{
    private function seedIssue(
        string $id = 'test-issue-1',
        Severity $severity = Severity::High,
        IssueType $type = IssueType::Slow,
    ): void {
        $storage = $this->app->make(StorageInterface::class);
        $fingerprint = QueryFingerprint::fromSql('select * from users where id = ?');

        $storage->storeIssue(new Issue(
            id: $id,
            type: $type,
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
            recommendation: new Recommendation(
                action: 'Add an index',
            ),
            sourceContext: new SourceContext(
                route: 'GET /users',
                file: null,
                line: null,
                controller: null,
            ),
            createdAt: new \DateTimeImmutable,
        ));
    }

    public function test_report_command_shows_no_issues(): void
    {
        $this->artisan('doctor:report')
            ->assertExitCode(0)
            ->expectsOutputToContain('No issues found for the given filters');
    }

    public function test_report_command_shows_issues_table(): void
    {
        $this->seedIssue();

        $this->artisan('doctor:report')
            ->assertExitCode(0)
            ->expectsOutputToContain('Found 1 issues');
    }

    public function test_report_command_json_format(): void
    {
        $this->seedIssue();

        $this->artisan('doctor:report', ['--format' => 'json'])
            ->assertExitCode(0);
    }

    public function test_report_command_md_format(): void
    {
        $this->seedIssue();

        $this->artisan('doctor:report', ['--format' => 'md'])
            ->assertExitCode(0);
    }

    public function test_report_command_period_filter(): void
    {
        $this->artisan('doctor:report', ['--period' => '1h'])
            ->assertExitCode(0);
    }
}
