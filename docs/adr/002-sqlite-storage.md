# ADR-002: SQLite for Internal Storage

**Status**: Accepted
**Date**: 2025-01-15

## Context

The package needs to store captured queries and detected issues across HTTP requests. Options considered:

1. **App's main database** — adds migrations to the host app, couples to their DB driver, pollutes their schema.
2. **File-based storage (JSON/CSV)** — no concurrency safety, slow for queries, no indexing.
3. **Redis** — fast, but requires Redis to be installed. Adds a hard dependency.
4. **Separate SQLite file** — zero dependencies, supports SQL queries, portable.

## Decision

Use a dedicated SQLite file at `storage/query-doctor.sqlite`. The package manages its own PDO connection, separate from Laravel's database connections. WAL (Write-Ahead Logging) mode handles concurrent reads and writes.

Fallback: if SQLite is unavailable (read-only filesystem, missing extension), degrade to `InMemoryStore` silently.

## Consequences

**Good**:
- Zero extra dependencies. SQLite ships with PHP.
- No impact on the host app's database or migrations.
- SQL queries for filtering, aggregation, and retention are straightforward.
- WAL mode allows parallel queue workers to write without blocking readers.
- Easy to inspect manually (`sqlite3 storage/query-doctor.sqlite`).

**Bad**:
- SQLite has write concurrency limits. Under very heavy load (many parallel workers), writes can queue up. Mitigated by `busy_timeout = 5000` and retry logic.
- Some hosting environments disable the SQLite PHP extension (rare, but possible). Mitigated by InMemoryStore fallback.
- The file can grow if retention cleanup doesn't run. Mitigated by automatic cleanup every N writes.
