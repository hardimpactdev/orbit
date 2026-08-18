# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad:
- Worktree: /Users/nckrtl/orbit/.worktrees/solo-hardening-owner-context-node
- Branch: solo-hardening-owner-context-node

## Goal

ProcessOwnerContext::forInstance and forWorkspace reject a node that is not the instance's placement node, using the cheapest in-memory driver_config comparison (no WorkspacePlacement query).

## Scope

- Owned: apps/gateway ProcessOwnerContext factories and ProcessOwnerContextFactoriesTest
- Constraints: instance-authoritative placement only (driver_config node_id/node, never App fields); no N+1 or WorkspacePlacement queries at factory sites; InvalidArgumentException as established by 756; existing factory tests stay green
- Out of scope: forNode, call-site refactors, live nodes, merge/push, E2E

## Proof

- Verification:
  - focused: passed - ProcessOwnerContextFactoriesTest 9 passed 39 assertions including both new mismatch cases; context suites 8 passed on the pre-merge tip
  - broader: passed - `composer quality-check` on clean merged commit a5e3ed7ad6bf12f82029e30d2d3f62cbc1f614e8 exit 0, 45/45 subgates (`.orbit/quality-gates/quality-check-2026-08-18T185554Z-e2e2b3f053ed.json`); pre-merge full gateway suite 7072 passed 2 skipped
  - runtime: passed - candidate=a5e3ed7ad6bf12f82029e30d2d3f62cbc1f614e8; venue=retained-incus; environment=dev-fixture; target=orbit-e2e-dev-40cae5-gateway; expected=exact candidate rejects cross-node process owner contexts against instance placement while all coherent factory paths stay constructible in the routed retained gateway environment; observed=matching ProcessOwnerContext sha256 423982cff060 and 17 tests passed 58 assertions in the retained gateway instance; result=passed; evidence=`.orbit/evidence/solo-hardening-owner-context-node-retained-incus-runtime.txt`
- Blast radius: complete - evidence=full factory call-site audit recorded in the handoff plus full gateway Pest suite; result=all eleven production call sites obtain the node from instance-authoritative placement so none legitimately construct cross-node contexts, guard is in-memory with WorkspacePlacement precedence and no added queries, DoctorProcessRestorer stale denormalized node path now fails closed as intended, full suite 7072 passed
- Review: passed - orchestrator Claude reviewer VERDICT PASS: guard scoped to OrbitInstanceDriverConfigData with node_id precedence then name and skip on absent placement, no N+1 queries added at hot call sites, no silent loosening or named bypass, thrown type matches the 756 factory contract; human-judgment=not-required
- Reviewed feature tip: a5e3ed7ad6bf12f82029e30d2d3f62cbc1f614e8
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: a5e3ed7ad6bf12f82029e30d2d3f62cbc1f614e8
- Accepted main tip: 23651eb35366a46438d8b21eeb558c57a8951cc8

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
