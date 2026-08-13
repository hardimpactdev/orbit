# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/2/scratchpad/orbit-stabilization--416`
- Worktree: `/Users/nckrtl/orbit/.worktrees/fix-node-remove-wireguard-peer`
- Branch: `fix/node-remove-wireguard-peer`

## Goal

Removing a non-gateway node removes its active gateway-managed WireGuard runtime peer before Orbit deletes the node registry state, and a teardown failure leaves the node retryable.

## Scope

- Owned: `node:remove` active runtime WireGuard peer teardown, retry-safe registry cleanup, self-removal eligibility, tests, and matching node-removal docs; primitive=node removal; transitions=success:runtime peer and node registry state are removed|failure:node registry state remains when runtime peer teardown fails|retry:repeated removal converges after an interrupted teardown|stop-restart:n/a|stale:an already-absent runtime peer is accepted and registry cleanup continues
- Constraints: Never remove a gateway node; refuse self-removal while the caller still has a managed WireGuard peer so the response remains reliable; never SSH into the target; use Beast host LAN SSH only at `nckrtl@192.168.6.20`; do not run human-only `composer test:e2e*`; preserve the existing CLI success response fields.
- Out of scope: Local client WireGuard cleanup, downstream app or runtime cleanup, gateway retirement, and unrelated Doctor family work.

## Proof

- Verification:
  - focused: passed - gateway node-removal and VPN installer suites: 45 tests, 317 assertions; CLI node write suite: 73 tests, 280 assertions; source-mounted gateway shim suite: 5 tests, 54 assertions; scoped Mago format/lint passed; `composer docs-lint` passed
  - broader: passed - `composer quality-check` passed on candidate `e78e88cb2dee0d22be3fae52f056c1e227cff7e4`; artifact=`.orbit/quality-gates/quality-check-2026-08-13T165655Z-e86df84f15c7.json`; `composer quality-gate:final-check` reported no warnings
  - runtime: passed - candidate=e78e88cb2dee0d22be3fae52f056c1e227cff7e4; venue=retained-incus; environment=dev-fixture; target=dev-564d3e/app-dev-1; expected=remove the durable and active WireGuard runtime peer before deleting retryable registry state; observed=public-key-matched durable and runtime peer counts changed from 1 to 0, total runtime peers changed from 5 to 4, node registry count changed from 1 to 0, and gateway API remained HTTP 200; result=passed; evidence=`.orbit/evidence/node-remove-wireguard-dev-564d3e.txt`
- Blast radius: complete - evidence=repository-wide search for `removePeer`, `deleteWgEasyPeerByPublicKey`, `removeRuntimePeer`, `NodeRemovalFailed`, `node.self_removal_requires_remote_caller`, and `node.wireguard_peer_removal_failed`; result=all implementation, error-registry, documentation, CLI generic-error, and test consumers are aligned with no unresolved surface
- Review: passed - Opus process 2388 found no correctness issues; human-judgment=not-required
- Reviewed feature tip: e78e88cb2dee0d22be3fae52f056c1e227cff7e4
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: e78e88cb2dee0d22be3fae52f056c1e227cff7e4
- Accepted main tip: 3f7528ca7fc0b3f48f22961b836fa112cf658f13

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
