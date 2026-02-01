# Domain Model

Definitions and specs for every domain object in the package.

## QueryEvent

A single captured SQL query with its context.

| Property | Type | Description |
|----------|------|-------------|
| `sql` | `string` | Raw SQL as executed (with `?` placeholders) |
| `bindings` | `array` | Bound parameter values |
| `timeMs` | `float` | Execution time in milliseconds |
| `connection` | `string` | Database connection name (e.g. `mysql`, `pgsql`) |
| `contextId` | `string` | Groups queries by request/job/command. UUID per context. |
| `context` | `CaptureContext` | Where this query ran: `http`, `queue`, or `cli` |
| `route` | `?string` | HTTP route (e.g. `GET /api/users`). Null for non-HTTP. |
| `controller` | `?string` | Controller class and method. Null if not applicable. |
| `stackExcerpt` | `array` | Filtered backtrace frames. Each: `{file, line, class, function}` |
| `timestamp` | `DateTimeImmutable` | When the query was captured |

### Construction

```php
$event = new QueryEvent(
    sql: 'select * from users where id = ?',
    bindings: [42],
    timeMs: 3.2,
    connection: 'mysql',
    contextId: 'req-abc123',
    context: CaptureContext::Http,
    route: 'GET /api/users/42',
    controller: 'App\\Http\\Controllers\\UserController@show',
    stackExcerpt: [
        ['file' => 'app/Http/Controllers/UserController.php', 'line' => 28, 'class' => 'UserController', 'function' => 'show'],
    ],
    timestamp: new \DateTimeImmutable(),
);
```

Immutable. No setters. Created once by the capture adapter.

## QueryFingerprint

A normalized version of a SQL query that strips away specific values. Two queries with the same fingerprint are structurally identical — they differ only in their bound parameters.

| Property | Type | Description |
|----------|------|-------------|
| `value` | `string` | The normalized SQL string |
| `hash` | `string` | SHA-256 hash of `value` (for storage/indexing) |

### Normalization Algorithm

Given raw SQL, apply these steps in order:

1. **Strip string literals**: Replace `'...'` and `"..."` with `?`. Handle escaped quotes (`\'`, `\"`).
2. **Strip numeric literals**: Replace standalone numbers (integers, floats) with `?`. Don't touch numbers inside identifiers (`table_2`).
3. **Collapse IN lists**: `IN (?, ?, ?, ?)` → `IN (?)`. Any number of `?` placeholders inside `IN (...)` becomes a single `?`.
4. **Lowercase keywords**: `SELECT`, `FROM`, `WHERE` etc. → `select`, `from`, `where`.
5. **Collapse whitespace**: Multiple spaces, tabs, newlines → single space.
6. **Trim**: Remove leading/trailing whitespace.

### Examples

| Input | Fingerprint |
|-------|------------|
| `SELECT * FROM users WHERE id = 42` | `select * from users where id = ?` |
| `SELECT * FROM users WHERE id = 99` | `select * from users where id = ?` |
| `SELECT * FROM users WHERE name = 'John'` | `select * from users where name = ?` |
| `SELECT * FROM posts WHERE user_id IN (1, 2, 3)` | `select * from posts where user_id in (?)` |
| `SELECT * FROM posts WHERE user_id IN (1, 2, 3, 4, 5)` | `select * from posts where user_id in (?)` |

### Edge Cases

- **Subqueries**: Normalized recursively. `WHERE id IN (SELECT id FROM ...)` keeps the subquery structure but normalizes its literals.
- **CASE WHEN**: `CASE WHEN x = 1 THEN 'a' ELSE 'b' END` → `case when x = ? then ? else ? end`
- **Table aliases**: Preserved as-is. `users u` stays `users u`.
- **Quoted identifiers**: `` `table_name` `` and `"table_name"` are preserved (they're identifiers, not string literals).
- **Comments**: `-- ...` and `/* ... */` are stripped.

## Issue

A detected problem with its evidence and recommendation.

| Property | Type | Description |
|----------|------|-------------|
| `id` | `string` | Deterministic ID: hash of `type + fingerprint.hash + contextId` |
| `type` | `IssueType` | Which analyzer found this |
| `severity` | `Severity` | `low`, `medium`, `high`, `critical` |
| `confidence` | `float` | 0.0 to 1.0. How certain the analyzer is. |
| `title` | `string` | One-line summary (e.g. "N+1: User → posts relationship") |
| `description` | `string` | Explanation of what's wrong |
| `evidence` | `Evidence` | Supporting data (queries, counts, timings) |
| `recommendation` | `Recommendation` | What to do about it |
| `sourceContext` | `?SourceContext` | Route, file, line where the issue originates |
| `createdAt` | `DateTimeImmutable` | When first detected |

### Issue ID

The ID is deterministic so the same problem detected across multiple runs produces the same ID. This is how baselines work — they store issue IDs and skip known issues.

```php
$id = hash('sha256', $type->value . ':' . $fingerprint->hash . ':' . $contextId);
```

## Evidence

Supporting data attached to an issue.

| Property | Type | Description |
|----------|------|-------------|
| `queries` | `QueryEvent[]` | The queries that triggered this issue (up to 10 examples) |
| `queryCount` | `int` | Total number of matching queries |
| `totalTimeMs` | `float` | Sum of all matching query times |
| `fingerprint` | `QueryFingerprint` | The normalized query pattern |
| `explainResult` | `?ExplainResult` | EXPLAIN output if available |

## Recommendation

Actionable fix suggestion.

| Property | Type | Description |
|----------|------|-------------|
| `action` | `string` | What to do (e.g. "Add eager loading") |
| `code` | `?string` | Suggested code change (e.g. `->with('posts')`) |
| `docsUrl` | `?string` | Link to relevant Laravel docs |

## ExplainResult

Structured EXPLAIN output, normalized across MySQL and Postgres.

| Property | Type | Description |
|----------|------|-------------|
| `scanType` | `string` | `full_scan`, `index_scan`, `range_scan`, `ref`, `const` |
| `possibleKeys` | `string[]` | Indexes that could be used |
| `usedKey` | `?string` | Index actually used |
| `estimatedRows` | `int` | Estimated row count |
| `extra` | `string[]` | Additional info (e.g. "Using filesort", "Using temporary") |
| `raw` | `array` | Original EXPLAIN output for debugging |

## Enums

### Severity
```php
enum Severity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';
}
```

### IssueType
```php
enum IssueType: string
{
    case NPlusOne = 'n_plus_one';
    case Duplicate = 'duplicate';
    case Slow = 'slow';
    case MissingIndex = 'missing_index';
    case SelectStar = 'select_star';
}
```

### CaptureContext
```php
enum CaptureContext: string
{
    case Http = 'http';
    case Queue = 'queue';
    case Cli = 'cli';
}
```

## SourceContext

Where in the host app the issue originates.

| Property | Type | Description |
|----------|------|-------------|
| `route` | `?string` | HTTP route pattern |
| `file` | `?string` | PHP file path (relative to project root) |
| `line` | `?int` | Line number in the file |
| `controller` | `?string` | Controller class and method |
