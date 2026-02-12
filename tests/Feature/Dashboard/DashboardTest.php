<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Tests\Feature\Dashboard;

use QDenka\QueryDoctor\Domain\Contracts\StorageInterface;
use QDenka\QueryDoctor\Domain\Enums\IssueType;
use QDenka\QueryDoctor\Domain\Enums\Severity;
use QDenka\QueryDoctor\Domain\Evidence;
use QDenka\QueryDoctor\Domain\Issue;
use QDenka\QueryDoctor\Domain\QueryFingerprint;
use QDenka\QueryDoctor\Domain\Recommendation;
use QDenka\QueryDoctor\Domain\SourceContext;
use QDenka\QueryDoctor\Tests\TestCase;

final class DashboardTest extends TestCase
{
    private function seedIssue(string $id = 'test-issue-1', Severity $severity = Severity::High): void
    {
        $storage = $this->app->make(StorageInterface::class);
        $fingerprint = QueryFingerprint::fromSql('select * from users where id = ?');

        $storage->storeIssue(new Issue(
            id: $id,
            type: IssueType::Slow,
            severity: $severity,
            confidence: 0.9,
            title: 'Slow query detected',
            description: 'Query took 500ms',
            evidence: new Evidence(
                queries: [],
                queryCount: 1,
                totalTimeMs: 500.0,
                fingerprint: $fingerprint,
            ),
            recommendation: new Recommendation(
                action: 'Add an index',
                code: 'CREATE INDEX idx ON users(id)',
            ),
            sourceContext: new SourceContext(
                route: 'GET /users',
                file: 'app/Http/Controllers/UserController.php',
                line: 42,
                controller: 'UserController@index',
            ),
            createdAt: new \DateTimeImmutable,
        ));
    }

    public function test_dashboard_returns_200_in_allowed_environment(): void
    {
        $this->get('/query-doctor')
            ->assertStatus(200);
    }

    public function test_dashboard_returns_403_when_env_not_allowed(): void
    {
        // Set env to production and remove it from allowed list
        $this->app['config']->set('app.env', 'production');
        $this->app['config']->set('query-doctor.allowed_environments', ['local', 'staging']);

        $this->get('/query-doctor')
            ->assertStatus(403);
    }

    public function test_dashboard_shows_no_issues_message(): void
    {
        $this->get('/query-doctor')
            ->assertStatus(200)
            ->assertSee('No issues found');
    }

    public function test_dashboard_shows_issues_when_present(): void
    {
        $this->seedIssue();

        $this->get('/query-doctor')
            ->assertStatus(200)
            ->assertSee('Slow query detected');
    }

    public function test_api_issues_returns_json(): void
    {
        $this->seedIssue();

        $response = $this->getJson('/query-doctor/api/issues');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'type', 'severity', 'confidence', 'title', 'description'],
                ],
                'meta' => ['total', 'page', 'per_page', 'last_page'],
                'filters',
            ])
            ->assertJsonPath('meta.total', 1);
    }

    public function test_api_issues_filters_by_severity(): void
    {
        $this->seedIssue('i-1', Severity::High);
        $this->seedIssue('i-2', Severity::Low);

        $response = $this->getJson('/query-doctor/api/issues?severity=high');

        $response->assertStatus(200)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_api_issues_pagination(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->seedIssue("issue-{$i}");
        }

        $response = $this->getJson('/query-doctor/api/issues?per_page=10&page=2');

        $response->assertStatus(200)
            ->assertJsonPath('meta.page', 2)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.last_page', 3);
    }

    public function test_api_queries_returns_json(): void
    {
        $response = $this->getJson('/query-doctor/api/queries');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data',
                'meta' => ['total', 'page', 'per_page', 'last_page'],
            ]);
    }

    public function test_api_create_baseline(): void
    {
        $this->seedIssue('i-1');
        $this->seedIssue('i-2');

        $response = $this->postJson('/query-doctor/api/baseline');

        $response->assertStatus(200)
            ->assertJsonPath('issues_baselined', 2)
            ->assertJsonStructure(['message', 'issues_baselined', 'created_at']);
    }

    public function test_api_ignore_issue(): void
    {
        $this->seedIssue('i-1');

        $response = $this->postJson('/query-doctor/api/ignore', [
            'issue_id' => 'i-1',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('issue_id', 'i-1');
    }

    public function test_api_ignore_issue_requires_id(): void
    {
        $response = $this->postJson('/query-doctor/api/ignore', []);

        $response->assertStatus(422)
            ->assertJsonPath('error', 'issue_id is required');
    }
}
