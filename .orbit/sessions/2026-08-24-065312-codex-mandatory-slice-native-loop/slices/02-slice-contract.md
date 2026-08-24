# Orbit Feature Slice

- Slice: 02-slice-contract
- Depends on: 01-frame-artifacts

## Outcome

The feature loop parses the active slice graph and enforces phase-specific
route, lint, feedback, and worker gates before work can advance.

## Scope

- Included:
  - Parse slice rows, dependencies, states, and checkpoints from the loop.
  - Enforce FRAME, BUILD, PROVE, ACCEPT, and LAND slice transition rules.
  - Add dependency-aware acceptance routing and finalization lint gates.
  - Record slice context on feedback without creating slice handoffs.
  - Include slice artifacts in the secret scan.
  - Add focused acceptance, feedback, parser, gate, and worker tests.
- Excluded:
  - Terminal all-complete enforcement and checkpoint ancestry validation.
  - Schema 4 compact archives, receipts, index identity, landing, and proof
    finalization.
  - Native Sol/Luna/Claude contracts, reviewer personas, the graph, and remaining
    active descriptions.

## Authority

- Decisions: Slice state is part of the feature loop contract; phase gates must
  reject missing, malformed, out-of-order, or dependency-blocked work.
- Product docs: The feature lifecycle and feedback contracts define when a slice
  can advance and which slice identity feedback retains.

## Proof

- Focused: Acceptance-routing, feedback, worker, parser, lint-gate, and secret-scan
  tests prove valid graphs advance and invalid phase transitions stop safely.
