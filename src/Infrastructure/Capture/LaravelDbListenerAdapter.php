<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Infrastructure\Capture;

use Illuminate\Database\Events\QueryExecuted;
use QDenka\QueryDoctor\Domain\BindingMasker;
use QDenka\QueryDoctor\Domain\Contracts\EventSourceInterface;
use QDenka\QueryDoctor\Domain\Enums\CaptureContext;
use QDenka\QueryDoctor\Domain\QueryEvent;

final class LaravelDbListenerAdapter implements EventSourceInterface
{
    /** @var callable(QueryEvent): void|null */
    private $callback = null;

    private string $contextId = '';

    private CaptureContext $context = CaptureContext::Http;

    private ?string $route = null;

    private ?string $controller = null;

    public function __construct(
        private readonly int $stackDepth = 10,
        /** @var string[] */
        private readonly array $excludePaths = ['vendor/'],
        /** @var string[] */
        private readonly array $ignoreSqlPatterns = [],
        private readonly ?BindingMasker $masker = null,
    ) {}

    public function listen(callable $callback): void
    {
        $this->callback = $callback;
    }

    public function stop(): void
    {
        $this->callback = null;
    }

    /**
     * Set context for the current request/job/command.
     */
    public function setContext(string $contextId, CaptureContext $context, ?string $route = null, ?string $controller = null): void
    {
        $this->contextId = $contextId;
        $this->context = $context;
        $this->route = $route;
        $this->controller = $controller;
    }

    /**
     * Handle a QueryExecuted event from Laravel's DB listener.
     * Called from the service provider's DB::listen() hook.
     */
    public function handleQueryExecuted(QueryExecuted $queryExecuted): void
    {
        if ($this->callback === null) {
            return;
        }

        if ($this->shouldIgnore($queryExecuted->sql)) {
            return;
        }

        try {
            $bindings = $queryExecuted->bindings;

            // Apply PII masking before creating the event
            if ($this->masker !== null) {
                $bindings = $this->masker->mask($queryExecuted->sql, $bindings);
            }

            $event = new QueryEvent(
                sql: $queryExecuted->sql,
                bindings: $bindings,
                timeMs: $queryExecuted->time,
                connection: $queryExecuted->connectionName,
                contextId: $this->contextId ?: 'unknown',
                context: $this->context,
                route: $this->route,
                controller: $this->controller,
                stackExcerpt: $this->extractStack(),
                timestamp: new \DateTimeImmutable,
            );

            ($this->callback)($event);
        } catch (\Throwable) {
            // Never crash the host app. Silently skip this query.
        }
    }

    /**
     * @return array<int, array{file: string, line: int, class?: string, function?: string}>
     */
    private function extractStack(): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 50);
        $frames = [];

        foreach ($trace as $frame) {
            $file = $frame['file'] ?? '';

            if ($file === '' || $this->isExcludedPath($file)) {
                continue;
            }

            $entry = [
                'file' => $file,
                'line' => $frame['line'] ?? 0,
            ];

            if (isset($frame['class'])) {
                $entry['class'] = $frame['class'];
            }

            if ($frame['function'] !== '') {
                $entry['function'] = $frame['function'];
            }

            $frames[] = $entry;

            if (count($frames) >= $this->stackDepth) {
                break;
            }
        }

        return $frames;
    }

    private function isExcludedPath(string $file): bool
    {
        foreach ($this->excludePaths as $prefix) {
            if (str_contains($file, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function shouldIgnore(string $sql): bool
    {
        foreach ($this->ignoreSqlPatterns as $pattern) {
            if (preg_match($pattern, $sql) === 1) {
                return true;
            }
        }

        return false;
    }
}
