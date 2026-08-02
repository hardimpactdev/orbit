# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-fix-gateway-host-doctor-token`
- Branch: `codex/fix-gateway-host-doctor-token`

## Goal

`RemoteLocalExecutor::runGatewayLocal` must not locally authorize/consume the
one-use operation token when `force_remote_host` is true, so the gateway host
CLI remains the sole verifier/consumer. Ordinary gateway-container local
execution still authorizes locally and injects trusted execution context.

Live regression context (candidate `20260802T145313Z-8f54774b5`, pre-fix):
- `orbit doctor --node=gateway --json`
- `orbit doctor --node=ingress1 --json`
Both failed with RemoteShell / `invalid_token` ("Operation token is invalid.")
because container-side pre-auth consumed the token before host CLI verify.

Do not claim live proof after this code fix; deploy/verify is owned elsewhere.

## Scope

- Owned: `apps/gateway/app/Services/RemoteShell/RemoteLocalExecutor.php`,
  `apps/gateway/tests/Unit/Services/RemoteShell/RemoteLocalExecutorTest.php`,
  `apps/docs/content/generated/transitional-ssh-inventory.json`,
  `.orbit/loop.md`
- Constraints: TDD; smallest production change; focused Pest + Mago format;
  no `composer test:e2e*`; preserve unrelated work; commit on this branch;
  regenerate SSH inventory via docs artisan (do not hand-edit)
- Out of scope: live retained-topology deploy proof; E2E lanes; new docs unless
  execution-lane contract would become inaccurate

## Proof

- Verification:
  - focused: passed
    - red: `bin/orbit-gateway-pest --compact --filter='does not pre-authorize force_remote_host'` failed with `operation_token_consumed_at` not null before fix
    - green: same filter + default gateway-local authorize test + full `RemoteLocalExecutorTest.php` (46 passed)
    - mago: `bin/orbit-gateway-vendor-bin mago format --check app/Services/RemoteShell/RemoteLocalExecutor.php tests/Unit/Services/RemoteShell/RemoteLocalExecutorTest.php`
    - quality-gate finding: docs Pest / `orbit:transitional-ssh-inventory --check` failed after `ddcdfc82f` because production edit shifted `RemoteLocalExecutor` provisioning-ssh marker/call lines; committed inventory was stale (was `call_line` 1382 / `marker_line` 1381; live scan `1374` / `1373`)
    - regeneration: `bin/orbit-docs-artisan orbit:transitional-ssh-inventory` updated `apps/docs/content/generated/transitional-ssh-inventory.json` only for that entry
    - recheck: `bin/orbit-docs-artisan orbit:transitional-ssh-inventory --check` up to date; `bin/orbit-docs-pest --compact --filter=TransitionalSshInventory` 21 passed; focused executor tests 3 passed
  - broader: passed - clean exact quality receipt `.orbit/quality-gates/quality-check-2026-08-02T152022Z-27a0d388cf2e.json` for commit `0c11e8e44943da7cd1a588375fbdd41ef3ed814a`, exit 0, duration 233s, git dirty false
  - runtime: passed - automated host-boundary runtime simulation in the focused RemoteLocalExecutor regression test; force_remote_host dispatched through RemoteHostExecutor with the operation run token unconsumed before the fake host CLI boundary, while the ordinary gateway-local path still consumed/authorized; immediate post-merge live candidate verification remains required
- Blast radius: complete - evidence=force_remote_host versus ordinary gateway-local path review in `RemoteLocalExecutor::runGatewayLocal` plus regenerated transitional SSH inventory for the provisioning-ssh host-dispatch marker; result=host CLI remains sole token consumer on force_remote_host, local authorize/trusted-execution retained for ordinary gateway-local, and inventory line numbers match live scan
- Review: passed - independent exact-tip review of `0c11e8e44943da7cd1a588375fbdd41ef3ed814a` found no findings - human-judgment=not-required
- Reviewed feature tip: 0c11e8e44943da7cd1a588375fbdd41ef3ed814a
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 0c11e8e44943da7cd1a588375fbdd41ef3ed814a
- Accepted main tip: 8f54774b5420d3085c9a59f830772cd9320db22e

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
