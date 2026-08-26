# Orbit Feature Slice

- Slice: 01-vp-ui-workflows
- Depends on: none

## Outcome

`apps/ui` uses `vp` for generic install, executable, and script interactions,
and the real UI build plus browser-facing app remain healthy.

## Scope

- Included: `apps/ui/**` generic package, script, and executable command
  references; browser build runner; focused tests; aligned UI guidance.
- Excluded: native Bun runtime APIs, `apps/macos/**`, dependency upgrades,
  visual UI changes, and human-only E2E lanes.

## Authority

- Decisions: newest VitePlus/Node/npm/Bun entry in `PRODUCT_DECISIONS.md`.
- Product docs: Vite+ package workflow policy already landed on `main`.

## Proof

- Focused: UI Pest tests, Pint, PHPStan, `vp check`, `vp install`, client and
  SSR `vp run build`, and browser proof on the exact candidate.
