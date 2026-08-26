# Orbit Feature Slice

- Slice: 02-vp-project-workflows
- Depends on: 01-managed-viteplus-runtime

## Outcome

Orbit-owned JavaScript projects explicitly select npm or Bun, and generic
package installation, dependency, and package-script workflows run through
`vp` while native publication and runtime-only operations stay explicit.

## Scope

- Included: explicit `packageManager` fields; repository setup, quality,
  worktree, desktop, browser-build, and generated-doc calls that are generic
  package-manager or package-script interactions; focused PHP and
  script-contract tests; aligned product and contributor docs.
- Excluded: npm registry publication/trust/version commands; Bun-native test or
  executable semantics; arbitrary user-supplied process-command fixtures;
  changing existing external application manifests.

## Authority

- Decisions: newest VitePlus/Node/npm/Bun entry in `PRODUCT_DECISIONS.md`.
- Product docs: app setup/process guidance, tool catalog, generated monorepo
  unit map, and the shared app development setup defaults design.

## Proof

- Focused: package manifest assertions, repository verification-script tests,
  docs unit-map generation tests, UI browser-build tests, and focused Mago
  checks for changed PHP.
