# MVP Checklist

What needs to be done before the first release (`v0.1.0`).

## Core

- [x] `QueryEvent` value object
- [x] `QueryFingerprint` value object with normalization algorithm
- [x] `Issue`, `Recommendation`, `Evidence` value objects
- [x] `Severity`, `IssueType`, `CaptureContext` enums
- [x] `AnalyzerInterface`, `StorageInterface`, `ExplainInterface`, `ReporterInterface` contracts

## Analyzers

- [x] `SlowQueryAnalyzer` — flags queries above threshold
- [x] `DuplicateQueryAnalyzer` — flags exact duplicate queries
- [x] `NPlusOneAnalyzer` — flags repeated fingerprints with varying bindings
- [x] `MissingIndexAnalyzer` — flags frequent slow queries without indexes
- [x] `SelectStarAnalyzer` — flags `SELECT *` patterns

## Application Layer

- [x] `QueryCaptureService` — receives events, builds domain objects, stores
- [x] `AnalysisPipeline` — runs all analyzers, deduplicates issues
- [x] `BaselineService` — snapshot/compare/exclude known issues
- [x] `ReportService` — load issues, filter, pass to reporter

## Infrastructure

- [x] `LaravelDbListenerAdapter` — hooks into `DB::listen()`
- [x] `SqliteStore` — CRUD, schema creation, WAL mode, retention
- [x] `InMemoryStore` — fallback for when SQLite is unavailable
- [x] `MysqlExplainAdapter` — parse MySQL EXPLAIN output
- [x] `PostgresExplainAdapter` — parse Postgres EXPLAIN JSON
- [x] `JsonReporter` — serialize issues to JSON
- [x] `MarkdownReporter` — render issues as markdown

## HTTP

- [x] `QueryDoctorMiddleware` — environment gate + context ID
- [x] `DoctorDashboardController` — index + API endpoints
- [x] Route registration (guarded by environment)
- [x] Dashboard layout (Blade + Alpine.js + Tailwind CDN)
- [x] Issue cards, filters, stats bar views

## CLI

- [x] `DoctorReportCommand` — table/JSON/markdown output
- [x] `DoctorBaselineCommand` — create/clear baseline
- [x] `DoctorCiReportCommand` — markdown output + exit codes

## Provider

- [x] `QueryDoctorServiceProvider` — full registration flow
- [x] Auto-discovery via `composer.json` extra
- [x] Config publishing

## Config

- [x] `config/query-doctor.php` with all options documented

## Security

- [x] PII masking for bindings (column-based + pattern-based)
- [x] Dashboard access restricted to allowed environments
- [x] Error isolation — package never crashes host app

## Tests

- [x] Unit: fingerprint normalization (10+ cases)
- [x] Unit: each analyzer (positive + negative cases)
- [x] Integration: DB listener capture
- [x] Integration: SQLite store CRUD + retention
- [x] Feature: CLI commands (output + exit codes)
- [x] Feature: dashboard routes (access control + API responses)
- [x] Feature: baseline flow (create → compare → exclude)
- [x] Golden: at least 3 fixture traces with expected output

## Quality

- [x] Larastan level 8 — zero errors
- [x] Pint — zero style issues
- [x] PHPUnit — all tests green

## Documentation

- [x] README with quickstart
- [x] CHANGELOG (initial entry)
- [x] CONTRIBUTING.md
- [x] All docs/ files up to date

## Release

- [ ] Tag `v0.1.0`
- [ ] GitHub release with changelog
- [ ] Packagist submission
