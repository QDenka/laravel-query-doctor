<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Events;

final readonly class BaselineCreated
{
    public function __construct(
        public int $issueCount,
    ) {}
}
