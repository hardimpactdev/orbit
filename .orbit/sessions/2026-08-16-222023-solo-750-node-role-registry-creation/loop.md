# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/2/todo/p01-a-reuse-noderole--750`
- Worktree: `/Users/nckrtl/orbit/.worktrees/solo-750-node-role-registry-creation`
- Branch: `solo-750-node-role-registry-creation`

## Goal

Node creation and bootstrap prevalidation use `NodeRoleRegistry` as the single compatibility authority, reject every conflicting role pair before side effects, and preserve template-only concepts outside the role registry.

## Scope

- Owned: `NodeRoleRegistry`, `NodeCreationRoleResolver`, `NodeBootstrapCompletion` prevalidation, role compatibility product contracts, and focused registry/resolver/bootstrap tests; primitive=registry-owned node-role compatibility prevalidation; transitions=success:compatible roles proceed to bootstrap|failure:conflicting roles reject before effects|retry:same validated role set can retry|stop-restart:n/a|stale:registry changes apply on the next prevalidation
- Constraints: Remove resolver-local conflict authority; exercise every registry conflict pair; prove no bootstrap side effect occurs before rejection; preserve template-only concepts outside the registry; preserve parent `.codex/config.toml`; use local Solo project 2 only.
- Out of scope: Role driver behavior, role convergence implementations, unrelated bootstrap sequencing, template taxonomy migration, and manual E2E lanes.

## Proof

- Verification:
  - focused: passed - literal RED observed 18 `node_bootstraps.last_error` updates through the HTTP completion endpoint before the fix; `bin/orbit-gateway-pest --compact tests/Unit/Services/Nodes/NodeRoleRegistryTest.php tests/Unit/Services/Nodes/NodeCreationRoleResolverTest.php tests/Unit/Services/Nodes/GatewayNodeCreatorArchitectureTest.php tests/Feature/Http/Api/NodeBootstrapControllerTest.php` (108 tests, 637 assertions); `composer docs-lint`; `bin/orbit-gateway-vendor-bin mago format --check`; `bin/orbit-gateway-vendor-bin rector process --dry-run`
  - broader: passed - `bin/orbit-gateway-pest --compact` (6,405 tests, 52,524 assertions, 6 skipped); `composer quality-check` against `0cbef186db995ba4ac11ecbe73ff98c94e6befaf`, receipt `.orbit/quality-gates/quality-check-2026-08-16T200636Z-9d38847f056c.json`
  - runtime: passed - candidate=0cbef186db995ba4ac11ecbe73ff98c94e6befaf; venue=retained-incus; environment=dev-fixture; target=dev-7bf9b7; expected=all 18 registry conflict pairs and locked HTTP bootstrap completion reject without persistence; observed=all 18 rejected and bootstrap sentinel status timestamp roles and peers remained unchanged; result=passed; evidence=`.orbit/evidence/todo-750-retained-incus.json`
- Blast radius: complete - evidence=repository-wide scoped search across gateway production code tests packages and product docs; result=registry owns compatibility data and creation queries while the remaining conflictsWith consumers enforce assignment and probe behavior
- Review: passed - human-judgment=not-required; independent local Solo review found no actionable issues and confirmed prevalidation precedes bookkeeping while genuine convergence failures still persist errors
- Reviewed feature tip: 0cbef186db995ba4ac11ecbe73ff98c94e6befaf
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 0cbef186db995ba4ac11ecbe73ff98c94e6befaf
- Accepted main tip: 7c851b85dd69f7fa5372f3b4f34ee1ae9e93114e

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
