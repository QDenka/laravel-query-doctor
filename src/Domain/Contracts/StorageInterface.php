<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Domain\Contracts;

use QDenka\QueryDoctor\Domain\Issue;
use QDenka\QueryDoctor\Domain\QueryEvent;

interface StorageInterface
{
    /**
     * Store a captured query event.
     */
    public function storeEvent(QueryEvent $event): void;

    /**
     * Store a batch of query events from one context.
     *
     * @param  QueryEvent[]  $events
     */
    public function storeEvents(array $events): void;

    /**
     * Retrieve query events, optionally filtered.
     *
     * @param  array<string, mixed>  $filters  Supported keys: context_id, fingerprint_hash, min_time, connection, period
     * @return QueryEvent[]
     */
    public function getEvents(array $filters = []): array;

    /**
     * Store a detected issue. Uses upsert: if the same issue ID exists,
     * update last_seen_at and increment occurrences.
     */
    public function storeIssue(Issue $issue): void;

    /**
     * Retrieve issues, optionally filtered.
     *
     * @param  array<string, mixed>  $filters  Supported keys: severity, type, route, period, is_ignored
     * @return Issue[]
     */
    public function getIssues(array $filters = []): array;

    /**
     * Mark an issue as ignored. Ignored issues are excluded from reports.
     */
    public function ignoreIssue(string $issueId): void;

    /**
     * Store current issues as a baseline snapshot.
     *
     * @return int Number of issues baselined
     */
    public function createBaseline(): int;

    /**
     * Remove the current baseline.
     */
    public function clearBaseline(): void;

    /**
     * Get all baselined issue IDs.
     *
     * @return string[]
     */
    public function getBaselinedIssueIds(): array;

    /**
     * Delete records older than the configured retention period.
     */
    public function cleanup(): void;
}
