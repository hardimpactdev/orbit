# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: Solo project for this worktree
- Worktree: `/Users/nckrtl/orbit/.worktrees/fix-wg-handoff-peer-endpoints`
- Branch: `fix/wg-handoff-peer-endpoints`

## Goal

The retained Incus wg-easy handoff restores live WireGuard peer sessions so the operator can reach the gateway API without waiting for the client re-key timer.

## Scope

- Owned: `apps/e2e/app/E2E/Support/E2EWgEasySwarmHandoff.php`, its focused gateway tests, and retained Incus proof; primitive=wg-easy peer endpoint handoff; transitions=success:fresh Swarm peer handshakes|failure:standalone VPN restored|retry:idempotent handoff rerun|stop-restart:Swarm VPN replacement|stale:captured endpoints removed
- Constraints: Capture only public peer keys, observed endpoints, and allowed WireGuard addresses; keep the snapshot private and temporary; preserve the existing rollback path; use Beast only through `nckrtl@192.168.6.20`; never run `composer test:e2e*`.
- Out of scope: General production Swarm rollout changes, WireGuard key rotation, client-wide interface restarts, and unrelated topology cleanup.

## Proof

- Verification:
  - focused: passed - 8 wg-easy gateway tests (131 assertions), 13 Incus acquisition readiness tests (109 assertions), scoped Mago format/lint, PHP syntax, embedded Bash syntax, and `git diff --check`
  - broader: passed - `composer quality-check` passed for candidate `3ec4720f4f0086634a3fc55a5b26be4136f8a51c`; profile proof `.orbit/quality-gates/profiles/2026-08-13T03-18-37Z-3ec4720f4f00/gateway_pest.junit.xml`
  - runtime: passed - candidate=3ec4720f4f0086634a3fc55a5b26be4136f8a51c; venue=retained-incus; environment=dev-fixture; expected=operator reaches gateway without WireGuard re-key delay; observed=gateway-api.ready 1.005s plus node:list and two fresh peer handshakes; result=passed; evidence=`.orbit/evidence/wg-easy-swarm-handoff-runtime.json`; target=dev-32a664 operator_gateway
- Blast radius: complete - evidence=repository-wide scoped search for the handoff lifecycle, snapshot path, and caller order; result=one production lifecycle class, one caller, and the focused gateway/readiness tests cover the changed boundary
- Review: passed - Claude Opus general review; human-judgment=not-required; 0 blocking findings
- Reviewed feature tip: 3ec4720f4f0086634a3fc55a5b26be4136f8a51c
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 3ec4720f4f0086634a3fc55a5b26be4136f8a51c
- Accepted main tip: 68d51988dc551f70e5111cd684cc93987e6fb381

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`

Allowed states are `frame`, `build`, `prove`, `accept`, `accepted`, `land`, and
`blocked`. Review must be `passed`, `fix`, or `escalate`. Acceptance must be
`pending`, `accepted`, `not-applicable`, or `changes-requested`. A terminal
review PASS must record `human-judgment=required|not-required` and the exact
committed HEAD in `Reviewed feature tip`. Blast radius must be `not-required -
concrete reason` or `complete - evidence=repository-wide search, inventory, or
lintable check; result=summary` before acceptance; `gaps` returns to BUILD.
For stateful, lifecycle, or concrete UX work, optionally append one compact
clause on the existing Scope `Owned` row (do not add a permanent new row):
`primitive=exact requested primitive; transitions=success:terminal success|failure:terminal failure|retry:retry behavior|stop-restart:stop or restart|stale:stale-state or n/a`.
Omit the clause for ordinary/local changes. When `primitive=` or `transitions=`
is present, deterministic lint requires both fields, the five known transition
keys without duplicates or empty values, and rejects template placeholders; it
does not grade prose or decide whether the feature is stateful. Explicit `n/a`
values are fine when a transition does not apply. After FRAME, run
`bin/orbit-feature-acceptance route` for the read-only
diff-derived venue before expensive PROVE work. For non-`automated` venues,
`Verification.runtime: passed` must use one candidate-bound structured receipt
on that same single line. Required fields are candidate=, venue=, environment=,
expected=, observed=, result=passed, and evidence= as one exact inline-code path
under the worktree evidence or quality-gates trees. Use exactly one of target= or command=.
Live/production claims require exact environment=live; ordinary retained
topology may use environment=dev-fixture. Semicolons separate fields,
so values must not embed raw semicolon-delimited pseudo-fields. Known keys
only. Example evidence citation: write a real receipt and cite one exact regular
file below the worktree evidence tree (not a directory root). A failed,
excluded, still-required, or deferred final hop cannot be recorded as passed;
remain in PROVE, disarm any armed or recorded acceptance, and follow FIX ->
BUILD -> PROVE before ACCEPT. Keep a still-valid Review and Reviewed feature tip
on proof-only retries; a HEAD change still needs a refreshed review. Automated
venues keep `runtime: not applicable`. Proof files retained by the compact
archive must be cited as one exact inline-code path; prose, directories, padded
code spans, and partial paths are not proof citations.
