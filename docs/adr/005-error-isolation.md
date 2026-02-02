# ADR-005: Error Isolation Strategy

**Status**: Accepted
**Date**: 2025-01-15

## Context

This is a dev-tool package installed in other people's applications. If the package throws an unhandled exception during query capture or analysis, it could crash the host app's request. That's unacceptable — a debugging tool must never be the cause of bugs.

## Decision

Every entry point from the host app into our code is wrapped in a try-catch that logs warnings and continues silently:

1. **DB listener callback**: If building a `QueryEvent` fails, log and skip that query.
2. **SQLite writes**: If storage fails, switch to `InMemoryStore` for the rest of the request.
3. **EXPLAIN execution**: If EXPLAIN fails, return null. The analyzer works without it (lower confidence).
4. **Dashboard rendering**: If the view fails, return a simple error page. Don't propagate to the host app's error handler.
5. **Analysis pipeline**: If an individual analyzer throws, catch it, log it, continue with the remaining analyzers.

The package uses Laravel's logger (`Log::warning(...)`) for its error messages. Prefix all messages with `Query Doctor:` for easy filtering.

## Consequences

**Good**:
- The host app never crashes because of our package.
- Errors are still visible in logs — you can diagnose issues without them affecting the app.
- Graceful degradation: if SQLite breaks, the package still works for the current request (in-memory). If EXPLAIN breaks, analyzers still run (with lower confidence).

**Bad**:
- Silent failures can be hard to notice. A bug in the package might go undetected because it only shows as a log warning.
- Catching `\Throwable` broadly can mask real problems during development of the package itself. Mitigated by comprehensive tests.
- Contributors need to remember to wrap new entry points in try-catch. Documented in `CONTRIBUTING.md`.
