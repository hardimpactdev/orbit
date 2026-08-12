# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none - narrow runtime bug fix
- Worktree: `/Users/nckrtl/orbit/.worktrees/fix-vpn-dns-xtables-wait`
- Branch: `fix/vpn-dns-xtables-wait`

## Goal

Make VPN/DNS firewall convergence wait for temporary xtables lock contention instead of failing gateway setup immediately.

## Scope

- Owned: VPN/DNS Swarm iptables command rendering, its health-check timeout, and focused regression coverage.
- Constraints: Preserve the exact four DNS forwarding rules and fail after a bounded wait. Verify on retained Incus through Beast LAN `192.168.6.20`.
- Out of scope: Other gateway firewall installers, nftables support, firewall policy changes, and human-only E2E lanes.

## Proof

- Verification:
  - focused: passed - renderer 6 tests/79 assertions; manager 9 tests/19 assertions; scoped Mago format and lint passed; diff check clean
  - broader: passed - composer quality-check profile 2026-08-12T15-23-57Z-e997c13031d9
  - runtime: passed - candidate=e997c13031d9e7fd0fc657188ad50676501ed38a; venue=retained-incus; environment=dev-fixture; target=orbit-e2e-dev-9feacb-gateway/orbit_orbit-vpn; expected=Orbit waits through a five-second xtables lock and keeps DNS forwarding healthy; observed=convergence exited zero after 5.02 seconds and the deployed health check has eight wait commands with a ten-second timeout; result=passed; evidence=`.orbit/evidence/vpn-dns-xtables-wait.txt`
- Blast radius: not-required - the candidate changes only VPN/DNS Swarm script rendering and its focused contract; other firewall installers remain separate paths
- Review: passed - Claude Opus Solo project 46 process 2309; no blocking findings; human-judgment=not-required
- Reviewed feature tip: e997c13031d9e7fd0fc657188ad50676501ed38a
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: e997c13031d9e7fd0fc657188ad50676501ed38a
- Accepted main tip: bf337f15a09952ef5bb05fae2ddb75312c483805

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
