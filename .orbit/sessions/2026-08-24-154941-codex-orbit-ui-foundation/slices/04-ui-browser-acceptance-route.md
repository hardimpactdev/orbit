# Orbit Feature Slice

- Slice: 04-ui-browser-acceptance-route
- Depends on: 03-worker-bootstrap-reliability

## Outcome

Orbit classifies changes under `apps/ui/**` as browser acceptance work, while automation-only companion files can coexist without changing that venue.

## Scope

- Included: candidate venue routing in `bin/orbit-loop-contract.php` and focused gateway acceptance-routing tests.
- Excluded: UI behavior, other application venue changes, and manual E2E lanes.

## Authority

- Decisions: `apps/ui` owns the browser UI and its proof runs at `https://orbit.nmbp`.
- Product docs: `HARNESS.md`, `apps/docs/content/architecture.md`, and `apps/docs/content/generated/monorepo-unit-map.json`.

## Proof

- Focused: show `apps/ui/**` currently falls back to retained Incus, then pass browser-route and mixed automation-only coverage.
