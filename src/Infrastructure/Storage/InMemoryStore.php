<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Infrastructure\Storage;

use QDenka\QueryDoctor\Domain\Contracts\StorageInterface;
use QDenka\QueryDoctor\Domain\Issue;
use QDenka\QueryDoctor\Domain\QueryEvent;

final class InMemoryStore implements StorageInterface
{
    /** @var QueryEvent[] */
    private array $events = [];

    /** @var array<string, Issue> */
    private array $issues = [];

    /** @var array<string, true> */
    private array $baselinedIds = [];

    /** @var array<string, true> */
    private array $ignoredIds = [];

    public function storeEvent(QueryEvent $event): void
    {
        $this->events[] = $event;
    }

    public function storeEvents(array $events): void
    {
        foreach ($events as $event) {
            $this->storeEvent($event);
        }
    }

    public function getEvents(array $filters = []): array
    {
        $result = $this->events;

        if (isset($filters['context_id'])) {
            $result = array_filter($result, static fn (QueryEvent $e) => $e->contextId === $filters['context_id']);
        }

        if (isset($filters['fingerprint_hash'])) {
            $result = array_filter($result, static function (QueryEvent $e) use ($filters) {
                $fp = \QDenka\QueryDoctor\Domain\QueryFingerprint::fromSql($e->sql);

                return $fp->hash === $filters['fingerprint_hash'];
            });
        }

        if (isset($filters['min_time'])) {
            $minTime = (float) $filters['min_time'];
            $result = array_filter($result, static fn (QueryEvent $e) => $e->timeMs >= $minTime);
        }

        if (isset($filters['connection'])) {
            $result = array_filter($result, static fn (QueryEvent $e) => $e->connection === $filters['connection']);
        }

        return array_values($result);
    }

    public function storeIssue(Issue $issue): void
    {
        $this->issues[$issue->id] = $issue;
    }

    public function getIssues(array $filters = []): array
    {
        $result = $this->issues;

        if (isset($filters['severity'])) {
            $result = array_filter($result, static fn (Issue $i) => $i->severity->value === $filters['severity']);
        }

        if (isset($filters['type'])) {
            $result = array_filter($result, static fn (Issue $i) => $i->type->value === $filters['type']);
        }

        if (($filters['is_ignored'] ?? null) === false) {
            $ignoredIds = $this->ignoredIds;
            $result = array_filter($result, static fn (Issue $i) => ! isset($ignoredIds[$i->id]));
        }

        return array_values($result);
    }

    public function ignoreIssue(string $issueId): void
    {
        $this->ignoredIds[$issueId] = true;
    }

    public function createBaseline(): int
    {
        $this->baselinedIds = [];

        foreach ($this->issues as $issue) {
            $this->baselinedIds[$issue->id] = true;
        }

        return count($this->baselinedIds);
    }

    public function clearBaseline(): void
    {
        $this->baselinedIds = [];
    }

    public function getBaselinedIssueIds(): array
    {
        return array_keys($this->baselinedIds);
    }

    public function cleanup(): void
    {
        // No retention in memory — data is lost when the request ends anyway
    }
}
