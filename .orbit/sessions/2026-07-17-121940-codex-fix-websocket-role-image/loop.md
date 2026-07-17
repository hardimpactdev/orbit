# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: /Users/nckrtl/.codex/worktrees/fix-websocket-role-image
- Branch: codex/fix-websocket-role-image

## Goal

Deploy the websocket role on services1 using the selected digest-pinned Reverb
image and prove that the runtime is healthy while using Redis on database1.

## Scope

- Owned: fresh-node websocket image convergence, focused CLI/gateway tests,
  command docs, live services1 deployment, and retained runtime evidence.
- Constraints: keep the existing source-checkout fallback; use fixed-argv
  target-local execution; do not widen into unrelated fleet update failures.
- Out of scope: beast Agent reachability and general fleet artifact drift.

## Proof

- Verification:
  - focused: passed - CLI 15 passed/115 assertions; gateway runtime/manifest 25 passed/96 assertions; scoped Mago and Rector passed; docs lint and diff check passed.
  - broader: passed - exact-tip `ORBIT_QUALITY_CHECK_CPU_BUDGET=1 composer quality-check`; receipt `.orbit/quality-gates/quality-check-2026-07-17T101744Z-a20208499686.json`
  - runtime: passed - retained Incus topology dev-d4e4eb installed the candidate archive, reached active with Redis, used image sha256:7f26c9a4320aeb83d4a997d6d2341713e0c4ef9fe82ae133a32c1b553c9d797a without source binds, and returned HTTPS 404; evidence `.orbit/evidence/runtime-proof.txt`
- Blast radius: complete - evidence=`rg -n "orbit-reverb:current|orbit-websocket|image:is-self-contained|role_images" apps packages bin`; result=websocket alias consumers plus release-manifest parsing and generation enforce the digest-pinned boundary while absent websocket image entries preserve source-checkout fallback.
- Review: passed - findings=none; human-judgment=not-required
- Reviewed feature tip: 899be5b26e1a00b7c61b01c98d920c76d420aaf9
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 899be5b26e1a00b7c61b01c98d920c76d420aaf9
- Accepted main tip: 7741a5524d574d805f8f5d5cc85c892a6504c86b

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`

Allowed states are `frame`, `build`, `prove`, `accept`, `accepted`, `land`, and
`blocked`. Review must be `passed`, `fix`, or `escalate`. Acceptance must be
`pending`, `accepted`, `not-applicable`, or `changes-requested`. A terminal
review PASS must record `human-judgment=required|not-required` and the exact
committed HEAD in `Reviewed feature tip`. Blast radius must be `not-required -
a reason` or `complete - evidence=repository-wide search, inventory, or
lintable check; result=summary` before acceptance; `gaps` returns to BUILD.
Proof files retained by the compact archive must be cited as one exact
inline-code path, for example `.orbit/evidence/runtime-proof.txt`; prose,
directories, padded code spans, and partial paths are not proof citations.
