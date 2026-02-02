# MVP Checklist

What needs to be done before the first release (`v0.1.0`).

## Core

- [ ] `QueryEvent` value object
- [ ] `QueryFingerprint` value object with normalization algorithm
- [ ] `Issue`, `Recommendation`, `Evidence` value objects
- [ ] `Severity`, `IssueType`, `CaptureContext` enums
- [ ] `AnalyzerInterface`, `StorageInterface`, `ExplainInterface`, `ReporterInterface` contracts

## Analyzers

- [ ] `SlowQueryAnalyzer` — flags queries above threshold
- [ ] `DuplicateQueryAnalyzer` — flags exact duplicate queries
- [ ] `NPlusOneAnalyzer` — flags repeated fingerprints with varying bindings
- [ ] `MissingIndexAnalyzer` — flags frequent slow queries without indexes
- [ ] `SelectStarAnalyzer` — flags `SELECT *` patterns

## Application Layer

- [ ] `QueryCaptureService` — receives events, builds domain objects, stores
- [ ] `AnalysisPipeline` — runs all analyzers, deduplicates issues
- [ ] `BaselineService` — snapshot/compare/exclude known issues
- [ ] `ReportService` — load issues, filter, pass to reporter

## Infrastructure

- [ ] `LaravelDbListenerAdapter` — hooks into `DB::listen()`
- [ ] `SqliteStore` — CRUD, schema creation, WAL mode, retention
- [ ] `InMemoryStore` — fallback for when SQLite is unavailable
- [ ] `MysqlExplainAdapter` — parse MySQL EXPLAIN output
- [ ] `PostgresExplainAdapter` — parse Postgres EXPLAIN JSON
- [ ] `JsonReporter` — serialize issues to JSON
- [ ] `MarkdownReporter` — render issues as markdown

## HTTP

- [ ] `QueryDoctorMiddleware` — environment gate + context ID
- [ ] `DoctorDashboardController` — index + API endpoints
- [ ] Route registration (guarded by environment)
- [ ] Dashboard layout (Blade + Alpine.js + Tailwind CDN)
- [ ] Issue cards, filters, stats bar views

## CLI

- [ ] `DoctorReportCommand` — table/JSON/markdown output
- [ ] `DoctorBaselineCommand` — create/clear baseline
- [ ] `DoctorCiReportCommand` — markdown output + exit codes

## Provider

- [ ] `QueryDoctorServiceProvider` — full registration flow
- [ ] Auto-discovery via `composer.json` extra
- [ ] Config publishing

## Config

- [ ] `config/query-doctor.php` with all options documented

## Security

- [ ] PII masking for bindings (column-based + pattern-based)
- [ ] Dashboard access restricted to allowed environments
- [ ] Error isolation — package never crashes host app

## Tests

- [ ] Unit: fingerprint normalization (10+ cases)
- [ ] Unit: each analyzer (positive + negative cases)
- [ ] Integration: DB listener capture
- [ ] Integration: SQLite store CRUD + retention
- [ ] Feature: CLI commands (output + exit codes)
- [ ] Feature: dashboard routes (access control + API responses)
- [ ] Feature: baseline flow (create → compare → exclude)
- [ ] Golden: at least 3 fixture traces with expected output

## Quality

- [ ] Larastan level 8 — zero errors
- [ ] Pint — zero style issues
- [ ] PHPUnit — all tests green

## Documentation

- [ ] README with quickstart
- [ ] CHANGELOG (initial entry)
- [ ] CONTRIBUTING.md
- [ ] All docs/ files up to date

## Release

- [ ] Tag `v0.1.0`
- [ ] GitHub release with changelog
- [ ] Packagist submission
