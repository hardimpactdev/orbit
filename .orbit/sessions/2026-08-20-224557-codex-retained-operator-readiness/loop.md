# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/117/scratchpad/retained-operator-re--512`
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-retained-operator-readiness`
- Branch: `codex/retained-operator-readiness`

## Goal

Make the documented one-node retained `operator` topology complete without a
gateway API. Preserve gateway readiness for every retained topology that has a
gateway role.

## Scope

- Owned: `apps/e2e/app/Console/Commands/E2EDevTopologyCommand.php`, focused tests in `apps/e2e/tests/Feature/E2ESupport/Commands/E2EDevTopologyCommandTest.php`, and loop/evidence files.
- Constraints: keep the `operator` topology one-node; keep gateway readiness mandatory when a gateway exists; use a failing Pest regression before implementation; never run or delegate `composer test:e2e*`; preserve unrelated primary-checkout state.
- Out of scope: topology role definitions, gateway boot behavior, public CLI commands, other providers, and product-doc wording that already describes the intended one-node topology.

## Proof

- Verification:
  - focused: passed - `apps/e2e/vendor/bin/pest --compact tests/Feature/E2ESupport/Commands/E2EDevTopologyCommandTest.php` (15 tests, 102 assertions); TDD red and green recorded in `.orbit/evidence/retained-operator-readiness-tdd.md`
  - broader: passed - `composer quality-check`; evidence `.orbit/quality-gates/profiles/2026-08-20T20-38-51Z-a6dd97cebcb8/gateway_pest.junit.xml`
  - runtime: passed - candidate=a6dd97cebcb83fb9998ae3d18b945a27dfc15574; venue=retained-incus; environment=dev-fixture; command=composer e2e:incus -- --start --topology=operator --checkout-roles=operator --json; expected=operator-only acquisition completes without a gateway API and yields a usable mounted checkout; observed=topology dev-4bec46 contained only the operator instance and the mounted Orbit CLI executed successfully; result=passed; evidence=`.orbit/evidence/retained-operator-readiness-runtime.md`
- Blast radius: complete - evidence=repository-wide search for retained acquisition, `startGatewayApi`, `waitForGatewayApi`, topology roles, and operator-topology documentation; result=only the retained dev-topology command unconditionally waited after overlay, while all gateway-bearing builder and test paths retain their readiness checks
- Review: passed - independent Solo reviewer found no issues after bounded control-flow, topology-role, documentation, test, and evidence review; human-judgment=not-required
- Reviewed feature tip: a6dd97cebcb83fb9998ae3d18b945a27dfc15574
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: a6dd97cebcb83fb9998ae3d18b945a27dfc15574
- Accepted main tip: 29177ab6ba594f1417449e159a2a52bf9c7e9fc3

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
