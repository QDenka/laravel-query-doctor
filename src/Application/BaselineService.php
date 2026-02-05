<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Application;

use QDenka\QueryDoctor\Domain\Contracts\StorageInterface;
use QDenka\QueryDoctor\Domain\Issue;

final class BaselineService
{
    public function __construct(
        private readonly StorageInterface $storage,
    ) {}

    /**
     * Create a baseline from all current issues.
     *
     * @return int Number of issues baselined
     */
    public function create(): int
    {
        return $this->storage->createBaseline();
    }

    /**
     * Remove the current baseline.
     */
    public function clear(): void
    {
        $this->storage->clearBaseline();
    }

    /**
     * Filter out baselined issues from a list.
     *
     * @param  Issue[]  $issues
     * @return Issue[]
     */
    public function filterBaselined(array $issues): array
    {
        $baselinedIds = $this->storage->getBaselinedIssueIds();

        if ($baselinedIds === []) {
            return $issues;
        }

        $baselinedSet = array_flip($baselinedIds);

        return array_values(array_filter(
            $issues,
            static fn (Issue $issue) => ! isset($baselinedSet[$issue->id]),
        ));
    }
}
