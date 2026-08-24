# Orbit Feature Slice

- Slice: 03-finalization-archive
- Depends on: 02-slice-contract

## Outcome

Finalization accepts only an all-complete slice graph with valid checkpoint
ancestry and emits a compact schema 4 archive whose receipt and index preserve
one feature identity.

## Scope

- Included:
  - Require every slice to be complete at the terminal gate.
  - Validate each recorded checkpoint against ordered Git ancestry.
  - Add compact archive schema 4 for the feature and its slice checkpoints.
  - Keep archive receipt and index identities aligned with the feature archive.
  - Keep schemas 2 and 3 readable through explicit compatibility handling.
  - Add focused finalization, archive, land, index, compatibility, and proof tests.
- Excluded:
  - Native Luna worker dispatch and the active Sol/Luna/Claude role contract.
  - `AGENTS.md`, `AGENT_FAST_PATH.md`, `HARNESS.md`, reviewer personas, graph, and
    remaining active agent or skill descriptions.
  - Multi-venue acceptance, persisted per-slice proving state, or slice handoffs.

## Authority

- Decisions: LAND requires a complete dependency graph and checkpoint ancestry;
  compact archives preserve feature identity without storing per-slice handoffs.
- Product docs: Finalization and archive contracts define schema 4, receipt/index
  identity, checkpoint ancestry, and schema 2 and 3 read compatibility.

## Proof

- Focused: Finalization, session-archive, landing, archive-index, compatibility,
  and feature-proof tests prove terminal gates and compact schema 4 behavior.
