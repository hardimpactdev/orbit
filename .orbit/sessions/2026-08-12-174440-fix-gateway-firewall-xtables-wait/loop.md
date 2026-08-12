# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none - narrow runtime bug fix
- Worktree: `/Users/nckrtl/orbit/.worktrees/fix-gateway-firewall-xtables-wait`
- Branch: `fix/gateway-firewall-xtables-wait`

## Goal

Make gateway-direct firewall convergence wait for temporary xtables lock contention instead of failing gateway setup immediately.

## Scope

- Owned: GatewayDirectFirewallInstaller iptables and ip6tables command rendering, plus focused regression coverage.
- Constraints: Preserve the exact DOCKER-USER and UFW policy. Use the same bounded five-second wait as VPN/DNS convergence. Verify on retained Incus through Beast LAN `192.168.6.20`.
- Out of scope: nftables implementation, UFW behavior, E2E harness firewall setup, and human-only E2E lanes.

## Proof

- Verification:
  - focused: passed - GatewaySwarmInstaller 14 tests/140 assertions; scoped Mago format and lint passed with one pre-existing help message; diff check clean
  - broader: passed - composer quality-check profile 2026-08-12T15-38-54Z-0086f8b7ea15
  - runtime: passed - candidate=0086f8b7ea15d7ce84498a2c8e0a5f4d6f38cad8; venue=retained-incus; environment=dev-fixture; target=orbit-e2e-dev-4bfb9c-gateway; expected=all gateway-direct firewall calls have a bounded wait and the exact policy still applies; observed=14 IPv4 and 5 IPv6 wait calls rendered, installer succeeded, and DOCKER-USER rules remained exact; result=passed; evidence=`.orbit/evidence/gateway-firewall-xtables-wait.txt`
- Blast radius: not-required - this changes only a bounded wait flag in one emitted firewall script and does not change rule policy, API contracts, schemas, or vocabulary
- Review: passed - Claude Opus Solo project 48 process 2310; no findings; human-judgment=not-required
- Reviewed feature tip: 0086f8b7ea15d7ce84498a2c8e0a5f4d6f38cad8
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 0086f8b7ea15d7ce84498a2c8e0a5f4d6f38cad8
- Accepted main tip: 7560768645295cbb265f8ff7ac1517cef4806d90

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
