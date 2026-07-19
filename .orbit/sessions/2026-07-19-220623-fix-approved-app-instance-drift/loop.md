# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: Solo scratchpad 322 (`docs-audit-final`)
- Worktree: `/Users/nckrtl/orbit/.worktrees/fix-approved-app-instance-drift`
- Branch: `fix-approved-app-instance-drift`

## Goal

Remove app-level placement defaults, make app instances the sole owner of
placement and workspace state, align schedule authorization with gateway-owned
execution, and preserve the already-running analytics assignment while making
its PostgreSQL process identity explicit.

## Scope

- Owned: product decisions and docs; app, app-instance, workspace, schedule,
  and analytics gateway contracts; matching CLI output; migrations and Pest
  coverage for the approved drift fixes.
- Constraints: `app:new` creates the logical app and first instance together;
  migration stops when ownership cannot be resolved safely; automatic schedule
  execution never re-checks caller permissions; existing analytics remains
  enabled and is not recreated.
- Out of scope: analytics replacement or move workflow; a new permission model
  for external Laravel Cloud instances; unrelated command families.

## Proof

- Verification:
  - focused: passed - gateway ownership/authorization/migration suites (108 tests,
    485 assertions); response-surface regression suite passed (62 tests, 422
    assertions); final app surface suite passed (50 tests, 337 assertions);
    CLI app command suite passed (72 tests, 316 assertions); docs rule suite
    passed (86 tests, 651 assertions); `composer docs-lint` passed with zero
    errors; gateway Mago formatting is clean.
  - broader: passed - `composer quality-check` passed every app and package at
    commit `254f556a27953a0d91fdd9f1f345b4ea751bb2ce`; receipt
    `.orbit/quality-gates/quality-check-2026-07-19T200453Z-8fd915758d30.json`.
  - runtime: passed - candidate `app:list --json` returned logical apps and
    instance/workspace counts without app-level placement; candidate
    `app:show mealou` rendered two instances with the workspace nested under
    `nmbp` and omitted app-level server, domain, path, and root. Read-only
    `node:show services1 --json` reports analytics active,
    `postgres_node_id=15`, `postgres_process_id=200`,
    `clickhouse_node_id=15`, and no last error; `https://analytics.orbit`
    returned HTTP 200.
- Blast radius: complete - evidence=repository-wide static analysis, lint, Rector dry-run, and review of the four-file corrective delta; result=logical-app and instance contracts remain aligned, and workspace ordering still comes from the ordered App relation
- Review: passed - human-judgment=not-required - independent review found no blocking issue
- Reviewed feature tip: 254f556a27953a0d91fdd9f1f345b4ea751bb2ce
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 254f556a27953a0d91fdd9f1f345b4ea751bb2ce
- Accepted main tip: a79e2cf1a6dca63215132394643770be34da84c0

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
