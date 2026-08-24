# Orbit Feature Slice

- Slice: 01-restart-ready-handoff
- Depends on: none

## Outcome

Gateway-produced macOS Desktop handoffs use `restart-ready`, and focused tests
prove the exact payload mode that the CLI writes and Orbit Desktop consumes.

## Scope

- Included: `apps/gateway/app/Services/Operations/WorkloadNodeUpdater.php`, its focused Pest coverage, and the matching `update:all` product contract wording.
- Excluded: Tauri menu code, CLI handoff schema changes, selection predicates, release scripts, and live-machine mutation.

## Authority

- Decisions: `PRODUCT_DECISIONS.md` 2026-08-23 macOS Desktop ownership decision requires consumption “as Restart to Update.”
- Product docs: `apps/docs/content/architecture.md`, `apps/docs/content/tech-stack.md`, and `apps/docs/content/domains/11_operation/2_update-all/technical/1_update-all.md`.

## Proof

- Focused: RED on the current `automatic` payload expectation; GREEN with the relevant `WorkloadNodeUpdaterTest` cases, focused Mago on every changed PHP file, and `composer docs-lint` when contract prose changes.
