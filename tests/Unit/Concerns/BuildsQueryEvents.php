<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Tests\Unit\Concerns;

use QDenka\QueryDoctor\Domain\Enums\CaptureContext;
use QDenka\QueryDoctor\Domain\QueryEvent;

trait BuildsQueryEvents
{
    protected function makeEvent(
        string $sql = 'select * from users where id = ?',
        array $bindings = [1],
        float $timeMs = 1.0,
        string $connection = 'mysql',
        string $contextId = 'test-request-1',
        CaptureContext $context = CaptureContext::Http,
        ?string $route = 'GET /test',
        ?string $controller = 'TestController@index',
    ): QueryEvent {
        return new QueryEvent(
            sql: $sql,
            bindings: $bindings,
            timeMs: $timeMs,
            connection: $connection,
            contextId: $contextId,
            context: $context,
            route: $route,
            controller: $controller,
            stackExcerpt: [],
            timestamp: new \DateTimeImmutable,
        );
    }

    /**
     * Build N similar events differing only in bindings.
     * Simulates an N+1 pattern.
     *
     * @return QueryEvent[]
     */
    protected function makeNPlusOneEvents(
        int $count,
        string $sql = 'select * from posts where user_id = ?',
        string $contextId = 'req-1',
        float $timeMsEach = 2.0,
    ): array {
        return array_map(
            fn (int $i) => $this->makeEvent(
                sql: $sql,
                bindings: [$i],
                timeMs: $timeMsEach,
                contextId: $contextId,
            ),
            range(1, $count),
        );
    }

    /**
     * Build N events with identical SQL and bindings.
     * Simulates duplicate queries.
     *
     * @return QueryEvent[]
     */
    protected function makeDuplicateEvents(
        int $count,
        string $sql = "select * from settings where key = 'app.name'",
        string $contextId = 'req-1',
    ): array {
        return array_map(
            fn () => $this->makeEvent(
                sql: $sql,
                bindings: [],
                timeMs: 1.5,
                contextId: $contextId,
            ),
            range(1, $count),
        );
    }
}
