# Orbit Feature Slice

- Slice: 03-docs-and-cleanup
- Depends on: 02-owned-shutdown

## Outcome

Product documentation and repository packaging describe the menu-bar-only lifecycle and no obsolete dashboard frontend or window contract remains.

## Scope

- Included: Reconcile architecture, tech stack, node concepts, native package configuration, and focused search-based cleanup proof.
- Excluded: Browser UI screens, production UI placement, gateway delegation, and release publication.

## Authority

- Decisions: Approved 2026-08-24 browser UI and menu-bar design.
- Product docs: `apps/docs/content/architecture.md`, `apps/docs/content/tech-stack.md`, and `apps/docs/content/domains/1_node/node-concepts.md`.

## Proof

- Focused: `composer docs-lint`, full `apps/macos` Cargo checks, and repository search proving dashboard window/frontend references are removed from the active native contract.
