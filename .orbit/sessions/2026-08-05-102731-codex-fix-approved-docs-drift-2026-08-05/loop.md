# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: solo://proj/4/scratchpad/final-orbit-document--340
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-fix-approved-docs-drift-2026-08-05
- Branch: codex/fix-approved-docs-drift-2026-08-05

## Goal

All 14 approved remediation groups (A1–A11, B1, C1–C2), representing all 121 observations from the final audit (primary A1–A39/B1–B32/C1–C42 plus Codex M1–M8), are corrected in product documentation under `apps/docs/content/**`, with generated surfaces current and docs gates passing.

## Scope

- Owned: `apps/docs/content/**` plus generator/config inputs strictly required for those docs (Librarian sources, command catalog generators, owned indexes).
- Constraints: docs-only remediation; latest topical PRODUCT_DECISIONS.md wins; where ledger is silent document actual current behavior from code/tests; never hand-edit Librarian-generated README.md or concepts.md; apply Codex corrections (A33 terminology; B19 grant semantics only; C3 exclude valid gateway-first-version-gate; C36 via owned indexes/Librarian).
- Out of scope: runtime implementation, dependencies, E2E, releases, unrelated lint cleanup, other worktrees, merge/push/archive/worktree deletion.
- Source: solo://proj/4/scratchpad/final-orbit-document--340 (primary 338, review 339).

## Proof

- Verification:
  - focused: passed - composer docs-lint (0 errors; 189 content warnings plus unchanged 3 testing/3 scoped); tool:remove focused tests 5 passed (18 assertions); venue classifier 16 passed (16 assertions); git diff --check clean; generated README.md, concepts.md, and monorepo-unit-map.json byte-identical vs current main; manifests/locks untouched
  - broader: passed - composer quality-check and final-check at exact profile `.orbit/quality-gates/profiles/2026-08-05T08-23-55Z-edec022659aa/gateway_pest.junit.xml`
  - runtime: not applicable
- Blast radius: complete - evidence=full 121-observation audit, repository/code/test inventories, generated/manifests checks, Fable reviews, and venue classifier boundary (docs Librarian automation vs browser resources vs runtime retained-incus); result=all 14 groups and residuals resolved plus classifier source fix
- Review: passed - human-judgment=not-required
- Reviewed feature tip: edec022659aab0c8fd485ace4f6c51294f81b95c
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: edec022659aab0c8fd485ace4f6c51294f81b95c
- Accepted main tip: 263dcf6c42f5d4ad920ab0b7a01f960f9729b3c7

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
