# Orbit Feature Slice

- Slice: 01-frame-artifacts
- Depends on: none

## Outcome

FRAME creates and seeds a dependency-aware slice set before BUILD starts, with
the product decision and architecture contract covered by focused preparation
tests.

## Scope

- Included:
  - Add the FRAME methods that create, validate, and update slice artifacts.
  - Add the loop and slice templates and seed them from worktree preparation.
  - Add the dated `PRODUCT_DECISIONS.md` entry for mandatory vertical slices.
  - Align the architecture and preparation contract with the seeded artifacts.
  - Add focused architecture and worktree-preparation tests.
- Excluded:
  - Phase-aware parsing, route or lint gates, feedback context, and secret scans.
  - Terminal checkpoint rules, schema 4 archives, and finalization behavior.
  - Native Luna BUILD instructions, reviewer personas, the graph, and remaining
    active descriptions.

## Authority

- Decisions: The dated product decision requires one or more ordered,
  dependency-aware vertical slices and makes the FRAME artifacts mandatory.
- Product docs: Architecture and worktree-preparation documentation define the
  artifact locations, dependency order, initial states, and seeding behavior.

## Proof

- Focused: Architecture contract tests and worktree-preparation tests prove the
  templates, FRAME methods, and initial slice files are present and consistent.
