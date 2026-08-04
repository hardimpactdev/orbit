# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-gateway-browser-tls-san`
- Branch: `codex/gateway-browser-tls-san`

## Goal

Gateway installer/convergence issues the Orbit gateway leaf so its SANs cover
the configured browser Gateway hostname (default `gateway.orbit`) in addition
to the short host `gateway` and the WireGuard API IP, without weakening TLS
verification. Missing-browser-hostname leaves reissue; complete leaves stay
idempotent.

## Scope

- Owned: gateway leaf SAN identity/config, `GatewaySwarmInstaller`,
  `GatewayApiContainerInstaller` alignment, product docs for the gateway leaf
  SAN contract, focused Pest coverage under `apps/gateway`.
- Constraints: no live topology mutation of the production fleet; retained
  Incus disposable topology used only for acceptance proof; no
  deploy/`update:all`/release/E2E test suite; reuse `OrbitCaService` leaf
  coverage/regeneration; smallest correct change.
- Out of scope: live production reissue; DNS product redesign; weakening TLS
  verification; release manifests; Gateway candidate publish.

## Proof

- Verification:
  - focused: passed - `bin/orbit-gateway-pest` GatewaySwarmInstaller/OrbitCa/GatewayApi/GatewayLeafIdentity; gateway mago format; `composer docs-lint`
  - broader: passed - full `composer quality-check` exit 0 on clean HEAD `b7fd55c450a3a70631d0b79691595fa5bdbd6749` receipt `.orbit/quality-gates/quality-check-2026-08-04T150002Z-9d7e5818686d.json`
  - runtime: passed - retained Incus id=dev-33d24f kind=operator_gateway host=beast gateway=orbit-e2e-dev-33d24f-gateway checkout=/home/orbit/orbit-run Solo terminal 1364/project 55; GatewaySwarmInstaller install leaf SANs DNS:gateway,DNS:gateway.orbit,IP:10.6.0.2 serial 63CB654262B02A6E13D2674AFC7A95F3; TLS verify 0 without -k SNI gateway.orbit Orbit root CA; GET https://gateway.orbit/api/ca/root HTTP 200; second issueLeaf idempotent; evidence `.orbit/evidence/retained-incus-gateway-browser-tls-san-proof.txt`
- Quality-check receipt:
  - command: `composer quality-check`
  - bound commit: `b7fd55c450a3a70631d0b79691595fa5bdbd6749`
  - dirty: false
  - exit_code: 0
  - receipt: `.orbit/quality-gates/quality-check-2026-08-04T150002Z-9d7e5818686d.json`
  - evidence copy: `.orbit/evidence/quality-check-b7fd55c45-receipt.json`
- Blast radius: complete - evidence=rg over apps/packages for issueLeaf/GatewayLeafIdentity/ORBIT_GATEWAY_HOSTNAME/orbit.gateway.hostname/gateway.orbit; result=only Swarm+legacy gateway installers issue the gateway leaf with browser hostname; TS SDK uses https://gateway.orbit; other issueLeaf paths unrelated
- Review: passed - human-judgment=not-required
- Reviewed feature tip: b7fd55c450a3a70631d0b79691595fa5bdbd6749
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: b7fd55c450a3a70631d0b79691595fa5bdbd6749
- Accepted main tip: 72260bca3f93f6864ec043d7a2397257eb32c40f

## Status

- State: land
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
