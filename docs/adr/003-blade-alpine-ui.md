# ADR-003: Blade + Alpine.js for Dashboard

**Status**: Accepted
**Date**: 2025-01-15

## Context

The dashboard needs client-side interactivity (filters, expand/collapse, async data loading). Options considered:

1. **Livewire** — reactive without JavaScript, but adds a Composer dependency. Host app might not use Livewire.
2. **Inertia + Vue/React** — full SPA experience, but heavy dependency. Requires build step.
3. **Blade + Alpine.js** — minimal JavaScript, loaded from CDN. No build step, no extra Composer packages.
4. **Blade + Vanilla JS** — simplest option, but writing filter logic and DOM manipulation by hand is tedious.

## Decision

Blade templates with Alpine.js for interactivity and Tailwind CSS (CDN play script) for styling. Both loaded from CDN — no npm, no build step, no node_modules.

This matches the approach used by Laravel Telescope and Horizon (self-contained dashboards that don't depend on the host app's frontend stack).

## Consequences

**Good**:
- Zero frontend dependencies. No package.json, no build step.
- Works regardless of what the host app uses (Vue, React, Livewire, nothing).
- Alpine.js is small (~15KB) and handles our needs (filters, toggles, fetch calls).
- Tailwind CDN play script gives utility classes without a build pipeline.
- Self-contained: the dashboard layout is a standalone HTML document.

**Bad**:
- CDN dependency means the dashboard needs internet access on first load (assets are cached after).
- Tailwind play script is not production-optimized (loads all utilities). Acceptable because this is a dev tool, not a user-facing page.
- Alpine.js has a learning curve for contributors unfamiliar with it, though it's simpler than Vue or React.
