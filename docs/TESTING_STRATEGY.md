# Testing Strategy

How the test suite is organized and what each layer covers.

## Test Pyramid

```
        ┌──────────┐
        │  Golden   │  ← Snapshot tests with fixed SQL traces
        │  Tests    │
       ┌┴──────────┴┐
       │   Feature   │  ← CLI commands, dashboard routes, baseline flow
       │   Tests     │
      ┌┴────────────┴┐
      │  Integration  │  ← DB listener, EXPLAIN adapters, SQLite store
      │  Tests        │
     ┌┴──────────────┴┐
     │   Unit Tests    │  ← Domain logic: fingerprinting, analyzers, value objects
     └────────────────┘
```

## Unit Tests

**Location**: `tests/Unit/`
**Framework**: Plain PHPUnit (`\PHPUnit\Framework\TestCase`). No TestBench.

These test the domain layer in isolation. No database, no Laravel, no filesystem.

### What to Test

| Component | Key Scenarios |
|-----------|--------------|
| `QueryFingerprint` | Literal replacement, IN list collapse, keyword lowering, whitespace normalization, edge cases (subqueries, CASE WHEN, quoted identifiers, comments) |
| `QueryEvent` | Construction, immutability |
| `Issue` | Deterministic ID generation, severity/confidence values |
| `NPlusOneAnalyzer` | True positive (5+ similar queries), true negative (unrelated queries), boundary (exactly at threshold), confidence scoring |
| `DuplicateQueryAnalyzer` | Exact duplicates vs. same fingerprint with different bindings |
| `SlowQueryAnalyzer` | At threshold boundary, below threshold, severity escalation |
| `MissingIndexAnalyzer` | With EXPLAIN data, without EXPLAIN data, confidence calculation |
| `SelectStarAnalyzer` | SELECT * detected, explicit columns not flagged |

### Helpers

Use the `BuildsQueryEvents` trait (defined in `tests/Unit/Concerns/BuildsQueryEvents.php`) to construct test data:

```php
$events = $this->makeNPlusOneEvents(
    count: 10,
    sql: 'select * from posts where user_id = ?',
);

$issues = (new NPlusOneAnalyzer())->analyze($events);

$this->assertCount(1, $issues);
$this->assertSame(IssueType::NPlusOne, $issues[0]->type);
```

## Integration Tests

**Location**: `tests/Integration/`
**Framework**: Orchestra TestBench.

These test infrastructure adapters against real databases and file systems.

### What to Test

| Component | Key Scenarios |
|-----------|--------------|
| `LaravelDbListenerAdapter` | Capture fires on Eloquent query, builds correct QueryEvent, stack trace extraction |
| `SqliteStore` | Write events, read events, query by fingerprint, retention cleanup, concurrent write handling, schema creation on first use |
| `InMemoryStore` | Same interface behavior as SqliteStore but without persistence |
| `MysqlExplainAdapter` | EXPLAIN output parsing, error handling for un-EXPLAIN-able queries |
| `PostgresExplainAdapter` | EXPLAIN JSON parsing, plan type mapping |
| `AnalysisPipeline` | Full pipeline with multiple analyzers, deduplication |

### Database Setup

Integration tests use TestBench's built-in SQLite database for running actual queries. The package's internal SQLite store uses a separate `:memory:` database during tests.

## Feature Tests

**Location**: `tests/Feature/`
**Framework**: Orchestra TestBench.

These test the full stack: commands, routes, and workflows.

### What to Test

| Component | Key Scenarios |
|-----------|--------------|
| `DoctorReportCommand` | Table output, JSON output, Markdown output, filters, empty storage |
| `DoctorBaselineCommand` | Create baseline, clear baseline |
| `DoctorCiReportCommand` | Exit code 0 (no issues), exit code 1 (issues above threshold), exit code 2 (storage error), markdown output, baseline exclusion |
| Dashboard | Access allowed in `local`, blocked in `production`, custom middleware |
| Dashboard API | Issues endpoint pagination, filtering, sorting, JSON response format |
| Baseline flow | Create baseline → new issues flagged → baselined issues excluded |

### Seeding Test Data

Feature tests seed the storage with known query events before testing:

```php
protected function seedStorage(int $events = 10): void
{
    $store = $this->app->make(StorageInterface::class);
    foreach ($this->makeNPlusOneEvents($events, 'select * from posts where user_id = ?') as $event) {
        $store->storeEvent($event);
    }
}
```

## Golden Tests

**Location**: `tests/Fixtures/`
**Framework**: Plain PHPUnit or TestBench.

Golden tests compare analyzer output against known-good snapshots.

### Structure

```
tests/Fixtures/
├── traces/                        # Input: arrays of serialized QueryEvent data
│   ├── n_plus_one_users_posts.json
│   ├── duplicate_config_queries.json
│   ├── slow_report_query.json
│   ├── clean_no_issues.json
│   └── mixed_issues.json
└── expected/                      # Output: expected Issue arrays
    ├── n_plus_one_users_posts_issues.json
    ├── duplicate_config_queries_issues.json
    └── mixed_issues_issues.json
```

### How Golden Tests Work

1. Load a trace fixture (JSON file with serialized QueryEvent data).
2. Deserialize into `QueryEvent[]`.
3. Run through `AnalysisPipeline`.
4. Serialize the resulting `Issue[]` to JSON.
5. Compare with the expected output fixture.

If the comparison fails, the test shows a diff. To update snapshots after intentional changes, set `UPDATE_SNAPSHOTS=1` env var.

## Running Tests

```bash
# Everything
vendor/bin/phpunit

# By suite
vendor/bin/phpunit --testsuite=Unit
vendor/bin/phpunit --testsuite=Feature
vendor/bin/phpunit --testsuite=Integration

# By filter
vendor/bin/phpunit --filter=NPlusOne
vendor/bin/phpunit --filter=Fingerprint

# With coverage
XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-html=coverage
```

## CI Pipeline

Tests run in a matrix:
- PHP: 8.2, 8.3, 8.4
- Laravel: 10, 11, 12
- OS: Ubuntu latest

Plus:
- Larastan level 8
- Laravel Pint check

See `.github/workflows/ci.yml` for the full workflow.
