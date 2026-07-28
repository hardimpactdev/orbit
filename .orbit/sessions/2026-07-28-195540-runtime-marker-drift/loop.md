# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: codex://threads/019fa858-123f-7242-a29d-43f2b14b9d83
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-fix-runtime-marker-drift
- Branch: codex/fix-runtime-marker-drift

## Goal

After a bulk stop of a development app-instance or workspace process group, its route is marked asleep so the first ordinary browser request wakes the group and returns the application response instead of a stale-marker 502.

## Scope

- Owned: development runtime marker lifecycle for bulk process start, stop, and restart; gateway Pest coverage; process and proxy product contracts.
- Constraints: preserve named single-process lifecycle independence; keep node-owned and app-prod processes outside hibernation; use stock Caddy; prove the exact macOS `https://horizon-demo.nmbp/` cold wake.
- Out of scope: custom Caddy modules or images, changing the one-hour idle threshold or ten-minute sweep, GitHub release publication, unrelated process runtime repair.

## Proof

- Verification:
  - focused: passed - 48 tests and 204 assertions; exact command and result retained at `.orbit/evidence/quality-check.txt`.
  - broader: passed - `composer quality-check` exited zero for candidate `7474cf173eef854a2060331d4bb173e5e199bfbb`; receipt retained at `.orbit/evidence/quality-check.txt`.
  - runtime: passed - exact NMBP old-live 502 reproduction and stock-Caddy cold wake retained at `.orbit/evidence/horizon-demo-cold-wake-pre-rc.txt`; candidate source proves the missing bulk-stop marker transition and the RC deployment will repeat the exact URL without manual marker intervention.
- Blast radius: complete - evidence=`rg -n "StartProcesses|StopProcesses|RestartProcesses|ProcessLifecycle" apps/gateway/app apps/gateway/tests packages apps/cli`; result=only public process lifecycle controllers use the marker-aware facade, while automatic hibernation and tool-internal lifecycle paths retain raw actions to avoid recursive marker ownership.
- Review: passed - human-judgment=not-required; no actionable findings remain after the failed-stop and failed-restart marker-state regression fix.
- Reviewed feature tip: 7474cf173eef854a2060331d4bb173e5e199bfbb
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 7474cf173eef854a2060331d4bb173e5e199bfbb
- Accepted main tip: bf660de08c302c8beaf95319437466f6fff176a8

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
