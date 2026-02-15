# Roadmap

Development plan broken into sprints and a backlog for future versions.

## Sprint 1 — Capture + Basic Analysis (DONE)

**Goal**: Capture queries, fingerprint them, detect slow and duplicate queries, show results in CLI.

### Tasks

- [x] Domain: `QueryEvent`, `QueryFingerprint`, `Issue`, `Recommendation` value objects
- [x] Domain: `Severity`, `IssueType`, `CaptureContext` enums
- [x] Domain: `AnalyzerInterface` contract
- [x] Domain: `SlowQueryAnalyzer` implementation
- [x] Domain: `DuplicateQueryAnalyzer` implementation
- [x] Application: `QueryCaptureService`
- [x] Application: `AnalysisPipeline` (runs analyzers sequentially)
- [x] Infrastructure: `LaravelDbListenerAdapter` (hooks into `DB::listen()`)
- [x] Infrastructure: `SqliteStore` (basic CRUD, schema creation, WAL mode)
- [x] Infrastructure: `InMemoryStore` (fallback)
- [x] Console: `DoctorReportCommand` (table + JSON output)
- [x] Provider: `QueryDoctorServiceProvider` (basic registration)
- [x] Config: `query-doctor.php` with defaults
- [x] Tests: Unit tests for fingerprinting, analyzers
- [x] Tests: Integration test for DB listener capture

---

## Sprint 2 — N+1 + Missing Index + Dashboard (DONE)

**Goal**: Add the two most valuable analyzers and a web UI.

### Tasks

- [x] Domain: `NPlusOneAnalyzer` implementation
- [x] Domain: `MissingIndexAnalyzer` implementation
- [x] Domain: `SelectStarAnalyzer` implementation
- [x] Infrastructure: `MysqlExplainAdapter`
- [x] Infrastructure: `PostgresExplainAdapter`
- [x] HTTP: `DoctorDashboardController` (index + API endpoints)
- [x] HTTP: `QueryDoctorMiddleware` (access gate + context tracking)
- [x] HTTP: Routes registration
- [x] Views: Dashboard layout (Blade + Alpine.js + Tailwind CDN)
- [x] Views: Issue cards, filters, stats bar
- [x] API: Issues endpoint with pagination and filtering
- [x] API: Queries endpoint
- [x] Tests: Unit tests for N+1, missing index, select * analyzers
- [x] Tests: Feature tests for dashboard routes
- [x] Tests: Integration tests for EXPLAIN adapters

---

## Sprint 3 — Baseline + CI + Polish (DONE)

**Goal**: Make it CI-ready and production-quality.

### Tasks

- [x] Application: `BaselineService`
- [x] Console: `DoctorBaselineCommand`
- [x] Console: `DoctorCiReportCommand` (markdown output, exit codes)
- [x] Infrastructure: `MarkdownReporter`
- [x] Dashboard: Baseline create button
- [x] Dashboard: Ignore issue button
- [x] API: Baseline + ignore endpoints
- [x] Config: CI fail policy options
- [x] Security: PII masking for bindings
- [x] Storage: Retention cleanup
- [x] Docs: README with quickstart
- [x] Docs: CONTRIBUTING.md
- [x] Docs: CHANGELOG.md
- [x] CI: GitHub Actions workflow (PHP 8.2/8.3/8.4 x Laravel 10/11/12)
- [x] Tests: Feature tests for baseline flow
- [x] Tests: Feature tests for CI command exit codes
- [x] Tests: Golden tests with fixtures

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
