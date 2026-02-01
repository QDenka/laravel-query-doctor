# Roadmap

Development plan broken into sprints and a backlog for future versions.

## Sprint 1 — Capture + Basic Analysis

**Goal**: Capture queries, fingerprint them, detect slow and duplicate queries, show results in CLI.

### Tasks

- [ ] Domain: `QueryEvent`, `QueryFingerprint`, `Issue`, `Recommendation` value objects
- [ ] Domain: `Severity`, `IssueType`, `CaptureContext` enums
- [ ] Domain: `AnalyzerInterface` contract
- [ ] Domain: `SlowQueryAnalyzer` implementation
- [ ] Domain: `DuplicateQueryAnalyzer` implementation
- [ ] Application: `QueryCaptureService`
- [ ] Application: `AnalysisPipeline` (runs analyzers sequentially)
- [ ] Infrastructure: `LaravelDbListenerAdapter` (hooks into `DB::listen()`)
- [ ] Infrastructure: `SqliteStore` (basic CRUD, schema creation, WAL mode)
- [ ] Infrastructure: `InMemoryStore` (fallback)
- [ ] Console: `DoctorReportCommand` (table + JSON output)
- [ ] Provider: `QueryDoctorServiceProvider` (basic registration)
- [ ] Config: `query-doctor.php` with defaults
- [ ] Tests: Unit tests for fingerprinting, analyzers
- [ ] Tests: Integration test for DB listener capture

### Definition of Done

- `composer require` works with auto-discovery.
- `php artisan doctor:report` shows detected issues from captured queries.
- SQLite storage works with WAL mode.
- Fallback to InMemoryStore when SQLite is unavailable.
- Unit tests pass.

---

## Sprint 2 — N+1 + Missing Index + Dashboard

**Goal**: Add the two most valuable analyzers and a web UI.

### Tasks

- [ ] Domain: `NPlusOneAnalyzer` implementation
- [ ] Domain: `MissingIndexAnalyzer` implementation
- [ ] Domain: `SelectStarAnalyzer` implementation
- [ ] Infrastructure: `MysqlExplainAdapter`
- [ ] Infrastructure: `PostgresExplainAdapter`
- [ ] HTTP: `DoctorDashboardController` (index + API endpoints)
- [ ] HTTP: `QueryDoctorMiddleware` (access gate + context tracking)
- [ ] HTTP: Routes registration
- [ ] Views: Dashboard layout (Blade + Alpine.js + Tailwind CDN)
- [ ] Views: Issue cards, filters, stats bar
- [ ] API: Issues endpoint with pagination and filtering
- [ ] API: Queries endpoint
- [ ] Tests: Unit tests for N+1, missing index, select * analyzers
- [ ] Tests: Feature tests for dashboard routes
- [ ] Tests: Integration tests for EXPLAIN adapters

### Definition of Done

- Dashboard at `/query-doctor` shows issues grouped by severity.
- Filters work (period, severity, type, route).
- N+1 detection works with configurable thresholds.
- EXPLAIN integration works for MySQL (Postgres as stretch goal).
- Feature tests pass.

---

## Sprint 3 — Baseline + CI + Polish

**Goal**: Make it CI-ready and production-quality.

### Tasks

- [ ] Application: `BaselineService`
- [ ] Console: `DoctorBaselineCommand`
- [ ] Console: `DoctorCiReportCommand` (markdown output, exit codes)
- [ ] Infrastructure: `MarkdownReporter`
- [ ] Dashboard: Baseline create button
- [ ] Dashboard: Ignore issue button
- [ ] API: Baseline + ignore endpoints
- [ ] Config: CI fail policy options
- [ ] Security: PII masking for bindings
- [ ] Storage: Retention cleanup
- [ ] Docs: README with quickstart
- [ ] Docs: CONTRIBUTING.md
- [ ] Docs: CHANGELOG.md
- [ ] CI: GitHub Actions workflow (PHP 8.2/8.3/8.4 x Laravel 10/11/12)
- [ ] Tests: Feature tests for baseline flow
- [ ] Tests: Feature tests for CI command exit codes
- [ ] Tests: Golden tests with fixtures

### Definition of Done

- `doctor:ci-report --fail-on=high` exits 1 when high-severity issues exist.
- Baseline excludes known issues from CI reports.
- PII masking strips sensitive bindings.
- All tests green. Larastan level 8 clean. Pint clean.
- README has a working quickstart.
- Package is publishable to Packagist.

---

## Backlog (v2+)

Ideas for future versions. Not committed.

- **Queue job middleware**: Automatic `job_trace_id` tracking for queue jobs.
- **Sampling mode**: Capture only a percentage of requests (configurable) to reduce overhead in staging.
- **Async flush**: Buffer events in memory, flush to SQLite on request termination.
- **SARIF reporter**: Output issues in SARIF format for GitHub Code Scanning integration.
- **Slack reporter**: Send alerts to Slack when new critical issues appear.
- **PR comment reporter**: Post a summary comment on GitHub PRs via Actions.
- **Laravel Telescope integration**: Link Query Doctor issues to Telescope request details.
- **Schema-aware analysis**: Read table schema to improve select * and missing index detection accuracy.
- **Trend tracking**: Show issue counts over time. Are things getting better or worse?
- **Custom analyzer registration**: Allow packages to register their own analyzers via service provider tag.
- **Rate limiting**: Throttle SQLite writes under heavy load to prevent disk I/O bottlenecks.
