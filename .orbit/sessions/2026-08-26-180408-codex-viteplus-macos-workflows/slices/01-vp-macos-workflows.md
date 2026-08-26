# Orbit Feature Slice

- Slice: 01-vp-macos-workflows
- Depends on: none

## Outcome

The native macOS desktop release workflow uses Vite+ for npm-selected package
installation and all generic Tauri script execution.

## Scope

- Included: `apps/macos/package.json`,
  `bin/orbit-build-desktop-bundle`, and pinned native release contract tests.
- Excluded: native app UI behavior, Bun runtime selection, dependency upgrades,
  browser workflows, and human-only E2E lanes.

## Authority

- Decisions: newest VitePlus/Node/npm/Bun entry in `PRODUCT_DECISIONS.md`.
- Product docs: Vite+ package workflow policy already landed on `main`.

## Proof

- Focused: NativeReleaseAssetsBuilderTest.php, Mago format/lint, shell syntax,
  and exact host-macos Vite+ install/Tauri CLI proof.
