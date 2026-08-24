# Orbit Feature Slice

- Slice: 02-owned-shutdown
- Depends on: 01-menu-only

## Outcome

Explicit Quit prevents new work, stops every discoverable Orbit-owned launchd unit and labeled Docker container, stops the supervised Agent last, verifies no owned runtime remains, and exits only on success.

## Scope

- Included: Testable shutdown discovery and command execution, bounded ownership filters, Agent-last ordering, final rediscovery, failure state that keeps the menu app alive, tray feedback, and focused Rust coverage for success and failure transitions.
- Excluded: Gateway API mutation, stopping shared Docker providers, and stopping any workload without a provable Orbit ownership marker.

## Authority

- Decisions: Approved 2026-08-24 browser UI and menu-bar design; existing `orbit.managed=true` Docker labels and `dev.hardimpact.orbit.*` launchd label namespace.
- Product docs: `apps/docs/content/architecture.md`, `apps/docs/content/tech-stack.md`, and `apps/docs/content/domains/1_node/node-concepts.md`.

## Proof

- Focused: Rust unit tests with fake discovery/execution for ownership filtering, stop order, verification, and failed shutdown; full `apps/macos` Cargo checks.
