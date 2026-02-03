<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Domain;

use QDenka\QueryDoctor\Domain\Enums\CaptureContext;

final readonly class QueryEvent
{
    /**
     * @param  string  $sql  Raw SQL with ? placeholders
     * @param  array<int, mixed>  $bindings  Bound parameter values
     * @param  float  $timeMs  Execution time in milliseconds
     * @param  string  $connection  Database connection name
     * @param  string  $contextId  Groups queries by request/job/command
     * @param  CaptureContext  $context  Where this query ran
     * @param  string|null  $route  HTTP route pattern, null for non-HTTP
     * @param  string|null  $controller  Controller@method, null if not applicable
     * @param  array<int, array{file: string, line: int, class?: string, function?: string}>  $stackExcerpt  Filtered backtrace
     * @param  \DateTimeImmutable  $timestamp  When the query was captured
     */
    public function __construct(
        public string $sql,
        public array $bindings,
        public float $timeMs,
        public string $connection,
        public string $contextId,
        public CaptureContext $context,
        public ?string $route,
        public ?string $controller,
        public array $stackExcerpt,
        public \DateTimeImmutable $timestamp,
    ) {}
}
