<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Domain\Contracts;

use QDenka\QueryDoctor\Domain\QueryEvent;

interface EventSourceInterface
{
    /**
     * Start capturing query events. Call $callback for each captured query.
     *
     * @param  callable(QueryEvent): void  $callback  Invoked for each query event
     */
    public function listen(callable $callback): void;

    /**
     * Stop capturing query events.
     */
    public function stop(): void;
}
