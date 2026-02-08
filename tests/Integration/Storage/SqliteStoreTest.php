<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Tests\Integration\Storage;

use PHPUnit\Framework\TestCase;
use QDenka\QueryDoctor\Domain\Contracts\StorageInterface;
use QDenka\QueryDoctor\Domain\Enums\Severity;
use QDenka\QueryDoctor\Infrastructure\Storage\SqliteStore;
use QDenka\QueryDoctor\Tests\Integration\Concerns\StorageContractTests;

final class SqliteStoreTest extends TestCase
{
    use StorageContractTests;

    protected function createStore(): StorageInterface
    {
        // Use in-memory SQLite for fast isolated tests
        return new SqliteStore(':memory:');
    }

    public function test_schema_is_created_automatically(): void
    {
        $store = new SqliteStore(':memory:');
        $store->storeEvent($this->makeEvent());

        // If we got here without PDO exceptions, schema was created
        $this->assertCount(1, $store->getEvents());
    }

    public function test_store_issue_upserts_on_same_id(): void
    {
        $store = new SqliteStore(':memory:');

        $store->storeIssue($this->makeIssue(id: 'i-1', severity: Severity::Medium));
        $store->storeIssue($this->makeIssue(id: 'i-1', severity: Severity::Critical));

        $issues = $store->getIssues();
        $this->assertCount(1, $issues);
        // Upsert updates severity
        $this->assertSame(Severity::Critical, $issues[0]->severity);
    }

    public function test_ignore_issue_excludes_from_get_issues(): void
    {
        $store = new SqliteStore(':memory:');
        $store->storeIssue($this->makeIssue(id: 'i-1'));
        $store->storeIssue($this->makeIssue(id: 'i-2'));

        $store->ignoreIssue('i-1');

        // SqliteStore excludes ignored issues by default (WHERE is_ignored = 0)
        $issues = $store->getIssues();
        $this->assertCount(1, $issues);
        $this->assertSame('i-2', $issues[0]->id);
    }

    public function test_filter_issues_by_route(): void
    {
        $store = new SqliteStore(':memory:');
        $store->storeIssue($this->makeIssue(id: 'i-1'));
        $store->storeIssue($this->makeIssue(id: 'i-2'));

        // Both have route 'GET /users', filter with wildcard
        $issues = $store->getIssues(['route' => 'GET /users']);
        $this->assertCount(2, $issues);

        // Non-matching route
        $issues = $store->getIssues(['route' => 'POST /admin*']);
        $this->assertCount(0, $issues);
    }

    public function test_issues_ordered_by_severity(): void
    {
        $store = new SqliteStore(':memory:');
        $store->storeIssue($this->makeIssue(id: 'i-low', severity: Severity::Low));
        $store->storeIssue($this->makeIssue(id: 'i-crit', severity: Severity::Critical));
        $store->storeIssue($this->makeIssue(id: 'i-med', severity: Severity::Medium));
        $store->storeIssue($this->makeIssue(id: 'i-high', severity: Severity::High));

        $issues = $store->getIssues();

        $this->assertSame('i-crit', $issues[0]->id);
        $this->assertSame('i-high', $issues[1]->id);
        $this->assertSame('i-med', $issues[2]->id);
        $this->assertSame('i-low', $issues[3]->id);
    }

    public function test_multiple_events_across_different_runs(): void
    {
        $store = new SqliteStore(':memory:');

        $store->storeEvent($this->makeEvent(contextId: 'run-a', sql: 'select 1'));
        $store->storeEvent($this->makeEvent(contextId: 'run-a', sql: 'select 2'));
        $store->storeEvent($this->makeEvent(contextId: 'run-b', sql: 'select 3'));

        $this->assertCount(3, $store->getEvents());
        $this->assertCount(2, $store->getEvents(['context_id' => 'run-a']));
        $this->assertCount(1, $store->getEvents(['context_id' => 'run-b']));
    }

    public function test_store_events_batch_is_atomic(): void
    {
        $store = new SqliteStore(':memory:');

        $events = [
            $this->makeEvent(sql: 'select 1', contextId: 'batch-1'),
            $this->makeEvent(sql: 'select 2', contextId: 'batch-1'),
        ];

        $store->storeEvents($events);
        $this->assertCount(2, $store->getEvents());
    }

    public function test_filter_events_by_fingerprint_hash(): void
    {
        $store = new SqliteStore(':memory:');

        $store->storeEvent($this->makeEvent(sql: 'select * from users where id = 1'));
        $store->storeEvent($this->makeEvent(sql: 'select * from posts where id = 1'));

        $fingerprint = \QDenka\QueryDoctor\Domain\QueryFingerprint::fromSql('select * from users where id = 1');
        $events = $store->getEvents(['fingerprint_hash' => $fingerprint->hash]);

        $this->assertCount(1, $events);
    }

    public function test_create_baseline_replaces_previous(): void
    {
        $store = new SqliteStore(':memory:');

        $store->storeIssue($this->makeIssue(id: 'i-1'));
        $store->createBaseline();
        $this->assertCount(1, $store->getBaselinedIssueIds());

        // Add another issue and re-baseline
        $store->storeIssue($this->makeIssue(id: 'i-2'));
        $count = $store->createBaseline();

        // Should include both issues now
        $this->assertSame(2, $count);
        $this->assertCount(2, $store->getBaselinedIssueIds());
    }
}
