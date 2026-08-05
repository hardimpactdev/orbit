# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-fix-macos-caddy-ca-recovery`
- Branch: `codex/fix-macos-caddy-ca-recovery`

## Goal

Doctor restore recovers Mini-style managed `orbit-caddy` restart loops caused by
host Caddyfile `pki { ca local { intermediate_lifetime 3599d } }` (Caddy 2.11.4
rejects 3599d against ~85901h remaining root life) by portably probing the host
Caddyfile on macOS and stripping only that obsolete `ca local` directive while
preserving root/intermediate PEMs and unrelated custom options.

## Scope

- Owned:
  - portable host-mounted global Caddyfile base64 probe (GNU + BSD/macOS)
  - brace-aware `ca local` strip of exact `intermediate_lifetime 3599d` in
    `Orbit\Core\Caddy\CaddyfileLocalCaIntermediateLifetime`
  - `CaddyGlobalConfig::ensure` and CLI apply-container merge both apply the strip
  - proxy doctor docs + Pest coverage + synthetic Caddy runtime proof
- Constraints:
  - this prepared worktree only
  - do not mutate live Mini
  - do not run `composer test:e2e*`
  - never delete root/intermediate PEMs or all Caddy data
- Out of scope:
  - merge/push/deploy/release
  - live Mini mutation

## Proof

- Verification:
  - focused: passed - core migrator 5 tests; gateway CaddyGlobalConfig 7 tests;
    ProxyRouteProbe/Fixer + CLI apply-container strip tests; portable base64 probe
  - broader: passed - `composer quality-check` exit 0; receipt
    `.orbit/quality-gates/quality-check-2026-08-02T132737Z-c9336184bc4d.json`
    (tip deeb6cc8e19f5d4dd061684a8e146fd294ccca7a) with gateway Pest 5340
    passed (31491 assertions); core mago analyze clean
  - runtime: passed - synthetic docker `caddy:2-alpine` v2.11.4 with exact
    Mini-style 3599d Caddyfile + aged root (~85896h remaining): validate failed
    with IntermediateLifetime error before migration; after ensure/strip validate
    passed, container stable running (2 samples), HTTP 200 `ok`, root.crt/key and
    intermediate.crt fingerprints preserved; evidence
    `.orbit/evidence/runtime-proof-caddy-3599d.txt`
- Blast radius: complete - evidence=repository-wide search for intermediate_lifetime, CaddyGlobalConfig, and global Caddyfile probe ownership; result=migration scoped to ca local 3599d only via shared core helper used by gateway ensure and CLI apply-container; custom CAs and non-3599d lifetimes preserved
- Review: passed - human-judgment=not-required; independent review PASS, no findings
- Reviewed feature tip: deeb6cc8e19f5d4dd061684a8e146fd294ccca7a
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
  synthetic Caddy runtime proof at `.orbit/evidence/runtime-proof-caddy-3599d.txt`
- Accepted feature tip: deeb6cc8e19f5d4dd061684a8e146fd294ccca7a
- Accepted main tip: c34d556f639cb588900f2a524169edcfd87aa4cb
- Feature tip: deeb6cc8e19f5d4dd061684a8e146fd294ccca7a

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
