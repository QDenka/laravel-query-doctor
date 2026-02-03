<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Domain\Contracts;

use QDenka\QueryDoctor\Domain\ExplainResult;

interface ExplainInterface
{
    /**
     * Run EXPLAIN on a query and return structured results.
     *
     * @param  string  $sql  The SQL query (with ? placeholders)
     * @param  array<int, mixed>  $bindings  Bound parameter values
     * @param  string  $connection  Database connection name
     * @return ExplainResult|null Null if EXPLAIN failed or is not supported
     */
    public function explain(string $sql, array $bindings, string $connection): ?ExplainResult;

    /**
     * Check if this adapter supports the given database driver.
     *
     * @param  string  $driver  Database driver name (mysql, pgsql, sqlite, etc.)
     */
    public function supports(string $driver): bool;
}
