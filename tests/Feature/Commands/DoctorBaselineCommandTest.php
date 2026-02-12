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

final class DoctorBaselineCommandTest extends TestCase
{
    private function seedIssue(string $id = 'test-issue-1'): void
    {
        $storage = $this->app->make(StorageInterface::class);
        $fingerprint = QueryFingerprint::fromSql('select * from users where id = ?');

        $storage->storeIssue(new Issue(
            id: $id,
            type: IssueType::Slow,
            severity: Severity::High,
            confidence: 1.0,
            title: 'Slow query',
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

    public function test_baseline_create(): void
    {
        $this->seedIssue('i-1');
        $this->seedIssue('i-2');

        $this->artisan('doctor:baseline')
            ->assertExitCode(0)
            ->expectsOutputToContain('Baseline created');
    }

    public function test_baseline_clear(): void
    {
        $this->seedIssue();
        $this->artisan('doctor:baseline');

        $this->artisan('doctor:baseline', ['--clear' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('Baseline cleared');
    }
}
