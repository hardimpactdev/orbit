# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Session: feat-codex-beast-flow-main-integration
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-beast-flow-main-integration
- Branch: codex/beast-flow-main-integration

## Goal

Integrate the exact Beast-proven worker-flow candidate
`1eeefff1c131dd1cde683ddc7dc3ce93f94cb4fe` onto the current local main
`baaee77ac38d629848f6c024a69ddd4d6ff0afdd`, preserve the concurrent firewall
feature, revalidate the combined candidate once, and land it cleanly on local
main. The resulting flow must launch all agents without permission prompts,
use Grok medium reasoning for implementation, treat `.orbit/loop.md` as the
goal authority, and use the worker brief only as the assignment.

## Scope

- Owned: merge the exact Beast feature tip into this current-main integration
  branch; resolve only concrete integration conflicts; prove the combined
  candidate; fresh diff-first Claude Opus-high review; exact-SHA acceptance;
  resumable LAND; clean worker and worktree cleanup.
- Constraints: Grok must launch as `grok --yolo --reasoning-effort medium`;
  Claude must launch with `--dangerously-skip-permissions`; Codex remains
  `codex --yolo`; `.orbit/loop.md` is authoritative; one terminal quality gate
  per immutable candidate; reviewers consume its SHA-bound receipt and run
  only targeted additional checks for a concrete concern; inspect rendered
  tmux output to distinguish progress from a blocker; never intervene based on
  elapsed time or absence of a diff alone; preserve exact candidate identity,
  the concurrent firewall commits, unrelated worktrees, fail-closed cleanup,
  resumability, and the human-only E2E boundary; do not push.
- Out of scope: new product behavior, release/deployment, automated E2E,
  unrelated polish, rewriting either parent history, or changes to the real
  Beast primary checkout `/home/nckrtl/orbit`.

## Proof

- Verification:
  - focused: passed - worker/bootstrap and proof-receipt portability tests 49 tests 265 assertions; archive runtime inference 108 tests 1469 assertions; both current-main and Beast tips remain ancestors; concurrent firewall files are byte-identical to current-main parent
  - broader: passed - exact clean candidate `ebc693f09fbcc522fc60103986fdfaf84214fd28` passed implementer-owned `composer quality-check`; evidence `.orbit/quality-gates/quality-check-2026-08-23T000458Z-1286e0694971.json`; exit 0; 110 seconds; no failed subgates
  - runtime: not applicable
- Blast radius: complete - evidence=fresh Opus-high diff-first review plus targeted delta review across both integration parents, launcher/bootstrap contracts, receipt reuse, transcript identity and archive inference, event consumption, macOS/Linux portability, docs, and concurrent firewall paths; result=no affected surface unresolved and concurrent firewall files byte-identical to current-main parent
- Review: passed - reviewer=Claude Opus-high review-1; exact-tip delta review; no broad gate rerun; concrete transcript archive defect closed; no blocking defects; remaining findings classified POLISH; human-judgment=not-required
- Reviewed feature tip: ebc693f09fbcc522fc60103986fdfaf84214fd28
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: ebc693f09fbcc522fc60103986fdfaf84214fd28
- Accepted main tip: baaee77ac38d629848f6c024a69ddd4d6ff0afdd

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
