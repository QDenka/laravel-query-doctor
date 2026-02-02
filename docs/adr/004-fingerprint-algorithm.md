# ADR-004: SQL Fingerprint Algorithm

**Status**: Accepted
**Date**: 2025-01-15

## Context

We need to group structurally identical queries together to detect N+1 and duplicate patterns. Two queries like `SELECT * FROM users WHERE id = 1` and `SELECT * FROM users WHERE id = 99` should have the same fingerprint.

There are existing tools (pt-fingerprint from Percona Toolkit, sqlparse libraries), but adding external dependencies for this felt heavy. Our needs are more limited: we work with parameterized queries from Laravel, which already use `?` placeholders most of the time.

## Decision

Implement a custom normalization algorithm in PHP:

1. Strip string literals (`'...'`, `"..."`) → `?`
2. Strip numeric literals → `?`
3. Collapse `IN (?, ?, ..., ?)` → `IN (?)`
4. Lowercase SQL keywords
5. Collapse whitespace
6. Trim

Store both the normalized string and its SHA-256 hash (for indexing).

### Why This Order

- Strings first: prevents numbers inside strings from being double-replaced.
- IN collapse after literal replacement: the `?` placeholders are already in place.
- Lowercase after replacements: avoids case issues in string literal detection.

### Edge Case Decisions

- **Subqueries**: Normalized recursively. The fingerprint preserves subquery structure.
- **Quoted identifiers** (`` `table` ``, `"table"`): Preserved. They're schema, not data.
- **Comments**: Stripped entirely (`--` and `/* */`).
- **CASE WHEN**: Literals inside CASE are replaced with `?`.

## Consequences

**Good**:
- No external dependencies. Pure PHP, ~50 lines of code.
- Handles the common cases from Laravel's query builder well.
- Fast enough for real-time capture (regex-based, no parsing).

**Bad**:
- Not a full SQL parser. May produce incorrect fingerprints for exotic SQL (recursive CTEs, window functions with complex expressions). Acceptable for v1 — we're analyzing typical Laravel Eloquent output.
- Custom implementation means we own the bugs. If a regex misses an edge case, we fix it ourselves.
- Different databases may produce slightly different SQL for the same Eloquent call. The fingerprint should still match, but edge cases are possible.
