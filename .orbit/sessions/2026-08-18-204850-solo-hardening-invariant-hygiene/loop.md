# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: /Users/nckrtl/orbit/.worktrees/solo-hardening-invariant-hygiene
- Branch: solo-hardening-invariant-hygiene

## Goal

Close three gateway invariant leaks: workspace-step inserts no longer inspect or write dropped `app_id`; `LocalGatewaySettings` cannot mint a second singleton via create/fill/save; assigning `FirewallRule.protected` throws because it is derived from owner.

## Scope

- Owned: `apps/gateway` AddWorkspaceStep, WorkspaceStepFactory, LocalGatewaySettings, FirewallRule, and focused Pest coverage plus leftover factory `protected` assignments
- Constraints: no compat branches, no live nodes, no e2e, no merge/push
- Out of scope: schema migrations already landed by 757/758/763; LAND/merge

## Proof

- Verification:
  - focused: passed - red-first invariant cases then 26 passed across workspace-step, singleton, and firewall suites; touched-suite bundle 201 passed 1219 assertions on the pre-merge tip
  - broader: passed - `composer quality-check` on clean merged commit fa57826f5f2f98256a55850ca109b9bbe093c90c exit 0, 45/45 subgates (`.orbit/quality-gates/quality-check-2026-08-18T184716Z-b23db73ca9b4.json`); pre-merge full gateway suite 6968 passed 2 skipped
  - runtime: passed - candidate=fa57826f5f2f98256a55850ca109b9bbe093c90c; venue=retained-incus; environment=dev-fixture; target=orbit-e2e-dev-648a44-gateway; expected=exact candidate inserts workspace steps without the compat branch, keeps the settings singleton model-closed, and throws on derived firewall protected assignment in the routed retained gateway environment; observed=matching LocalGatewaySettings sha256 993e96a88f23 and 21 tests passed 86 assertions in the retained gateway instance; result=passed; evidence=`.orbit/evidence/solo-hardening-invariant-hygiene-retained-incus-runtime.txt`
- Blast radius: complete - evidence=repository-wide rg for remaining protected writers and singleton_key fill sites plus full gateway Pest suite; result=no production path assigns protected or a non-default singleton_key, remaining protected literals are raw DB helpers that unset the dropped column and serialized payload expectations, workspace-step insert path free of schema introspection, full suite 6968 passed
- Review: passed - orchestrator Claude reviewer VERDICT PASS: compat branch and factory dual-path removed cleanly, saving guard forcing SINGLETON_KEY keeps firstOrCreate working while overwriting deviant keys, firewall protected assignment now throws LogicException with all former writers stripped, post-merge quality failure was a namespace-less use-statement warning escalated by the quality lane and fixed by referencing the global class; human-judgment=not-required
- Reviewed feature tip: fa57826f5f2f98256a55850ca109b9bbe093c90c
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: fa57826f5f2f98256a55850ca109b9bbe093c90c
- Accepted main tip: 0824bce48fdb13bcbc662b900e0f90d37c6f4f85

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
