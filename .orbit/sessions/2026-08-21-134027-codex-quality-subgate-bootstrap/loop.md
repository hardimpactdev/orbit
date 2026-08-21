# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: solo://proj/124
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-quality-subgate-bootstrap
- Branch: codex/quality-subgate-bootstrap

## Goal

Allow the LAND verifier to accept additive quality-check subgate evolution without allowing a candidate to remove or rename any subgate trusted by current main.

## Scope

- Owned: `bin/orbit-quality-gate-artifacts.php` and finalization-gate regression tests
- Constraints: preserve the current expected subgate set as a fail-closed floor; no manual E2E
- Out of scope: product behavior, deployment behavior, and changes to the quality checks themselves

## Proof

- Verification:
  - focused: passed - direct hook contract plus 269 finalization, artifact, and LAND tests with 1,168 assertions
  - broader: passed - composer quality-check 45/45 at `.orbit/quality-gates/quality-check-2026-08-21T113912Z-01094c54a44e.json`
  - runtime: not applicable
- Blast radius: complete - evidence=direct hook contract plus repository finalization, artifact, and LAND test inventory; result=current floor, additive declaration, producer alignment, stale artifact, malformed declaration, duplicate, removal, and failed-subgate paths covered
- Review: passed - Claude Opus 4.8 Solo process 2645 - human-judgment=not-required - exact-state security review found no actionable findings
- Reviewed feature tip: 00e14969f2b3586182dd859e2273a8e40274c745
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 00e14969f2b3586182dd859e2273a8e40274c745
- Accepted main tip: 96f066f09f3eb76f54b8203f0842c08a20e77032

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
