# API Specification

All HTTP endpoints and CLI commands with their parameters and response formats.

## Dashboard HTTP Endpoints

All routes are prefixed with `/query-doctor` and protected by the `QueryDoctorMiddleware` (environment check + optional auth gate).

### GET /query-doctor

Renders the main dashboard HTML page.

**Response**: Blade view with summary data passed as view variables.

Not an API endpoint — returns HTML.

---

### GET /query-doctor/api/issues

Paginated list of detected issues.

**Query Parameters**:

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `page` | int | 1 | Page number |
| `per_page` | int | 25 | Items per page (max 100) |
| `severity` | string | all | Filter: `low`, `medium`, `high`, `critical` |
| `type` | string | all | Filter: `n_plus_one`, `duplicate`, `slow`, `missing_index`, `select_star` |
| `route` | string | — | Filter by route pattern (supports `*` wildcard) |
| `period` | string | 24h | Time window: `1h`, `6h`, `24h`, `7d`, `30d` |
| `connection` | string | — | Filter by database connection name |
| `sort` | string | severity | Sort by: `severity`, `time`, `count`, `created_at` |
| `direction` | string | desc | Sort direction: `asc`, `desc` |

**Response** (200):

```json
{
    "data": [
        {
            "id": "a1b2c3d4...",
            "type": "n_plus_one",
            "severity": "high",
            "confidence": 0.85,
            "title": "N+1: select * from posts where user_id = ?",
            "description": "This query runs 47 times in a single request...",
            "evidence": {
                "query_count": 47,
                "total_time_ms": 234.5,
                "fingerprint": "select * from posts where user_id = ?",
                "sample_sql": "select * from posts where user_id = 12",
                "sample_bindings": [12]
            },
            "recommendation": {
                "action": "Add eager loading",
                "code": "->with('posts')",
                "docs_url": "https://laravel.com/docs/eloquent-relationships#eager-loading"
            },
            "source_context": {
                "route": "GET /api/users",
                "file": "app/Http/Controllers/UserController.php",
                "line": 28,
                "controller": "UserController@index"
            },
            "is_baselined": false,
            "created_at": "2025-01-15T14:30:00Z"
        }
    ],
    "meta": {
        "total": 42,
        "page": 1,
        "per_page": 25,
        "last_page": 2
    },
    "filters": {
        "severity": null,
        "type": null,
        "period": "24h"
    }
}
```

---

### GET /query-doctor/api/queries

Raw captured query events.

**Query Parameters**:

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `page` | int | 1 | Page number |
| `per_page` | int | 50 | Items per page (max 200) |
| `fingerprint` | string | — | Filter by fingerprint hash |
| `context_id` | string | — | Filter by context (request/job) ID |
| `min_time` | float | — | Minimum execution time in ms |
| `connection` | string | — | Filter by database connection |
| `period` | string | 24h | Time window |

**Response** (200):

```json
{
    "data": [
        {
            "sql": "select * from users where id = ?",
            "bindings_sanitized": ["[MASKED]"],
            "time_ms": 3.2,
            "connection": "mysql",
            "context_id": "req-abc123",
            "context": "http",
            "route": "GET /api/users/42",
            "fingerprint": "select * from users where id = ?",
            "timestamp": "2025-01-15T14:30:00.123Z"
        }
    ],
    "meta": {
        "total": 1250,
        "page": 1,
        "per_page": 50,
        "last_page": 25
    }
}
```

Note: Bindings are sanitized. Potential PII values are replaced with `[MASKED]`.

---

### POST /query-doctor/api/baseline

Create a baseline from current issues.

**Request body**: None (takes a snapshot of all current issues).

**Response** (200):

```json
{
    "message": "Baseline created",
    "issues_baselined": 15,
    "created_at": "2025-01-15T14:30:00Z"
}
```

---

### POST /query-doctor/api/ignore

Ignore a specific issue.

**Request body**:

```json
{
    "issue_id": "a1b2c3d4..."
}
```

**Response** (200):

```json
{
    "message": "Issue ignored",
    "issue_id": "a1b2c3d4..."
}
```

---

## CLI Commands

### doctor:report

```
php artisan doctor:report [options]
```

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `--period` | string | 24h | Time window: `1h`, `6h`, `24h`, `7d`, `30d` |
| `--format` | string | table | Output format: `table`, `json`, `md` |
| `--severity` | string | — | Filter by minimum severity |
| `--type` | string | — | Filter by issue type |
| `--route` | string | — | Filter by route pattern |
| `--output` | string | — | Write to file instead of stdout |

**Exit codes**: 0 (always — this is a report, not a check).

**Table output example**:
```
+-------------+----------+----------------+----------------------------------------+-------+--------+
| Type        | Severity | Route          | Query                                  | Count | Time   |
+-------------+----------+----------------+----------------------------------------+-------+--------+
| n_plus_one  | high     | GET /api/users | select * from posts where user_id = ?  | 47    | 234ms  |
| duplicate   | medium   | GET /dashboard | select * from settings where key = ?   | 12    | 18ms   |
| slow        | high     | GET /reports   | select * from orders where created...  | 1     | 3400ms |
+-------------+----------+----------------+----------------------------------------+-------+--------+

Found 3 issues (0 critical, 2 high, 1 medium, 0 low)
```

### doctor:baseline

```
php artisan doctor:baseline [action] [options]
```

| Argument/Option | Type | Default | Description |
|----------------|------|---------|-------------|
| `action` | string | create | `create` or `clear` |
| `--clear` | flag | — | Alternative way to clear baseline |

**Exit codes**: 0 on success, 2 on storage error.

### doctor:ci-report

```
php artisan doctor:ci-report [options]
```

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `--fail-on` | string | high | Minimum severity that triggers failure: `low`, `medium`, `high`, `critical` |
| `--output` | string | — | Write report to file |
| `--baseline` | flag | — | Exclude baselined issues |
| `--format` | string | md | Output format: `md`, `json` |

**Exit codes**:
- `0`: No issues at or above `--fail-on` severity.
- `1`: Issues found at or above `--fail-on` severity.
- `2`: Package error (storage unavailable, etc.).

**Markdown output**: Issues grouped by severity, with SQL, evidence counts, and recommendations.
