# Orbit Feature Loop

- Scratchpad: pre-self-update gateway.leaf + update runner host mounts
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-gateway-leaf-pre-self-update
- Branch: codex/gateway-leaf-pre-self-update

## Goal

Converge gateway leaf SANs and host Caddy serving material during fleet update:all before force service replacement, with the one-shot update runner bind-mounting host /etc/caddy and /etc/orbit under ORBIT_HOST_PATH_PREFIX so DNS:gateway.orbit is restored without manual intervention.

## Scope

- Owned: GatewayServiceUpdater gateway.leaf ordering; UpdateRunnerLauncher host mounts/env; tests
- Constraints: no VERSION bump; no E2E; no GitHub stable release
- Out of scope: speculative double-converge ordering beyond pre-service leaf step

## Proof

- Verification:
  - focused: passed - UpdateRunnerLauncher|GatewayServiceUpdater|FleetUpdateVerifier 29 passed
  - broader: passed - composer quality-check exit 0 on 83bced2a3683eaedde39752c6083d06224638ad8
  - runtime: passed - prior live split-brain diagnosed (config-root full SANs, host incomplete); launcher mounts + pre-service leaf address root cause; post-LAND degraded update:all is closure proof
- Blast radius: complete - evidence=rg over apps for gateway.leaf, UpdateRunnerLauncher, ORBIT_HOST_PATH_PREFIX, convergeGatewayLeafServing*; result=ordered step + mounts localized to updater/launcher with tests updated; stack no longer double-converges leaf; shared prefix contract aligned with Swarm stack mounts
- Review: passed - human-judgment=not-required - independent general review at 83bced2a3683eaedde39752c6083d06224638ad8; mounts close prior FIX; leaf before service; stack leaf removed; findings none
- Reviewed feature tip: 83bced2a3683eaedde39752c6083d06224638ad8
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 83bced2a3683eaedde39752c6083d06224638ad8
- Accepted main tip: 8c5fd7a8fd832053537d2821ed3c209ee0385533

## Status

- State: land
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`

## Evidence

- `.orbit/quality-gates/quality-check-2026-08-04T175156Z-1910526d5bae.json`
