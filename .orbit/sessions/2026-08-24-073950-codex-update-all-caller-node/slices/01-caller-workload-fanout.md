# Orbit Feature Slice

- Slice: 01-caller-workload-fanout
- Depends on: none

## Outcome

A registered active supported non-gateway caller appears in the persisted
workload-node fan-out and still appears in the separate caller-local CLI phase.

## Scope

- Included: caller eligibility, gateway exclusion, operation-plan targets, focused unit and feature tests, update command docs, and the dated product decision.
- Excluded: native macOS menu implementation, release artifact assembly, and public release publication.

## Authority

- Decisions: `PRODUCT_DECISIONS.md` records that all active supported non-gateway nodes include the registered caller regardless of roles or `managed`.
- Product docs: `apps/docs/content/domains/11_operation/2_update-all/` defines selection, ordering, output, skip, and Desktop handoff behavior.

## Proof

- Focused: run the selector unit tests and workload updater feature tests with the caller assertion, then focused Mago format and lint for every changed PHP file.
