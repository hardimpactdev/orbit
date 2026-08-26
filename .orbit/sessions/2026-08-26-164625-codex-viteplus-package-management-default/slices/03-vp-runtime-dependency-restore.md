# Orbit Feature Slice

- Slice: 03-vp-runtime-dependency-restore
- Depends on: 02-vp-project-workflows

## Outcome

Orbit's local process dependency restoration keeps detecting npm or Bun project
state but restores JavaScript dependencies through the common `vp install`
surface selected by the project.

## Scope

- Included: local runtime dependency detection/restoration service, its
  operator-visible dependency metadata where needed, focused CLI tests, and
  directly relevant process/runtime docs.
- Excluded: arbitrary stored process commands; changing dependency keys from
  npm/Bun to VitePlus; native package publishing; external project migration.

## Authority

- Decisions: newest VitePlus/Node/npm/Bun entry in `PRODUCT_DECISIONS.md`.
- Product docs: process dependency restoration and tool catalog contracts.

## Proof

- Focused: CLI Pest coverage proves npm and Bun lock/project detection remains
  distinct while both restore through a frozen Vite+ install; focused Mago
  checks for changed PHP.
