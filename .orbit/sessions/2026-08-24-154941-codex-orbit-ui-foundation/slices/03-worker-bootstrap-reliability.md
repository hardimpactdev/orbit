# Orbit Feature Slice

- Slice: 03-worker-bootstrap-reliability
- Depends on: 02-monorepo-ui-integration

## Outcome

The required Claude reviewer can start and receive its bootstrap reliably when the operator PATH is long.

## Scope

- Included: `bin/orbit-worker-spawn`, its focused gateway worker-tool tests, and the smallest related helper surface.
- Excluded: UI behavior, weakened worker checkout or role protections, and manual E2E lanes.

## Authority

- Decisions: Orbit feature implementation requires one independent Claude reviewer through the worker launcher.
- Product docs: `HARNESS.md` and `.agents/skills/implementing-features/SKILL.md`.

## Proof

- Focused: reproduce the long-PATH tmux input truncation, then pass all 49 WorkerToolsTest cases and the full monorepo quality gate.
