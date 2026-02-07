<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Events;

use QDenka\QueryDoctor\Domain\Enums\CaptureContext;

final readonly class CaptureStarted
{
    public function __construct(
        public string $contextId,
        public CaptureContext $context,
    ) {}
}
