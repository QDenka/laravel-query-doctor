# Analyzers

How each detector works, its rules, thresholds, and confidence scoring.

All analyzers implement `AnalyzerInterface`. They receive `QueryEvent[]` from one context (one HTTP request, one queue job, or one CLI command) and return `Issue[]`.

Analyzers are stateless. They don't read from storage or talk to the database. They work only with the data they're given.

## N+1 Detector

Finds the classic N+1 problem: a query runs inside a loop, once per related model.

### Signal

Same fingerprint appears many times in one context, with different bindings. Typically looks like:

```sql
-- Query 1:  select * from users
-- Query 2:  select * from posts where user_id = 1
-- Query 3:  select * from posts where user_id = 2
-- Query 4:  select * from posts where user_id = 3
-- ...47 more
```

### Rules

| Parameter | Config Key | Default | Description |
|-----------|-----------|---------|-------------|
| Min repetitions | `analyzers.n_plus_one.min_repetitions` | 5 | Fingerprint must appear at least this many times |
| Min total time | `analyzers.n_plus_one.min_total_ms` | 20 | Total time of all matching queries must exceed this |

Both conditions must be true to flag an issue.

### Confidence Formula

```
base = 0.6
repetition_bonus = min(0.3, (count - min_repetitions) / 50)
pattern_bonus = 0.1 if WHERE clause contains single-column equality (user_id = ?)
confidence = min(1.0, base + repetition_bonus + pattern_bonus)
```

- 5 repetitions with a `where user_id = ?` pattern: confidence 0.7
- 50 repetitions with the same pattern: confidence 1.0
- 5 repetitions without a clear relation pattern: confidence 0.6

### Severity

| Repetitions | Total Time | Severity |
|-------------|-----------|----------|
| >= 50 | >= 500ms | Critical |
| >= 20 | >= 200ms | High |
| >= 10 | >= 50ms | Medium |
| >= 5 | any | Low |

### Recommendation

```
Add eager loading: `->with('relation')` or `->load('relation')`.
If loading conditionally, use `->loadMissing('relation')`.
If the relation is deeply nested, use `->with('relation.subrelation')`.
```

## Duplicate Query Detector

Finds queries that run multiple times with identical SQL and bindings. Unlike N+1, these are exact duplicates — not just the same structure.

### Signal

```sql
-- Runs 4 times with identical SQL and bindings:
select * from settings where key = 'app.name'
select * from settings where key = 'app.name'
select * from settings where key = 'app.name'
select * from settings where key = 'app.name'
```

### Rules

| Parameter | Config Key | Default | Description |
|-----------|-----------|---------|-------------|
| Min duplicates | `analyzers.duplicate.min_count` | 3 | Same exact SQL must run at least this many times |

### Confidence

Always 1.0. This is a deterministic check — if the same SQL runs N times, it's a duplicate.

### Severity

| Count | Severity |
|-------|----------|
| >= 20 | High |
| >= 10 | Medium |
| >= 3 | Low |

### Recommendation

```
Cache the result: use `Cache::remember()`, a request-scoped singleton, or `once()`.
If this is a config lookup, consider loading all config values at once.
```

## Slow Query Detector

Flags queries that take too long.

### Signal

Any query where `timeMs >= threshold`.

### Rules

| Parameter | Config Key | Default | Description |
|-----------|-----------|---------|-------------|
| Threshold | `analyzers.slow.threshold_ms` | 100 | Queries slower than this are flagged |

### Confidence

Always 1.0. Time is measured, not estimated.

### Severity

| Time | Severity |
|------|----------|
| >= 5000ms | Critical |
| >= 1000ms | High |
| >= 500ms | Medium |
| >= threshold | Low |

### Recommendation

Depends on EXPLAIN data (if available):

- **Full table scan**: "Add an index on the columns in the WHERE clause."
- **Filesort**: "Add a composite index covering WHERE + ORDER BY columns."
- **Large row estimate**: "Consider adding a LIMIT or paginating the results."
- **No EXPLAIN data**: "Run EXPLAIN on this query manually to identify optimization opportunities."

## Missing Index Heuristic

Flags queries that likely run without an index.

### Signal

A query has:
1. A WHERE or ORDER BY clause.
2. Runs frequently (appears > `min_occurrences` times across all contexts).
3. Is slow (average time > `min_avg_ms`).
4. (Optional) EXPLAIN shows `type: ALL` (full scan) or high row estimate.

### Rules

| Parameter | Config Key | Default | Description |
|-----------|-----------|---------|-------------|
| Min occurrences | `analyzers.missing_index.min_occurrences` | 5 | Fingerprint must appear across multiple contexts |
| Min avg time | `analyzers.missing_index.min_avg_ms` | 50 | Average execution time must exceed this |

### Confidence

```
base = 0.3
explain_bonus = 0.4 if EXPLAIN shows full_scan
frequency_bonus = min(0.2, occurrences / 100)
time_bonus = 0.1 if avg_time > 200ms
confidence = min(1.0, base + explain_bonus + frequency_bonus + time_bonus)
```

- Without EXPLAIN: confidence 0.3–0.5 (it's a guess based on patterns)
- With EXPLAIN showing full scan: confidence 0.7–1.0

### Severity

| Confidence | Avg Time | Severity |
|-----------|----------|----------|
| >= 0.8 | >= 500ms | High |
| >= 0.5 | >= 100ms | Medium |
| any | any | Low |

### Recommendation

```
Consider adding an index:
  ALTER TABLE {table} ADD INDEX idx_{table}_{columns} ({columns});

Or in a migration:
  $table->index(['{columns}']);
```

Extracts table and column names from the WHERE clause using pattern matching.

## Select Star Detector

Flags `SELECT *` queries that fetch all columns when you probably don't need them.

### Signal

Query uses `SELECT *` (or `SELECT table.*`) and:
1. The table has many columns (if schema info available), OR
2. The query runs frequently (> `min_occurrences`).

### Rules

| Parameter | Config Key | Default | Description |
|-----------|-----------|---------|-------------|
| Min occurrences | `analyzers.select_star.min_occurrences` | 3 | Must appear at least this many times |
| Enabled | `analyzers.select_star.enabled` | true | Can be disabled (some teams prefer SELECT *) |

### Confidence

```
base = 0.4
frequency_bonus = min(0.3, occurrences / 20)
time_bonus = 0.2 if total_time > 100ms
join_bonus = 0.1 if query has JOIN (fetching all columns from joined tables is worse)
confidence = min(1.0, base + frequency_bonus + time_bonus + join_bonus)
```

### Severity

Always `Low` unless it's a JOIN with high frequency, then `Medium`.

### Recommendation

```
Specify only the columns you need:
  ->select(['id', 'name', 'email'])

This reduces memory usage and network transfer, especially for tables with
TEXT/BLOB columns or many columns.
```

## Adding Custom Analyzers

See [EXTENSION_API.md](EXTENSION_API.md) for how to register your own analyzer.
