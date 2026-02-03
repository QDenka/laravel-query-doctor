<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Domain;

final readonly class SourceContext
{
    public function __construct(
        public ?string $route,
        public ?string $file,
        public ?int $line,
        public ?string $controller,
    ) {}
}
