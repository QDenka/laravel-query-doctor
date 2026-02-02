# ADR-001: Hexagonal Architecture

**Status**: Accepted
**Date**: 2025-01-15

## Context

We need an architecture for a Laravel package that has multiple input sources (HTTP, queue, CLI), multiple output targets (dashboard, CLI, markdown, JSON), and multiple analysis strategies (5 analyzers, potentially more). The standard approach in Laravel packages is service classes directly using Eloquent and facades, but that makes testing harder and the package harder to extend.

## Decision

Use hexagonal architecture (ports & adapters). The domain layer defines interfaces (ports) for storage, reporting, EXPLAIN, and event capture. Infrastructure adapters implement those interfaces with Laravel-specific code.

Analyzers follow the strategy pattern — each one is a standalone class implementing `AnalyzerInterface`. The `AnalysisPipeline` runs them in sequence.

## Consequences

**Good**:
- Domain logic is testable with plain PHPUnit. No TestBench needed for analyzer unit tests.
- Adding a new analyzer doesn't touch existing code — just implement the interface and register it.
- Adding a new reporter (SARIF, Slack) doesn't require changes to the analysis pipeline.
- Swapping storage (SQLite → Redis, file-based, etc.) requires only a new adapter.

**Bad**:
- More files and indirection than a simple service-class approach.
- Contributors familiar with typical Laravel packages may need to understand the port/adapter pattern first.
- Overkill if the package never grows beyond v1 scope — but that's unlikely given the roadmap.
