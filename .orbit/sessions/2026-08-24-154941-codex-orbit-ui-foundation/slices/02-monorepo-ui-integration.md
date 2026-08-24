# Orbit Feature Slice

- Slice: 02-monorepo-ui-integration
- Depends on: 01-fresh-ui-app

## Outcome

Orbit treats `apps/ui` as a first-class monorepo application and its product documentation states the separate local browser-UI boundary without claiming the deferred production role.

## Scope

- Included: integrate `apps/ui` into existing root install/check/tooling conventions; update the generated unit routing source or artifact through its canonical generator; record the fresh separate UI decision and current local-development behavior; add focused repository coverage for the new unit where existing architecture tests require it.
- Excluded: production `ui` role/image/routing, `app.orbit`, gateway delegation, local runtime mutation, and native macOS changes.

## Authority

- Decisions: approved separate `apps/ui` based on Launch; production UI-role work is deferred; native menu-bar conversion is a separate host-mac feature.
- Product docs: `PRODUCT_DECISIONS.md`, `apps/docs/content/architecture.md`, `apps/docs/content/tech-stack.md`, `apps/docs/content/generated/monorepo-unit-map.json`.

## Proof

- Focused: prove root orchestration discovers and checks `apps/ui`, then pass affected architecture/docs checks and `composer docs-lint`.
