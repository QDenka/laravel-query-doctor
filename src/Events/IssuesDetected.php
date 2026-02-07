<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Events;

use QDenka\QueryDoctor\Domain\Issue;

final readonly class IssuesDetected
{
    /**
     * @param  Issue[]  $issues
     */
    public function __construct(
        public array $issues,
    ) {}
}
