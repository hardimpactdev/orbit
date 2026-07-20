# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/4/scratchpad/docs-audit-final--325`; user-approved remediation in the current Codex task
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-fix-docs-audit-drift
- Branch: codex/fix-docs-audit-drift

## Goal

Align Orbit's product and command documentation with all 22 approved docs-drift
findings, recording Ubuntu-only platform support and a confirmed cascading
`app:remove --force` contract, then prove the resulting documentation is one
coherent, lint-clean contract.

## Scope

- Owned: `PRODUCT_DECISIONS.md`; the approved documentation seams under
  `apps/docs/content/**`; the narrow docs-linter entity schema and focused tests
  required to validate the approved logical-app contract; generated Librarian
  or command-catalog artifacts only when their native builders require updates.
- Constraints: Ubuntu is Orbit's only supported host platform for now;
  `app:remove` asks before removing existing instances and `--force` supplies
  non-interactive destructive consent; preserve unrelated state; use the exact
  approved app-instance ownership, local-only command, credential custody,
  no-legacy, VPN, DNS, and tool-baseline directions; never run or delegate a
  `composer test:e2e*` command.
- Out of scope: runtime PHP, Rust, JavaScript, migrations, or runtime behavior
  changes; unrelated documentation cleanup; deployment or live-node mutation.

## Proof

- Verification:
  - focused: passed - docs lint had zero errors and current generated indexes; docs Pest passed 111 tests with 980 assertions; focused Mago and diff check passed
  - broader: passed - `composer quality-check` passed every repository quality lane at the exact reviewed feature tip; evidence `.orbit/quality-gates/quality-check-2026-07-20T000454Z-126d021bcd96.json`
  - runtime: passed - focused Pest and full docs lint exercised the docs validator; Orbit product runtime was not changed
- Blast radius: complete - evidence=repository-wide searches for platform, consent, app ownership, transport, activity, credential custody, and tool baselines plus docs lint and generated inventories; result=no active contract contradictions remain, with Debian only in superseded ledger history and upstream PHP image descriptions
- Review: passed - independent exact-tip review; human-judgment=not-required
- Reviewed feature tip: 9671e41258243390c7b3dbc0ffdc94eb77a4e967
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 9671e41258243390c7b3dbc0ffdc94eb77a4e967
- Accepted main tip: 5ab595ef681b30a3148aeeab0c95473483dbd1fa

## Status

- State: land
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
- Promotion: `promotion-20260719234425-3836dcffa2fa` protects the Ubuntu-only
  host and confirmed app-instance cascade-removal decisions in
  `PRODUCT_DECISIONS.md`.
