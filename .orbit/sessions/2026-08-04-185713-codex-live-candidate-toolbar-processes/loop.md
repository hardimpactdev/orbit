# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: no-GitHub live candidate for Laravel Toolbar process control +
  permanent update-path gateway leaf SAN convergence
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-live-candidate-toolbar-processes
- Branch: codex/live-candidate-toolbar-processes

## Goal

Make fleet `update:all` permanently reissue an incomplete Orbit gateway API
leaf so SANs always cover short host `gateway`, browser hostname
`gateway.orbit` (config `orbit.gateway.hostname`), and the gateway WireGuard
API IP, and refresh router-owned Caddy site material, so Toolbar/SDK/EventSource
strict TLS works without `-k`. Keep VERSION `0.1.190`, no GitHub stable
release, no E2E. After LAND, rebuild no-GitHub candidate from pushed main and
re-prove via the durable updater path.

## Scope

- Owned:
  - `apps/gateway/app/Services/Gateway/GatewaySwarmInstaller.php`
    (`convergeGatewayLeafServing`, shared issue/stage/serve helpers)
  - `apps/gateway/app/Services/Operations/GatewayServiceUpdater.php`
    (call leaf converge during stack convergence)
  - `apps/gateway/tests/Feature/Services/Operations/GatewayServiceUpdaterTest.php`
  - `apps/gateway/tests/Feature/Services/Operations/FleetUpdateVerifierTest.php`
  - live-test candidate + `update:all` + strict TLS/CORS/SSE proofs after LAND
- Constraints: no VERSION bump; no GitHub release/tag/final GHCR gateway tag;
  no npm publish; never `composer test:e2e*`; no bulk Doctor restore; do not
  mutate application process lifecycle (start/stop)
- Out of scope: Toolbar browser lifecycle clicks (Codex); SDK work already on
  main (`65396c9f`)

## Bug

`b7fd55c45` taught installers to issue browser-hostname SANs, but
`GatewayServiceUpdater::convergeGatewayStack` only ran
`bootstrapRuntimeConfig` + stack deploy. Live leaf stayed Jun-14
`DNS:gateway` + `IP:10.6.0.2` with Caddy site `10.6.0.2 :443` only; strict
`https://gateway.orbit` failed curl 60. Product docs require installer **and
convergence** reissue when SANs are incomplete.

## Source fix

1. `GatewaySwarmInstaller::convergeGatewayLeafServing()` issues/stages the
   full SAN leaf; on router-colocated exposure installs to `/etc/orbit/certs`,
   writes `orbit-gateway.caddy` with browser hostname, reloads Caddy without
   recreating the public container (ports already owned).
2. `GatewayServiceUpdater::convergeGatewayStack` calls that helper before
   stack deploy using the gateway node WireGuard address/CIDR.
3. Tests cover updater leaf SAN + Caddy route commands and fleet
   UpdateRunner terminal success/failure with fake leaf issue.

## Proof

- Verification:
  - focused: passed - post-merge leaf suite 59; GatewayServiceUpdater|GatewaySwarmInstaller|FleetUpdateVerifier 32 passed
  - broader: passed - composer quality-check exit 0 on tip 9eee39feab84c7ac40147e6b98c58a33b775f5fc
  - runtime: passed - operational leaf reissue + strict TLS/CORS/SSE/list on live gateway.orbit; durable updater-path re-proof after LAND
- Blast radius: not-required - local gateway update converge + installer leaf serve helpers and their tests only; no shared schema/vocabulary change
- Review: passed - human-judgment=not-required - independent general review at 9eee39feab84c7ac40147e6b98c58a33b775f5fc; no actionable source findings; update:all stack converge reuses installer leaf issue/stage/serve with correct WG address/CIDR/exposure; SAN set includes gateway + gateway.orbit + IP; router-colocated reloads without recreating public Caddy; tests cover updater path; delta scoped to 4 files
- Reviewed feature tip: 9eee39feab84c7ac40147e6b98c58a33b775f5fc
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 9eee39feab84c7ac40147e6b98c58a33b775f5fc
- Accepted main tip: 65396c9f3a9b6f58638eb710d2ec22c944890ae8

## Status

- State: land
- Feature tip (clean): `9eee39feab84c7ac40147e6b98c58a33b775f5fc`
- Merged main: `65396c9f3a9b6f58638eb710d2ec22c944890ae8`
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`

## Evidence

- `.orbit/release-evidence/2026-08-04-live-candidate-toolbar-processes/15-live-leaf-reissue.txt`
- `.orbit/release-evidence/2026-08-04-live-candidate-toolbar-processes/16-post-reissue-toolbar-proofs.txt`
- `.orbit/release-evidence/2026-08-04-live-candidate-toolbar-processes/19-final-report.json`
- `.orbit/release-evidence/2026-08-04-live-candidate-toolbar-processes/20-post-merge-identity.txt`
- `.orbit/release-evidence/2026-08-04-live-candidate-toolbar-processes/21-focused-tests.txt`
- `.orbit/release-evidence/2026-08-04-live-candidate-toolbar-processes/23-quality-check.log`
- `.orbit/quality-gates/quality-check-2026-08-04T164855Z-40a87a583def.json`
