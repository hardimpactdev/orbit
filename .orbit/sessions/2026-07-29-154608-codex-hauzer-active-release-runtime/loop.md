# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: Codex task 019fa8f4-96e7-70d3-b9a0-e52f607f84df
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-hauzer-active-release-runtime
- Branch: codex/hauzer-active-release-runtime

## Goal

After a release-aware production deploy activates its `live` symlink, Orbit
validates that target inside the app boundary, re-renders FrankenPHP to execute
the active release, and runs built-in warmup against that same release.

## Scope

- Owned: deploy active-release transition, app runtime container working
  directory/base path, internal path-containment probe, focused docs and Pest
  coverage.
- Constraints: preserve the restored Hauzer production `.env`; fail closed on
  symlinks outside the instance app boundary; do not edit Hauzer code; no
  GitHub release.
- Out of scope: changing Hauzer deploy steps, queue-process commands, unrelated
  deployment policy, or environment values.

## Proof

- Verification:
  - focused: passed - 79 Pest tests / 337 assertions across CLI and gateway;
    latest DeployManager slice passed 26 tests / 130 assertions; targeted Mago
    formatting passed.
  - broader: passed - CLI 224 tests / 495 assertions and gateway 417 tests / 2446
    assertions passed across all identified runtime consumers; the review-fix
    slice passed 27 tests / 157 assertions; exact feature tip passed
    `composer quality-check` with receipt
    `.orbit/quality-gates/quality-check-2026-07-29T134348Z-a176c13a39c4.json`.
  - runtime: passed - topology=dev-16cbe4; evidence=`.orbit/evidence/runtime-proof.txt`; result=two consecutive active-release deploys completed, workdir/env/container cwd pointed at each live release, warmup cache was ready, and the unchanged container restarted without replacement.
- Blast radius: complete - evidence=`rg -l "AppRuntimeContainer|AppRuntimeContainerManager|AppRuntimeContainerRenderer|LocalAppRuntimeContainerSpec|app-source-path:probe|DeployManager" apps/cli/tests apps/gateway/tests`; result=active-release runtime convergence, restart, warmup ordering, fail-closed containment, and unaffected static progress are covered with no unresolved surface.
- Review: passed - human-judgment=not-required; evidence=independent
  exact-tip review closed both prior findings with no remaining findings.
- Reviewed feature tip: 31ce0fcbbcdedd0a3b4c11799f21f4d4c9afab53
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 31ce0fcbbcdedd0a3b4c11799f21f4d4c9afab53
- Accepted main tip: c8d0a7030071709576a2d11dbd8c67f28e7cd8b5

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`

Allowed states are `frame`, `build`, `prove`, `accept`, `accepted`, `land`, and
`blocked`. Review must be `passed`, `fix`, or `escalate`. Acceptance must be
`pending`, `accepted`, `not-applicable`, or `changes-requested`. A terminal
review PASS must record `human-judgment=required|not-required` and the exact
committed HEAD in `Reviewed feature tip`. Blast radius must be `not-required`
with a reason or `complete` with repository evidence and a result summary
before acceptance; `gaps` returns to BUILD.
Proof files retained by the compact archive must be cited as one exact
inline-code path, for example `.orbit/evidence/runtime-proof.txt`; prose,
directories, padded code spans, and partial paths are not proof citations.
