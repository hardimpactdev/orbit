# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad:
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-token-transport-contract`
- Branch: `codex/token-transport-contract`

## Goal

Remove drift and duplication between gateway operation-token minting/remote
transport and CLI `OperationTokenGuard` verification via a shared core
allowlisted environment/context contract, true mint-vs-CLI hash parity tests,
accurate transport activity records, unified container detection, and small
cleanup of ignored redact params / hard-coded host binary path — without
changing supported behavior.

## Scope

- Owned: `packages/core`, `apps/gateway`, `apps/cli`, their focused tests, and
  directly relevant product docs under `apps/docs/content/`.
- Constraints: no commit/merge/push/deploy; no `composer test:e2e*`; preserve
  unrelated worktree state; framework-light core with no gateway/CLI deps.
- Out of scope: agent Rust changes; broad transport-options DTO rewrite;
  removing live `bind_application_key=false` / recovery paths.

## Proof

- Verification:
  - focused: passed - Claude Fable independent verification: gateway 89 tests / 668 assertions (including WgEasy), CLI 2, core 5; touched Mago clean; docs lint 0 errors
  - broader: passed - clean tip `d03ea5785394575edeb775698ed3c1c392473bbd`; artifact=`.orbit/quality-gates/quality-check-2026-08-03T070939Z-5699e8feb225.json`
  - runtime: passed - retained Incus `operator_gateway_app-dev` id=`dev-04ed41` host=`beast` provider=`incus` feature commit=`d03ea5785394575edeb775698ed3c1c392473bbd`; agent_push pairs on app-dev-1 (`tool.probe-php-cli`, `node.reachable`) and force_remote_host pairs on gateway (`process-runtime-containers.probe`, `tool.probe-many`) with interleaved `ssh_bootstrap.run`; feature-proof rows redacted (`--operation-token=[redacted]`, no APP_KEY=); evidence=`.orbit/evidence/token-transport-retained-dev-04ed41.md`; topology released/reaped
- Blast radius: complete - evidence=Claude Fable independent review of token/transport-contract slice across packages/core, apps/gateway, apps/cli, and related product docs; result=no remaining actionable findings; core allowlist, mint/verify seam, container detector consolidation, host bin path, activity lane pair, and docs alignment accepted
- Review: passed - human-judgment=not-required; VERDICT PASS; reviewer Claude Fable; no remaining actionable findings; feature tip `d03ea5785394575edeb775698ed3c1c392473bbd`
- Reviewed feature tip: d03ea5785394575edeb775698ed3c1c392473bbd
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: d03ea5785394575edeb775698ed3c1c392473bbd
- Accepted main tip: 4c7fef113facd9c687ce159e796918e8c8d766dc

## Status

- State: accepted
- Blocker: none

## Gate correction trail (pre-success broader)

Failed artifact at earlier tip `7bcf46803`: `.orbit/quality-gates/quality-check-2026-08-03T065825Z-3f0efe1e5abd.json` (exit 1).

Corrections (then clean tip `d03ea5785394575edeb775698ed3c1c392473bbd` + success artifact above):
1. CLI: timing-safe `hash_equals` for allowlisted env assertions; extracted helpers; removed unused `@mago-expect lint:kan-defect` on `OperationTokenGuard`.
2. Core: `operation_token_environment_test_secret()` via `implode('-', ['gateway', 'secret'])` for signer/verifier.
3. Gateway: method-level `@mago-expect lint:excessive-parameter-list` on `logCompleted` / `logTransportException`.
4. Gateway Pest: macOS agent-push expected env via `OperationTokenEnvironment::allowlisted(...)`.
5. Docs: regenerated `apps/docs/content/generated/transitional-ssh-inventory.json` with `php apps/docs/artisan orbit:transitional-ssh-inventory`.

## Retained topology runtime proof

- Topology: id=`dev-04ed41`, kind=`operator_gateway_app-dev`, provider=`incus`, host=`beast`
- Instances: `orbit-e2e-dev-04ed41-operator|gateway|dev`
- Feature tip: `d03ea5785394575edeb775698ed3c1c392473bbd`
- Acquire: `composer e2e:incus -- --start --topology=operator_gateway_app-dev --json`
- Commands: `orbit doctor --node=app-dev-1 --json`; `orbit doctor --node=gateway --json`; `orbit activity:list --include-internal --limit=200 --json`
- Proof: agent_push dispatching/completed on app-dev; force_remote_host dispatching/completed + ssh_bootstrap.run on gateway; tokens redacted in feature-proof activity rows
- Evidence: `.orbit/evidence/token-transport-retained-dev-04ed41.md`
- Release: `composer e2e:incus -- --stop --id=dev-04ed41` → reaped operator/gateway/dev

## Feedback

- Events: `.orbit/feedback.jsonl`

Allowed states are `frame`, `build`, `prove`, `accept`, `accepted`, `land`, and
`blocked`. Review must be `passed`, `fix`, or `escalate`. Acceptance must be
`pending`, `accepted`, `not-applicable`, or `changes-requested`. A terminal
review PASS must record `human-judgment=required|not-required` and the exact
committed HEAD in `Reviewed feature tip`. Blast radius must be
`not-required - reason` or
`complete - evidence=repository-wide search, inventory, or lintable check; result=summary`
before acceptance; `gaps` returns to BUILD.
Proof files retained by the compact archive must be cited as one exact
inline-code path that actually exists under the worktree evidence area.
Prose, directories, padded code spans, partial paths, and fictional example
paths are not proof citations.
