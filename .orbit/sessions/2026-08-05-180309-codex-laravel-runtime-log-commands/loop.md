# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `/Users/nckrtl/shared-knowledge/projects/orbit/superpowers/specs/2026-08-05-laravel-runtime-log-commands-design.md`
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-laravel-runtime-log-commands`
- Branch: `codex/laravel-runtime-log-commands`

## Goal

Ship the approved Laravel runtime log command contract: fixed-path application logs for App/Instance/Workspace targets (`app:log`, `instance:log`, `workspace:log`), rename process/lifecycle log surfaces to singular `process:log` and `workspace:run:log` with atomic permission/grant migration and no aliases, and land matching gateway routes, SDKs, OpenAPI, docs, catalogs, and tests from base `10833ea27f23e85b0f3f469257732c5a5d8f1cb6`.

## Scope

- Owned: CLI commands/actions/resolvers; gateway application-log routes + authorization + grant migration; agent/on-node transport reuse for bounded/follow with history-before-live; PHP/TS SDK + OpenAPI; product docs/decision ledger/catalogs/bundled skill; permissions matrix; focused tests. primitive=laravel-application-log-read; transitions=success:bounded-or-follow-success|failure:validation-auth-path-node-unavailable|retry:n/a|stop-restart:follow-disconnect-terminal|stale:missing-file-empty-success
- Constraints: Exact design authority; no aliases/dual tokens; no absolute host paths in public payloads; `--node` placement constraint only; no E2E composer lanes; no primary checkout / live node / push / merge / release work; feedback surface `laravel-runtime-log-commands` returned `[]`.
- Out of scope: Loki/retention; arbitrary path reads; multi-file channels; process HTTP route shape changes.

## Proof

- Verification:
  - focused: passed - merge tip includes prior focused green plus process-bind main merge; revalidated via quality-check 45/45
  - broader: passed - composer quality-check exit 0, 45/45 subgates, dirty false, artifact `.orbit/quality-gates/quality-check-2026-08-05T155459Z-7c6041b66b4f.json`
  - runtime: passed - candidate=a130a843b989e8746e90a7c2e52ec411c80de6b6; venue=retained-incus; environment=dev-fixture; expected=after main merge sync, instance/workspace/app hostname JSON, wrong --node, instance and workspace follow history-before-marker; observed=dev-36c3ac synced to merge tip, compact set passed with markers INST-FOLLOW-20260805T155607Z-a130a843 and WS-FOLLOW-20260805T155607Z-a130a843; result=passed; command=`orbit workspace:log feature-logproof --instance=logdemo.development --follow`; evidence=`.orbit/evidence/laravel-runtime-log-commands-dev-36c3ac/RECEIPT.json`
- Blast radius: complete - evidence=repository search of public command names process:logs/workspace:log renames plus quality-check and retained topology proof; result=public renames present, old process:logs absent, application-log surfaces proven on retained-incus
- Review: passed - human-judgment=not-required - independent terminal review FINDINGS=none BLAST_RADIUS=complete VERDICT=PASS for feature a130a843b989e8746e90a7c2e52ec411c80de6b6 against main 117d4735108f16fe541930d41f2ffc11b2d82ae7
- Reviewed feature tip: a130a843b989e8746e90a7c2e52ec411c80de6b6
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: a130a843b989e8746e90a7c2e52ec411c80de6b6
- Accepted main tip: 117d4735108f16fe541930d41f2ffc11b2d82ae7

## Status

- State: accepted
- Blocker: none
- Topology retained: dev-36c3ac (do not stop yet); Solo terminal process_id=1450 runtime-log-proof

## Feedback

- Events: `.orbit/feedback.jsonl`
