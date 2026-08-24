# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Session: feat-codex-mandatory-slice-native-loop
- Worktree: /home/nckrtl/orbit/.worktrees/codex-mandatory-slice-native-loop
- Branch: codex/mandatory-slice-native-loop

## Goal

Orbit requires one or more dependency-aware vertical slices, uses one fresh
native `gpt-5.6-luna` worker at `reasoning_effort=low` per slice, retains one
feature-level proof and one Claude review, and archives slice checkpoints
safely.

## Scope

- Owned: Mandatory slice FRAME artifacts and methods; phase-aware slice parsing
  and gates; checkpoint ancestry and compact archive schema 4; the native Luna
  BUILD contract; feedback slice context; the graph; all active agent and skill
  descriptions; tests; and a dated product decision.
- Constraints: Use the exact Beast worktree
  `/home/nckrtl/orbit/.worktrees/codex-mandatory-slice-native-loop`. Keep one writer. Human-only
  integrated lanes stay forbidden. Use one singular diff-routed proof venue.
  Do not add slice handoffs or persisted proving state. Keep schemas 2 and 3
  readable. Keep the instruction total at or below 35,600 bytes. Preserve
  dormant Grok tools and the global coder role.
- Out of scope: Multi-venue acceptance; external ticket services; semantic
  graders; a new lifecycle command; removing dormant Grok tooling; changing the
  global coder role.

## Slices

| Slice | State | Checkpoint |
| --- | --- | --- |
| `.orbit/slices/01-frame-artifacts.md` | complete | 59f8ac7fde7bcd1c55631dbb7e9b178d477e9b7f |
| `.orbit/slices/02-slice-contract.md` | complete | c08a287cacfd7a57eb27072d3d3f5b779a93a5b5 |
| `.orbit/slices/03-finalization-archive.md` | complete | 946ddc2e8c3c6107877e4323cddec83f452687f9 |
| `.orbit/slices/04-native-luna-flow.md` | complete | b7c796a93685f55e094b8ec568e01d4c84f9ac99 |

## Proof

- Verification:
  - focused: passed - candidate=b7c796a93685f55e094b8ec568e01d4c84f9ac99; command=`bin/orbit-gateway-pest` 12-file feature set; observed=813 tests and 4,187 assertions; result=passed
  - broader: passed - candidate=b7c796a93685f55e094b8ec568e01d4c84f9ac99; command=`composer quality-check`; observed=all subgates exit 0; result=passed; evidence=`.orbit/quality-gates/quality-check-2026-08-24T044618Z-d29ecfa74d6b.json`
  - runtime: passed - candidate=b7c796a93685f55e094b8ec568e01d4c84f9ac99; venue=retained-incus; environment=dev-fixture; target=mandatory slice FRAME instruction, worktree preparation, and invalid implementation-worker graph gate; expected=instruction is present, preparation passes, and invalid graph rejects before mutation; observed=retained checkout digest matched, preparation passed, and graph gate rejected before registry/log/tmux mutation; result=passed; evidence=`.orbit/evidence/mandatory-slice-native-loop-b7c796a.md`
- Blast radius: complete - evidence=review-1 bounded sweep of stale lifecycle wording, schema readers `[2, 3, 4]`, product-doc vocabulary, and active Grok/global coder surfaces; result=FRAME gap closed and no affected surface remains unresolved
- Review: passed - Claude general reviewer review-1 - human-judgment=not-required
- Reviewed feature tip: b7c796a93685f55e094b8ec568e01d4c84f9ac99
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: b7c796a93685f55e094b8ec568e01d4c84f9ac99
- Accepted main tip: 017504f4657c920c2c1cccbf602a63c52cbf0f9a

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
