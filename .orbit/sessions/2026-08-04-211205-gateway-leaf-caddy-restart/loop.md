# Orbit Feature Loop

- Scratchpad: none
- Worktree: /Users/nckrtl/orbit/.worktrees/gateway-leaf-caddy-restart
- Branch: gateway-leaf-caddy-restart

## Goal

After fleet-update gateway leaf install, the served TLS cert on gateway.orbit must match host files: restart orbit-caddy because caddy reload keeps stale in-memory leaves when only cert bytes change.

## Scope

- Owned:
  - apps/gateway/app/Tools/CaddyTool.php
  - apps/gateway/app/Services/Gateway/GatewaySwarmInstaller.php
  - apps/gateway/tests/Feature/Services/Gateway/GatewaySwarmInstallerTest.php
  - apps/gateway/tests/Feature/Services/Operations/GatewayServiceUpdaterTest.php
  - apps/gateway/tests/Feature/Services/Operations/FleetUpdateVerifierTest.php
  - apps/docs/content/tech-stack.md
- Constraints: no VERSION bump, no GitHub stable release
- Out of scope: GatewayApiContainerInstaller/ProxyRouteFixer reload paths

## Proof

- Verification:
  - focused: passed - GatewaySwarmInstaller GatewayServiceUpdater FleetUpdateVerifier Pest 33 passed
  - broader: passed - composer quality-check exit 0 dirty=false tip e3d86dc3c6587448a4abdf23ed32a569355af439 evidence `.orbit/quality-gates/quality-check-2026-08-04T191117Z-46b0a4230d6a.json`
  - runtime: passed - live op edff96c0 left served cert stale after leaf+reload; docker restart restored serial CF69E5A0 with DNS:gateway.orbit; evidence `.orbit/release-evidence/2026-08-04-live-candidate-toolbar-leaf-converge/46-served-cert-stale-after-leaf-acceptance-fail.txt`
- Blast radius: complete - evidence=bounded search restartCommand/reloadCommand and serveGatewayLeafViaRouterCaddy callers; result=only Swarm leaf serve path restarts orbit-caddy after leaf install
- Review: passed - human-judgment=not-required
- Reviewed feature tip: e3d86dc3c6587448a4abdf23ed32a569355af439
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: e3d86dc3c6587448a4abdf23ed32a569355af439
- Accepted main tip: e8a7c668e3ef68c312402a721c4e3233423a6218

## Status

- State: land
- Blocker: none

## Feedback

- Events: .orbit/feedback.jsonl
