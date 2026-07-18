# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-fix-apple-leaf-validity
- Branch: codex/fix-apple-leaf-validity

## Goal

Orbit issues every gateway-root-signed TLS leaf with a 397-day validity period, automatically replaces overlong leaves during convergence, and serves Apple-compatible certificates for app, workspace, proxy, gateway, tool, analytics, metrics, S3, and WebSocket routes.

## Scope

- Owned: gateway CA issuance and freshness policy, proxy/TLS product documentation, focused gateway coverage, retained runtime proof, and live fleet certificate reconvergence/proof.
- Constraints: preserve the 10-year root CA; keep signing authority gateway-only; retain per-route SAN and key behavior; never run `composer test:e2e*`; prove Apple trust evaluation for representative NMBP, Beast, and `analytics.orbit` leaves.
- Out of scope: public ACME/Cloudflare edge certificates, intermediate CAs, unrelated proxy/backend health, and mobile device management.

## Proof

- Verification:
  - focused: passed - 156 focused gateway tests, 707 assertions; final corrected ProxyRouteProbe 70 tests / 246 assertions and CA/fixer 47 tests / 382 assertions; gateway Mago format and lint; docs lint; PHP syntax; diff check
  - broader: passed - `ORBIT_QUALITY_CHECK_CPU_BUDGET=2 composer quality-check` and `composer quality-gate:final-check` at exact candidate 0fee2b0066c0732cb7ee8e93c24aecbb9bd578cb; all subgates exited 0; duration warnings classified as performance warning with no product failure
  - runtime: passed - retained topology dev-2b939e (`operator_gateway_app-dev`) detected an injected 3650-day `apple-proof.orbit` leaf as `proxy.tls_mismatch`, restored it to 397 days, returned clean on a second verify, and completed a root-trusted TLS 1.3 hostname-verified handshake; evidence `.orbit/evidence/apple-leaf-retained-proof.txt`
- Blast radius: complete - evidence=repository-wide `issueLeaf` consumer inventory plus every stored `ProxyRoute` traversing `ProxyRouteProbe::introspect()` and `diff()`; result=all five production leaf consumers use the central 397-day service, and the generic managed-route doctor path covers app/workspace/custom proxy/redirect plus NMBP, Beast, analytics, metrics, S3, gateway, tool, and WebSocket routes while excluding public ACME ingress
- Review: passed - no actionable findings; human-judgment=not-required
- Reviewed feature tip: 0fee2b0066c0732cb7ee8e93c24aecbb9bd578cb
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 0fee2b0066c0732cb7ee8e93c24aecbb9bd578cb
- Accepted main tip: b01b74a62c9c4227d3ed97827fe23f9063971c23

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
