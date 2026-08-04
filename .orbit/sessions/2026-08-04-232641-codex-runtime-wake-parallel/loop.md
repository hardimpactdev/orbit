# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-runtime-wake-parallel`
- Branch: `codex-runtime-wake-parallel`

## Goal

Soft and cold development runtime activation start every configured lifecycle
process concurrently, then mark the scope awake only after a bounded aggregate
readiness check observes every expected runtime unit running; activation page
background probes use a one-second non-overlapping cadence. Public
`process:start` process-order semantics stay unchanged. No process-dependency
model is introduced.

## Scope

- Owned:
  - `apps/gateway/app/Services/Processes/RuntimeHibernation.php` and related
    runtime-activation start/readiness helpers
  - `apps/gateway/app/Services/Processes/RuntimeActivationPage.php` poll cadence
  - focused gateway Pest coverage for concurrent starts, aggregate readiness,
    failure cleanup, and 1s poll assertions
  - `PRODUCT_DECISIONS.md` and authoritative runtime wake docs under
    `apps/docs/content/`
- Constraints:
  - Concurrent starts only on the internal runtime activation path
  - Durable process-event DB writes stay in the parent runner
  - Aggregate readiness is bounded/retried; start-command exit alone is
    insufficient for awake marking
  - Keep activation serialization/fencing and already-awake fast path
  - Overlap tests use a deterministic barrier/shared observation
  - No `composer test:e2e*`
  - Preserve unrelated work; no merge/push/accept/archive/cleanup
- Out of scope:
  - Process dependency configuration/graphs/levels
  - Public bulk `process:start` process-order changes
  - Browser visual redesign beyond the 1s cadence assertion update

## Proof

- Verification:
  - focused: passed - ProcessRuntimeWakeConcurrentRunner + RuntimeHibernation/ColdActivation + ProcessKeyLabel; CLI process + is-active
  - broader: passed - composer quality-check at 02b2b43c024e5e168d0104e77933daa021b2b171; `.orbit/quality-gates/quality-check-2026-08-04T211132Z-d6b4a464bf48.json`
  - runtime: passed - `.orbit/evidence/runtime-wake-parallel-retained-incus.md`
- Blast radius: complete - evidence=repo-wide search of ProcessRuntimeDriver/isRunning, RuntimeWake/ProcessRuntimeWake, process dependency graph/level surface, StartProcesses callers, and process key/label merge interaction; result=shared driver + product decision + CLI is-active + wake-only concurrency; public StartProcesses order and dependency graphs unchanged; label is display-only vs runtime unit identity
- Review: passed - human-judgment=not-required
- Reviewed feature tip: 02b2b43c024e5e168d0104e77933daa021b2b171
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 02b2b43c024e5e168d0104e77933daa021b2b171
- Accepted main tip: 64aab716cea3523c0b84d59992410de148dff0f3
- Candidate tip: `02b2b43c024e5e168d0104e77933daa021b2b171` (merge of `2a81034ce` + main `64aab716cea3523c0b84d59992410de148dff0f3`)
- Merged main tip: `64aab716cea3523c0b84d59992410de148dff0f3`
- Retained topology (held for acceptance):
  - id=`dev-bea81b` kind=`operator_gateway_app-dev` provider=`incus` host=`beast`
  - roles=operator,gateway,dev checkout=`/home/orbit/orbit-run`
  - proof op=`d813fe7d-c507-4d9a-9750-86cbe11193b0` soft succeeded
  - evidence: `.orbit/evidence/runtime-wake-parallel-retained-incus.md`
  - release: `composer e2e:incus -- --stop --id=dev-bea81b` (not run)

## Status

- State: land
- Blocker: none


## Feedback

- Events: `.orbit/feedback.jsonl`

Allowed states are `frame`, `build`, `prove`, `accept`, `accepted`, `land`, and
`blocked`. Review must be `passed`, `fix`, or `escalate`. Acceptance must be
`pending`, `accepted`, `not-applicable`, or `changes-requested`. A terminal
review PASS must record `human-judgment=required|not-required` and the exact
committed HEAD in Reviewed feature tip. Blast radius must be
`not-required - reason` or
`complete - evidence=...; result=...` before acceptance; `gaps` returns to BUILD.
Proof files retained by the compact archive must be cited as one exact
inline-code path under evidence or quality-gates; prose, directories, padded
code spans, and partial paths are not proof citations.
