# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-02-16

First public release.

### Added

- **Domain layer**: `QueryEvent`, `QueryFingerprint`, `Issue`, `Evidence`, `Recommendation`, `SourceContext` value objects. `Severity`, `IssueType`, `CaptureContext` enums. All contracts (`AnalyzerInterface`, `StorageInterface`, `ExplainInterface`, `ReporterInterface`).
- **Five analyzers**: `SlowQueryAnalyzer`, `DuplicateQueryAnalyzer`, `NPlusOneAnalyzer`, `MissingIndexAnalyzer`, `SelectStarAnalyzer` — each with configurable thresholds, confidence scoring, and severity tiers.
- **Application layer**: `AnalysisPipeline`, `QueryCaptureService`, `ReportService`, `BaselineService`.
- **Infrastructure**: `SqliteStore` (WAL mode, retention cleanup), `InMemoryStore` (fallback), `LaravelDbListenerAdapter`, `MysqlExplainAdapter`, `PostgresExplainAdapter`, `JsonReporter`, `MarkdownReporter`.
- **PII masking**: `BindingMasker` with column-based and pattern-based masking (email, phone, SSN). Configurable via `masking.columns` and `masking.value_patterns`.
- **Web dashboard**: Blade + Alpine.js (CDN) UI with stats bar, severity/type filters, expandable issue cards, baseline and ignore buttons. API endpoints for issues, queries, baseline, and ignore.
- **CLI commands**: `doctor:report` (table/JSON/markdown), `doctor:baseline` (create/clear), `doctor:ci-report` (exit codes, baseline exclusion).
- **CI integration**: Exit code 0/1 based on configurable severity threshold. Markdown report output. Baseline exclusion for known issues.
- **Service provider**: Auto-discovery, config publishing, view publishing, environment gating.
- **GitHub infrastructure**: CI workflow (PHP 8.2/8.3/8.4 x Laravel 10/11/12 matrix), release workflow, issue templates, PR template, CONTRIBUTING.md.
- **Tests**: 190+ tests across unit, integration, feature, and golden test suites. Larastan level 8 with zero errors. Pint clean.
- **Documentation**: Architecture guide, domain model, analyzer specs, API reference, database schema, configuration reference, security guide, testing strategy, extension API, roadmap, 5 ADRs.
