# Orbit Feature Loop

- Scratchpad: none
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-process-logs-follow-history`
- Branch: `codex/process-logs-follow-history`

## Goal

`orbit process:logs --follow` shows historical prelude before later live lines, including idle websocket gaps and concurrent subscriber lease join after log-stream 202.

## Scope

- Owned: process-logs follow history (CLI idle reads, log-stream Content-Length, 1s subscriber grace), migration replay-safe, stop-decision target-agent auth, Mago style on those surfaces.
- Constraints: no `composer test:e2e*`; preserve unrelated work; no land/push/release until instructed.
- Out of scope: LAND/merge, archive, release until instructed.

## Proof

- Verification:
  - focused: passed - CLI 22 tests/75 assertions (RawOperationStreamWebSocketTransport + GatewayOperationStreamSubscriber + ProcessLogs + InternalProcessLogs); gateway 20 tests/160 assertions (ProcessLogStream + OperationStreamControlPlane + Valkey migration + label backfill) on clean HEAD `691d7edfc3c9785372c9ea3f9a4dd1d0542d03c1`
  - broader: passed - `composer quality-check` exit 0 dirty=false duration_seconds=73 all subgates 0; artifact `.orbit/quality-gates/quality-check-2026-08-05T123956Z-376c317f944c.json`; profile receipt file `.orbit/quality-gates/profiles/2026-08-05T12-38-43Z-691d7edfc3c9/cli_pest.log`; CLI 2436/10155; gateway 5693/34680
  - runtime: passed - candidate=691d7edfc3c9785372c9ea3f9a4dd1d0542d03c1; venue=retained-incus; environment=dev-6934a8 operator_gateway_app-dev host=beast; command=`ORBIT_GATEWAY_URL=http://10.6.0.2:8080 orbit process:logs follow-proof-9b76d195 --node=app-dev-1 --follow --lines=5`; expected=nonce HIST earlier PTY chunk than FOLLOW-LVE marker about 5s later with controlled capture stop and log-stream Content-Length near startup RTT; observed=hist_elapsed=2.11s follow_lve_elapsed=8.24s chunk_count=2 pass log-stream ttfb=total≈0.058s Content-Length=261 lease joined at follow start capture exit 129 idle after second chunk; result=passed; evidence=`.orbit/evidence/2026-08-05-process-logs-follow-dev-6934a8-n9b76d195-runtime-receipt.md`
- Blast radius: complete - evidence=independent final review process 1429 inventory of operation-stream auth/publish/stop-decision process-logs follow/transport and July Valkey migration replay surface vs main c825cead; result=no remaining actionable finding on exact tip 691d7edfc3c9785372c9ea3f9a4dd1d0542d03c1
- Review: passed - human-judgment=not-required - VERDICT=PASS BLAST_RADIUS=complete findings=none independent read-only review process 1429 of candidate 691d7edfc3c9785372c9ea3f9a4dd1d0542d03c1 against base/main c825cead10543a40d11a7513ebd855a6b8906f9c
- Reviewed feature tip: 691d7edfc3c9785372c9ea3f9a4dd1d0542d03c1
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 691d7edfc3c9785372c9ea3f9a4dd1d0542d03c1
- Accepted main tip: c825cead10543a40d11a7513ebd855a6b8906f9c

## Status

- State: land
- Blocker: none
- Feature tip: 691d7edfc3c9785372c9ea3f9a4dd1d0542d03c1
- Superseded tip 31ad24ace (pre-main-merge) retained as non-cited historical context only; current archive cites 691d7edfc receipts only

## Feedback

- Events: `.orbit/feedback.jsonl`
