# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/123/scratchpad/orbit-audit-typescri--528`
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-audit-typescript-docs-skill`
- Branch: `codex/audit-typescript-docs-skill`

## Goal

The TypeScript SDK normalizes gateway API URLs safely, derives duplicated value types from one source, the repository quality gate runs the SDK runtime tests, the generated monorepo map includes the TypeScript SDK, and the Orbit skill preserves intentional documented native command aliases.

## Scope

- Owned: `packages/sdk-typescript/**`, the TypeScript SDK subgates in `bin/quality-check.sh`, the finalization hook, and their contract tests, the docs monorepo-unit-map builder and its tests/generated output, root `README.md`, and `.agents/skills/orbit/SKILL.md`.
- Constraints: TDD for behavior changes; generated docs stay generator-owned; product authority and latest decisions outrank generated discovery signatures; preserve public `process:add --version`; no unrelated cleanup; no manual E2E.
- Out of scope: new SDK features, command renames, product-direction changes, dependencies, UI, and unrelated documentation drift.

## Proof

- Candidate: 97dade62e73409a8cd0023474f45c6706a53bcf3
- Verification:
  - focused: passed - `cd packages/sdk-typescript && npm test && npm run build` (19/19 runtime tests plus typecheck/build); monorepo map test (20 tests, 151 assertions), freshness check, and docs lint; direct pre-tool hook contract; gateway verification/finalization/artifact/LAND inventory (311 tests, 1,663 assertions)
  - broader: passed - `composer quality-check` exit 0 with all 46 subgates green, bound to exact clean candidate `97dade62e73409a8cd0023474f45c6706a53bcf3`; artifact `.orbit/quality-gates/quality-check-2026-08-21T114537Z-d262c2bb6487.json`
  - runtime: not applicable - the executable acceptance router derives `automated` for the complete exact-candidate diff
- Required verification:
  - `composer quality-check`: passed - exact clean candidate `97dade62e73409a8cd0023474f45c6706a53bcf3`; dirty=false; exit=0; all 46 subgates zero; artifact `.orbit/quality-gates/quality-check-2026-08-21T114537Z-d262c2bb6487.json`
- Blast radius: complete - evidence=exact main-to-candidate diff inventory, generated-map freshness, TypeScript runtime/type checks, and executable quality-label alignment; result=SDK behavior, docs, skill guidance, quality producer, shared verifier declaration, and owning tests remain aligned after main integration
- Review: passed - independent Claude Opus 4.8 Solo process 2644 reviewed exact main-to-candidate diff; findings none; blast radius complete; human-judgment=not-required
- Reviewed feature tip: 97dade62e73409a8cd0023474f45c6706a53bcf3
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 97dade62e73409a8cd0023474f45c6706a53bcf3
- Accepted main tip: 772a8880560fa6192ad8d56455322775cc31f220

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
