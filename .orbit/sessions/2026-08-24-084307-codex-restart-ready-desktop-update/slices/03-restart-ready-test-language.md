# Orbit Feature Slice

- Slice: 03-restart-ready-test-language
- Depends on: 02-version-0-1-197

## Outcome

The focused macOS Desktop handoff test names use the same `restart-ready`
vocabulary as their assertions and the product contract.

## Scope

- Included: Rename only the stale Desktop handoff test descriptions in `WorkloadNodeUpdaterTest.php`.
- Excluded: Test logic, implementation, product docs, VERSION, and runtime behavior.

## Authority

- Decisions: The 2026-08-23 Desktop ownership decision names the user action “Restart to Update.”
- Product docs: `apps/docs/content/domains/11_operation/2_update-all/technical/1_update-all.md`.

## Proof

- Focused: RED from an exact search finding stale `pending automatic handoff` test titles; GREEN with no such titles, the focused test file passing, focused Mago format, and `git diff --check`.
