# Configuration

Full reference for `config/query-doctor.php`.

Publish the config file:
```bash
php artisan vendor:publish --tag=query-doctor-config
```

## Complete Config Reference

```php
return [
    // Master switch. Set to false to disable all capture and analysis.
    'enabled' => env('QUERY_DOCTOR_ENABLED', true),

    // Environments where the package is active.
    // Outside these environments, the package does nothing.
    'allowed_environments' => ['local', 'staging'],

    // Which contexts to capture queries from.
    'capture' => [
        'http' => true,
        'queue' => true,
        'cli' => false,   // disabled by default — artisan commands can be noisy
    ],

    // Stack trace extraction settings.
    'stack_trace' => [
        // Max number of app-level frames to keep per query.
        'depth' => 10,

        // Path prefixes to exclude from stack traces.
        // Frames from these paths are skipped.
        'exclude_paths' => [
            'vendor/',
        ],
    ],

    // Analyzer-specific thresholds.
    'analyzers' => [

        'n_plus_one' => [
            'enabled' => true,
            // Minimum times the same fingerprint must appear in one context.
            'min_repetitions' => 5,
            // Minimum total time (ms) of all matching queries.
            'min_total_ms' => 20,
        ],

        'duplicate' => [
            'enabled' => true,
            // Minimum times the exact same SQL must run in one context.
            'min_count' => 3,
        ],

        'slow' => [
            'enabled' => true,
            // Queries slower than this (ms) are flagged.
            'threshold_ms' => 100,
        ],

        'missing_index' => [
            'enabled' => true,
            // Minimum occurrences across contexts before flagging.
            'min_occurrences' => 5,
            // Minimum average execution time (ms).
            'min_avg_ms' => 50,
            // Try to run EXPLAIN on suspicious queries.
            'use_explain' => true,
        ],

        'select_star' => [
            'enabled' => true,
            // Minimum occurrences before flagging.
            'min_occurrences' => 3,
        ],
    ],

    // Patterns to ignore. Queries matching these won't be captured.
    'ignore' => [
        // SQL patterns (regex). Matched against the raw SQL.
        'sql_patterns' => [
            // '/^PRAGMA/',       // Example: ignore SQLite pragmas
            // '/^SET NAMES/',    // Example: ignore connection setup
        ],

        // Route patterns. Requests to these routes won't be captured.
        'routes' => [
            'query-doctor*',     // Don't capture the dashboard's own queries
            '_debugbar*',        // Laravel Debugbar
            'telescope*',        // Laravel Telescope
            'horizon*',          // Laravel Horizon
        ],

        // Table names. Queries touching these tables are skipped.
        'tables' => [
            // 'telescope_entries',
            // 'jobs',
        ],
    ],

    // Internal storage settings.
    'storage' => [
        // Where to store the SQLite file.
        // Set to ':memory:' for in-memory only (no persistence).
        'path' => env('QUERY_DOCTOR_STORAGE_PATH', storage_path('query-doctor.sqlite')),

        // Delete records older than this many days.
        'retention_days' => 14,

        // Run retention cleanup every N writes (0 = never, do it manually).
        'cleanup_every' => 500,
    ],

    // PII masking for query bindings.
    'masking' => [
        'enabled' => true,

        // Columns whose values should always be masked in storage.
        // Matched against the column name in WHERE clauses.
        'columns' => [
            'password', 'secret', 'token', 'api_key', 'access_token',
            'refresh_token', 'credit_card', 'ssn', 'social_security',
        ],

        // Regex patterns for values that look like PII.
        // Applied to string bindings before storage.
        'value_patterns' => [
            '/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/',  // email
            '/^\+?[1-9]\d{6,14}$/',                                    // phone
            '/^\d{3}-\d{2}-\d{4}$/',                                   // SSN format
        ],
    ],

    // Dashboard settings.
    'dashboard' => [
        // Route prefix. Dashboard will be at /{prefix}.
        'prefix' => 'query-doctor',

        // Additional middleware to apply to dashboard routes.
        // Use this for auth: ['auth', 'can:view-query-doctor']
        'middleware' => [],
    ],

    // CI report settings.
    'ci' => [
        // Default severity threshold for CI failure.
        // Issues at or above this severity cause exit code 1.
        'fail_on' => 'high',
    ],
];
```

## Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `QUERY_DOCTOR_ENABLED` | `true` | Master switch |
| `QUERY_DOCTOR_STORAGE_PATH` | `storage/query-doctor.sqlite` | SQLite file location |

## Overriding in Tests

```php
// In your TestCase setUp
$this->app['config']->set('query-doctor.enabled', true);
$this->app['config']->set('query-doctor.allowed_environments', ['testing']);
$this->app['config']->set('query-doctor.storage.path', ':memory:');
```
