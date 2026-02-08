<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Tests\Integration\Storage;

use PHPUnit\Framework\TestCase;
use QDenka\QueryDoctor\Domain\Contracts\StorageInterface;
use QDenka\QueryDoctor\Infrastructure\Storage\InMemoryStore;
use QDenka\QueryDoctor\Tests\Integration\Concerns\StorageContractTests;

final class InMemoryStoreTest extends TestCase
{
    use StorageContractTests;

    protected function createStore(): StorageInterface
    {
        return new InMemoryStore;
    }

    public function test_events_are_isolated_per_instance(): void
    {
        $store1 = new InMemoryStore;
        $store2 = new InMemoryStore;

        $store1->storeEvent($this->makeEvent());

        $this->assertCount(1, $store1->getEvents());
        $this->assertCount(0, $store2->getEvents());
    }

    public function test_store_issue_overwrites_same_id(): void
    {
        $store = new InMemoryStore;
        $store->storeIssue($this->makeIssue(id: 'i-1'));
        $store->storeIssue($this->makeIssue(id: 'i-1'));

        // InMemoryStore replaces by key, so only 1 issue
        $this->assertCount(1, $store->getIssues());
    }

    public function test_cleanup_does_not_clear_events(): void
    {
        $store = new InMemoryStore;
        $store->storeEvent($this->makeEvent());
        $store->cleanup();

        // InMemoryStore cleanup is a no-op — data lives for the request only
        $this->assertCount(1, $store->getEvents());
    }
}
