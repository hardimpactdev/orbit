# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: user request in the current Codex task
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-gateway-operation-token-proxy-convergence`
- Branch: `codex/gateway-operation-token-proxy-convergence`

## Goal

Gateway-local internal commands complete the signed operation-token round trip
inside the real gateway container, and Hauzer proxy restoration converges in
backend -> router -> ingress order without masking partial or failed enactment.

## Scope

- Owned:
  - gateway-local operation-token authorization and trusted execution context
  - CLI trusted execution verification
  - app proxy enactment state, ordering, failure attribution, and proxy:list status
  - focused docs and regression coverage
  - retained-topology Hauzer proxy restoration proof
- Constraints:
  - use the prepared Orbit worktree and repository-native verification lanes
  - do not run manual-only `composer test:e2e*` commands
  - preserve the intended ingress -> gateway/router -> main1 topology
  - remove obsolete BEAST deployment guidance if it exists in the owned docs
- Out of scope:
  - Cloudflare edge-certificate or API-token remediation
  - unrelated baseline test failures

## Proof

- Verification:
  - focused: passed - full owned gateway slice 112 tests/661 assertions; full owned CLI slice 129/526; core 24/38; retained-topology support 161/1048; gateway host-path follow-up 21/213; CLI host-path follow-up 28/134; ingress certificate role regression 139/952
  - broader: passed - `composer quality-check` passed 43/43 subgates at exact feature tip; proof `.orbit/quality-gates/quality-check-2026-07-16T143956Z-7dbf6ae64e2f.json`
  - runtime: passed - retained Incus topology `dev-48819c` completed the gateway-container token lane and proved gateway-host writes are visible in the real `orbit-caddy` container but absent from the gateway execution container; its intentionally missing certificate caused an explicit reload failure rather than false convergence. Retained Docker topology `dev-07289e` completed the 7-operation backend -> router -> ingress flow. Live Hauzer re-registration then recorded all seven operations as completed and `proxy:list` reported `converged`; direct ingress HTTPS showed two Caddy hops. The downstream main1 app backend still returns 502; proof `.orbit/evidence/gateway-operation-token-proxy-incus.txt`; proof `.orbit/evidence/gateway-operation-token-proxy-runtime.txt`
- Blast radius: complete - evidence=focused gateway, CLI, core, docs, and source-reference checks across the operation-token and proxy surfaces; result=no unaddressed call sites or generated-doc drift
- Review: passed - independent exact-tip review found no findings - human-judgment=not-required
- Reviewed feature tip: 88a91bca322805e25e308398b1ca13799d3f174e
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 88a91bca322805e25e308398b1ca13799d3f174e
- Accepted main tip: 54a5df0bb210129a37180dd20a40e7a20f135315

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`

Allowed states are `frame`, `build`, `prove`, `accept`, `accepted`, `land`, and
`blocked`. Review must be `passed`, `fix`, or `escalate`. Acceptance must be
`pending`, `accepted`, `not-applicable`, or `changes-requested`. A terminal
review PASS must record `human-judgment=required|not-required` and the exact
committed HEAD in `Reviewed feature tip`. Blast radius must state either a
reason it is not required or the completed evidence and result before
acceptance; `gaps` returns to BUILD.
Proof files retained by the compact archive must be cited as one exact
inline-code path; prose, directories, padded code spans, and partial paths are
not proof citations.
