<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Application;

use Illuminate\Support\Facades\Log;
use QDenka\QueryDoctor\Domain\Contracts\StorageInterface;
use QDenka\QueryDoctor\Domain\QueryEvent;

final class QueryCaptureService
{
    /** @var QueryEvent[] */
    private array $buffer = [];

    private bool $capturing = false;

    public function __construct(
        private readonly StorageInterface $storage,
    ) {}

    public function startCapture(): void
    {
        $this->capturing = true;
        $this->buffer = [];
    }

    public function stopCapture(): void
    {
        $this->capturing = false;
    }

    public function capture(QueryEvent $event): void
    {
        if (! $this->capturing) {
            return;
        }

        $this->buffer[] = $event;
    }

    /**
     * Flush buffered events to storage.
     * Called at end of request/job lifecycle.
     */
    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        try {
            $this->storage->storeEvents($this->buffer);
        } catch (\Throwable $e) {
            // Never crash the host app
            try {
                Log::warning('Query Doctor: Failed to flush events — '.$e->getMessage());
            } catch (\Throwable) {
                // Logger itself might fail in edge cases
            }
        }

        $this->buffer = [];
    }

    /**
     * Get currently buffered events (for immediate analysis without flushing).
     *
     * @return QueryEvent[]
     */
    public function bufferedEvents(): array
    {
        return $this->buffer;
    }

    public function isCapturing(): bool
    {
        return $this->capturing;
    }
}
