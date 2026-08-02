# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-orphaned-workspace-route-repair`
- Branch: `codex/orphaned-workspace-route-repair`

## Goal

`orbit proxy:remove DOMAIN --force` removes gateway proxy route rows whose
recorded owner is proven missing (orphan), while still denying removal of
routes with a living owner. Output states what was removed and why it was safe.
Docs, tests, and code stay aligned.

## Scope

- Owned:
  - `apps/gateway/app/Services/Proxy/ProxyRouteIntent.php`
  - `apps/gateway/tests/Unit/Services/Proxy/ProxyRouteIntentTest.php`
  - `apps/gateway/tests/Feature/Http/Api/ProxyRouteMutationControllerTest.php`
  - `apps/cli/app/Commands/Proxy/ProxyRemoveCommand.php`
  - `apps/cli/tests/Feature/Commands/Proxy/ProxyWriteCommandTest.php`
  - `apps/docs/content/domains/8_proxy/3_proxy-remove/**`
  - `apps/docs/content/domains/8_proxy/README.md`
  - `apps/docs/content/domains/8_proxy/proxy-doctor.md`
  - `.orbit/loop.md`
  - `.orbit/evidence/orphan-proxy-remove-contract-proof.md`
- Constraints:
  - Preserve ownership protection for living owners
  - Narrow orphan bypass only; no general `--force` ownership bypass
  - No E2E Composer commands; no live topology mutation; no release
  - Preserve unrelated work across checkouts and worktrees
- Out of scope:
  - doctor `--restore` for `proxy.owner_invalid`
  - live Beast craft-starterkit-react repair
  - release

## Proof

- Verification:
  - focused: passed - gateway Pest 17 tests (`ProxyRouteIntent` + mutation API); CLI Pest 14 tests (`ProxyWriteCommandTest`); mago format check clean; docs lint `domains/8_proxy/3_proxy-remove` 0 issues; evidence `.orbit/evidence/orphan-proxy-remove-contract-proof.md`
  - broader: passed - `composer quality-check` exit 0 at clean HEAD `db1a60cc73f9847ed96c8ae56c540536926b6303` (dirty=false); artifact `.orbit/quality-gates/quality-check-2026-08-02T205838Z-9c78c3e2945e.json`
  - runtime: passed - retained-incus venue contract proven by gateway DELETE API and CLI command tests for living-owner deny and orphan-owner force-remove; live topology mutation explicitly excluded by LAND instruction; evidence `.orbit/evidence/orphan-proxy-remove-contract-proof.md`
- Blast radius: complete - evidence=repository-wide search of `proxy.owned_route_denied`, `ProxyRouteIntent::remove`, proxy-remove docs, and doctor `proxy.owner_invalid` handoff; result=living owners stay denied, only FK-backed missing app/workspace owners gain force-remove, doctor restore still does not auto-delete invalid-owner rows
- Review: passed - human-judgment=not-required - independent review PASS on exact tip `db1a60cc73f9847ed96c8ae56c540536926b6303`; CHECKOUT_PROOF=feature worktree clean at that tip with Hermes archive `ded4d3296` on main; BLAST_RADIUS=complete; VERDICT=PASS
- Reviewed feature tip: db1a60cc73f9847ed96c8ae56c540536926b6303
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: db1a60cc73f9847ed96c8ae56c540536926b6303
- Accepted main tip: 9e4d7e8d6dd8b13a8912735590f740e11ab2f1ff

## Status

- State: land
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
