# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: Solo project 73
- Worktree: `/Users/nckrtl/orbit/.worktrees/refactor-firewall-rule-script`
- Branch: `refactor/firewall-rule-script`

## Goal

Gateway ingress and VPN DNS render idempotent iptables rules through one bounded-lock contract, so lock waiting and check-before-mutate behavior cannot drift between them.

## Scope

- Owned: shared iptables rule script rendering, GatewayDirectFirewallInstaller adoption, VpnDnsSwarmStackRenderer adoption, and focused contract tests
- Constraints: preserve exact emitted rule order and behavior; keep the five-second xtables lock wait; preserve sudo and IPv4/IPv6 choices at each caller; no new runtime dependency
- Out of scope: nftables support, UFW behavior changes, firewall product-policy changes, topology lifecycle changes, and further Doctor refactoring

## Proof

- Verification:
  - focused: passed - 36 focused gateway/VPN/firewall tests, 274 assertions; scoped Mago and PHP syntax passed; all rendered scripts passed `bash -n`; `git diff --check` passed
  - broader: passed - `composer quality-check` passed for candidate `a82aa92451c3edb2b35ad20eaf6709acbed18bcb`; profile proof `.orbit/quality-gates/profiles/2026-08-13T03-37-56Z-a82aa92451c3/gateway_pest.log`
  - runtime: passed - candidate=a82aa92451c3edb2b35ad20eaf6709acbed18bcb; venue=retained-incus; environment=dev-fixture; expected=gateway ingress and VPN DNS rules converge twice without duplicates; observed=operator node list succeeded and every expected gateway and VPN DNS rule was present exactly once after two passes; result=passed; evidence=`.orbit/evidence/firewall-rule-convergence-runtime.json`; target=dev-1340bf operator_gateway
- Blast radius: complete - evidence=bounded repository-wide iptables inventory; result=the two gateway production owners use the shared contract, while the Incus harness has a distinct delete/reinsert lifecycle and remains out of scope
- Review: passed - Claude Opus general review; human-judgment=not-required; 0 actionable findings
- Reviewed feature tip: a82aa92451c3edb2b35ad20eaf6709acbed18bcb
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: a82aa92451c3edb2b35ad20eaf6709acbed18bcb
- Accepted main tip: 58643258db0d7946ce26cf70d188d5e74899c9e1

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
