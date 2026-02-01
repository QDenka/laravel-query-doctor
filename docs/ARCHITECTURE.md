# Architecture

How the package is structured and why.

## Overview

Laravel Query Doctor uses a hexagonal architecture (ports & adapters). The domain layer defines the rules. The infrastructure layer plugs into Laravel. They communicate through interfaces, not concrete classes.

```
┌────────────────────────────────────────────────────────────────┐
│                        Host Laravel App                        │
│                                                                │
│  DB::listen() ──► Capture Adapter ──► QueryEvent (domain)      │
│                                          │                     │
│                                    AnalysisPipeline            │
│                                    ┌─────┴──────┐             │
│                                    │  Analyzer 1 │             │
│                                    │  Analyzer 2 │ ──► Issue[] │
│                                    │  Analyzer 3 │             │
│                                    │  ...        │             │
│                                    └─────────────┘             │
│                                          │                     │
│                              ┌───────────┼───────────┐         │
│                              ▼           ▼           ▼         │
│                          Storage     Reporter     Dashboard    │
│                         (SQLite)    (MD/JSON)    (Blade+API)   │
└────────────────────────────────────────────────────────────────┘
```

## Layers

### Domain (`src/Domain/`)

Pure PHP. No Laravel imports. No database access.

Contains:
- **Value objects**: `QueryEvent`, `QueryFingerprint`, `Issue`, `Recommendation`
- **Enums**: `Severity`, `IssueType`, `CaptureContext`
- **Contracts**: `AnalyzerInterface`, `StorageInterface`, `ExplainInterface`, `EventSourceInterface`, `ReporterInterface`
- **Analyzers**: Five analyzer implementations that take `QueryEvent[]` and return `Issue[]`

This layer is testable with plain PHPUnit. No TestBench needed.

### Application (`src/Application/`)

Orchestration. Wires domain components together.

- **QueryCaptureService**: Receives raw query events from the event source, builds `QueryEvent` objects, stores them.
- **AnalysisPipeline**: Runs all registered analyzers against a batch of events. Collects and deduplicates issues.
- **BaselineService**: Manages the baseline — a snapshot of known issues that should be excluded from reports.
- **ReportService**: Loads issues from storage, applies filters, passes to a reporter for output.

### Infrastructure (`src/Infrastructure/`)

Laravel-specific adapters that implement domain contracts.

- **LaravelDbListenerAdapter** → `EventSourceInterface`: Hooks into `DB::listen()`.
- **SqliteStore** / **InMemoryStore** → `StorageInterface`: Persist and query events/issues.
- **MysqlExplainAdapter** / **PostgresExplainAdapter** → `ExplainInterface`: Run EXPLAIN on queries.
- **JsonReporter** / **MarkdownReporter** → `ReporterInterface`: Format issue reports.

### HTTP (`src/Http/`)

Dashboard controller, middleware, and routes. Self-contained Blade + Alpine.js UI.

### Console (`src/Console/`)

Artisan commands: `doctor:report`, `doctor:baseline`, `doctor:ci-report`.

### Providers (`src/Providers/`)

`QueryDoctorServiceProvider` — registers everything. Checks if the package is enabled, binds interfaces to implementations, registers routes/commands/middleware.

## Data Flow

### Capture (per request/job)

```
HTTP Request
  → QueryDoctorMiddleware (generates request_id)
  → App code runs Eloquent queries
  → DB::listen() fires for each query
  → LaravelDbListenerAdapter catches it
  → Builds QueryEvent with SQL, bindings, time, stack trace, context
  → QueryCaptureService stores to SqliteStore
```

### Analysis (on-demand or deferred)

```
ReportService (triggered by CLI or dashboard)
  → Loads QueryEvent[] from storage for a given period/context
  → Passes to AnalysisPipeline
  → Each Analyzer runs independently
  → Pipeline collects Issue[] from all analyzers
  → Deduplicates (same fingerprint + type = same issue)
  → Filters out baselined issues
  → Returns to caller
```

### Reporting

```
Issue[]
  → ReportService selects reporter (JSON, Markdown, or Dashboard)
  → Reporter formats output
  → Output goes to: stdout (CLI), file (CI), or HTTP response (dashboard API)
```

## Design Decisions

Detailed reasoning in `docs/adr/`:

- [ADR-001](adr/001-hexagonal-architecture.md): Why hexagonal over simple service classes
- [ADR-002](adr/002-sqlite-storage.md): Why SQLite for internal storage
- [ADR-003](adr/003-blade-alpine-ui.md): Why Blade + Alpine.js for the dashboard
- [ADR-004](adr/004-fingerprint-algorithm.md): How SQL fingerprinting works
- [ADR-005](adr/005-error-isolation.md): How we prevent the package from crashing the host app

## Extending

To add a new analyzer: implement `AnalyzerInterface` and register it in the service provider.

To add a new reporter: implement `ReporterInterface`.

To add a new event source: implement `EventSourceInterface`.

See [EXTENSION_API.md](EXTENSION_API.md) for details.
