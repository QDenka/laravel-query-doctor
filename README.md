# Laravel Query Doctor

[![CI](https://github.com/QDenka/laravel-query-doctor/actions/workflows/ci.yml/badge.svg)](https://github.com/QDenka/laravel-query-doctor/actions)
[![Latest Version](https://img.shields.io/packagist/v/qdenka/laravel-query-doctor.svg)](https://packagist.org/packages/qdenka/laravel-query-doctor)
[![License](https://img.shields.io/packagist/l/qdenka/laravel-query-doctor.svg)](LICENSE)

Detect N+1 queries, missing indexes, and slow queries in your Laravel app before they reach production.

- **Catches 5 types of problems**: N+1, duplicate queries, slow queries, missing indexes, unnecessary `SELECT *`.
- **Works in dev and CI**: Web dashboard for browsing, CLI commands for reporting, exit codes for CI pipelines.
- **Zero config to start**: Install, open `/query-doctor`, see results.

## Requirements

- PHP 8.2+
- Laravel 10, 11, or 12
- SQLite PHP extension (included in most PHP installations)

## Installation

```bash
composer require --dev qdenka/laravel-query-doctor
php artisan vendor:publish --tag=query-doctor-config
```

That's it. The package auto-discovers and starts capturing queries.

## Quick Start

1. Install the package (see above).
2. Browse your app — make some requests, trigger some pages.
3. Open `/query-doctor` in your browser.

Or generate a report from the command line:

```bash
php artisan doctor:report
```

## CLI Commands

| Command | Description |
|---------|-------------|
| `php artisan doctor:report` | Show detected issues |
| `php artisan doctor:report --format=json` | Output as JSON |
| `php artisan doctor:report --format=md --output=report.md` | Save markdown report to file |
| `php artisan doctor:baseline` | Snapshot current issues as baseline |
| `php artisan doctor:baseline --clear` | Remove the baseline |
| `php artisan doctor:ci-report --fail-on=high` | CI mode: exit 1 if high+ severity issues exist |
| `php artisan doctor:ci-report --baseline` | CI mode: exclude baselined issues |

### CI Integration

```yaml
# .github/workflows/ci.yml
- name: Run tests
  run: vendor/bin/phpunit

- name: Check query performance
  run: php artisan doctor:ci-report --fail-on=high --output=report.md

- name: Upload report
  if: always()
  uses: actions/upload-artifact@v4
  with:
    name: query-doctor-report
    path: report.md
```

## Configuration

Publish and edit the config file:

```bash
php artisan vendor:publish --tag=query-doctor-config
```

Key options:

```php
// config/query-doctor.php
return [
    'enabled' => env('QUERY_DOCTOR_ENABLED', true),
    'allowed_environments' => ['local', 'staging'],

    'analyzers' => [
        'n_plus_one' => ['min_repetitions' => 5],
        'slow' => ['threshold_ms' => 100],
        'duplicate' => ['min_count' => 3],
    ],
];
```

See [docs/CONFIGURATION.md](docs/CONFIGURATION.md) for the full reference.

## Dashboard

The web dashboard is available at `/query-doctor` (only in allowed environments).

It shows:
- Issues grouped by severity
- Slow queries with timing details
- N+1 candidates with query counts
- Filters by period, route, severity, and type

## Security

Query bindings are sanitized before storage:

- **Column-based masking**: Bindings for columns like `password`, `token`, `api_key` are replaced with `[MASKED]`.
- **Pattern-based masking**: Strings matching email, phone, or SSN patterns are masked regardless of column.
- **Hash-only storage**: Bindings are stored as SHA-256 hashes for duplicate detection, not raw values.

You can add your own columns and patterns in the config:

```php
'masking' => [
    'columns' => ['password', 'secret', 'token', 'date_of_birth'],
    'value_patterns' => ['/^[A-Z]{2}\d{6}$/'],  // passport numbers
],
```

## How It Works

1. The package hooks into Laravel's `DB::listen()` to capture every SQL query.
2. Queries are fingerprinted (normalized) to group structurally identical queries.
3. Five analyzers run against captured queries to detect problems.
4. Results are stored in a dedicated SQLite file (separate from your app's database).
5. You view results via the dashboard, CLI, or exported reports.

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for the full architecture overview.

## Extending

Add custom analyzers:

```php
use QDenka\QueryDoctor\Domain\Contracts\AnalyzerInterface;

final class MyCustomAnalyzer implements AnalyzerInterface
{
    public function analyze(array $events): array { /* ... */ }
    public function type(): IssueType { /* ... */ }
}
```

See [docs/EXTENSION_API.md](docs/EXTENSION_API.md) for details on custom analyzers, reporters, and event hooks.

## Contributing

See [CONTRIBUTING.md](.github/CONTRIBUTING.md).

## License

MIT. See [LICENSE](LICENSE).
