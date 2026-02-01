# Database Schema

SQLite tables used internally by the package. This is a separate SQLite file — not the app's main database.

Default location: `storage/query-doctor.sqlite`

## Tables

### doctor_runs

Tracks each capture context (HTTP request, queue job, CLI command).

```sql
CREATE TABLE doctor_runs (
    id          TEXT PRIMARY KEY,           -- UUID, same as contextId
    context     TEXT NOT NULL,              -- 'http', 'queue', 'cli'
    route       TEXT,                       -- HTTP route or null
    controller  TEXT,                       -- Controller@method or null
    job_class   TEXT,                       -- Queue job class or null
    command     TEXT,                       -- Artisan command name or null
    query_count INTEGER NOT NULL DEFAULT 0, -- Total queries in this run
    total_ms    REAL NOT NULL DEFAULT 0,    -- Sum of all query times
    created_at  TEXT NOT NULL               -- ISO 8601 timestamp
);

CREATE INDEX idx_runs_context ON doctor_runs(context);
CREATE INDEX idx_runs_route ON doctor_runs(route);
CREATE INDEX idx_runs_created_at ON doctor_runs(created_at);
```

### doctor_query_events

Every captured SQL query.

```sql
CREATE TABLE doctor_query_events (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    run_id          TEXT NOT NULL,              -- FK to doctor_runs.id
    sql             TEXT NOT NULL,              -- Raw SQL with ? placeholders
    bindings_hash   TEXT,                       -- SHA-256 of serialized bindings (not the values themselves)
    time_ms         REAL NOT NULL,              -- Execution time in milliseconds
    connection      TEXT NOT NULL,              -- Database connection name
    fingerprint     TEXT NOT NULL,              -- Normalized SQL (see DOMAIN_MODEL.md)
    fingerprint_hash TEXT NOT NULL,             -- SHA-256 of fingerprint for indexing
    stack_excerpt   TEXT,                       -- JSON array of stack frames
    created_at      TEXT NOT NULL,              -- ISO 8601 timestamp

    FOREIGN KEY (run_id) REFERENCES doctor_runs(id) ON DELETE CASCADE
);

CREATE INDEX idx_events_run_id ON doctor_query_events(run_id);
CREATE INDEX idx_events_fingerprint_hash ON doctor_query_events(fingerprint_hash);
CREATE INDEX idx_events_time_ms ON doctor_query_events(time_ms);
CREATE INDEX idx_events_connection ON doctor_query_events(connection);
CREATE INDEX idx_events_created_at ON doctor_query_events(created_at);
```

**Why `bindings_hash` instead of raw bindings?**

Bindings may contain PII (emails, names, tokens). We store a hash so we can detect duplicate queries (same SQL + same bindings = exact duplicate) without keeping the actual values. The raw bindings are held only in memory during the request and discarded.

### doctor_issues

Detected problems.

```sql
CREATE TABLE doctor_issues (
    id              TEXT PRIMARY KEY,          -- Deterministic hash (see DOMAIN_MODEL.md)
    type            TEXT NOT NULL,             -- IssueType enum value
    severity        TEXT NOT NULL,             -- Severity enum value
    confidence      REAL NOT NULL,             -- 0.0 to 1.0
    title           TEXT NOT NULL,             -- One-line summary
    description     TEXT NOT NULL,             -- Full description
    fingerprint_hash TEXT NOT NULL,            -- Links to the query pattern
    evidence_json   TEXT NOT NULL,             -- JSON: {query_count, total_time_ms, sample_sql, ...}
    recommendation_json TEXT NOT NULL,         -- JSON: {action, code, docs_url}
    source_route    TEXT,                      -- Route where issue was detected
    source_file     TEXT,                      -- File path
    source_line     INTEGER,                   -- Line number
    source_controller TEXT,                    -- Controller@method
    is_ignored      INTEGER NOT NULL DEFAULT 0,-- 1 if manually ignored
    first_seen_at   TEXT NOT NULL,             -- ISO 8601
    last_seen_at    TEXT NOT NULL,             -- ISO 8601
    occurrences     INTEGER NOT NULL DEFAULT 1 -- How many runs triggered this

);

CREATE INDEX idx_issues_type ON doctor_issues(type);
CREATE INDEX idx_issues_severity ON doctor_issues(severity);
CREATE INDEX idx_issues_fingerprint ON doctor_issues(fingerprint_hash);
CREATE INDEX idx_issues_last_seen ON doctor_issues(last_seen_at);
CREATE INDEX idx_issues_ignored ON doctor_issues(is_ignored);
```

**Upsert behavior**: When the same issue ID is detected again, update `last_seen_at`, increment `occurrences`, and update severity/confidence if changed.

### doctor_baselines

Snapshot of known issues. Issues matching a baseline entry are excluded from CI failure checks.

```sql
CREATE TABLE doctor_baselines (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    issue_id        TEXT NOT NULL,             -- The issue ID that was baselined
    fingerprint_hash TEXT NOT NULL,            -- For quick lookup
    type            TEXT NOT NULL,             -- Issue type at time of baseline
    created_at      TEXT NOT NULL              -- When baseline was created
);

CREATE UNIQUE INDEX idx_baselines_issue ON doctor_baselines(issue_id);
CREATE INDEX idx_baselines_fingerprint ON doctor_baselines(fingerprint_hash);
```

## Migration Strategy

The package doesn't use Laravel's migration system for its internal SQLite database. Instead:

1. On first use, `SqliteStore` checks if tables exist.
2. If not, it runs the CREATE TABLE statements directly.
3. Schema version is tracked in a `doctor_meta` table:

```sql
CREATE TABLE doctor_meta (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

-- Initial entry
INSERT INTO doctor_meta (key, value) VALUES ('schema_version', '1');
```

4. On package update, `SqliteStore` compares the stored `schema_version` with the expected version and runs migration steps if needed.
5. Migrations are incremental ALTER TABLE / CREATE INDEX statements, not full rebuilds.

This approach avoids polluting the host app's migration history.

## Retention

The `SqliteStore` runs cleanup on a schedule (or on every Nth write):

```sql
DELETE FROM doctor_query_events WHERE created_at < datetime('now', '-{retention_days} days');
DELETE FROM doctor_runs WHERE created_at < datetime('now', '-{retention_days} days');
DELETE FROM doctor_issues WHERE last_seen_at < datetime('now', '-{retention_days} days') AND is_ignored = 0;
```

Default `retention_days`: 14. Configurable via `query-doctor.storage.retention_days`.

## SQLite Configuration

Set on every connection open:

```sql
PRAGMA journal_mode = WAL;       -- concurrent reads during writes
PRAGMA busy_timeout = 5000;      -- wait up to 5s for locks
PRAGMA synchronous = NORMAL;     -- faster writes, acceptable durability
PRAGMA cache_size = -2000;       -- 2MB in-memory cache
PRAGMA foreign_keys = ON;
PRAGMA temp_store = MEMORY;
```

See [ADR-002](adr/002-sqlite-storage.md) for rationale.
