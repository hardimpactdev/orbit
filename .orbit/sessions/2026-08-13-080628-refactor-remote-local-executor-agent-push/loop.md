# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/77/scratchpad/node-agent-push-disp--428`
- Worktree: `/Users/nckrtl/orbit/.worktrees/refactor-remote-local-executor-agent-push`
- Branch: `refactor/remote-local-executor-agent-push`

## Goal

Make the Agent-push transport arm an explicit dependency of `RemoteLocalExecutor` without changing operation records, activity records, redaction, transport choice, stream failure handling, or frame order.

## Scope

- Owned: Agent-push selection, execution, streaming, and frame-to-result conversion currently inside `RemoteLocalExecutor`.
- Constraints: Preserve operation and activity ordering, exact error codes and messages, output frame order, duration mapping, and the executor's single redaction boundary.
- Out of scope: Gateway-local and forced-host execution, operation lifecycle, activity shape, redaction policy, probes, renderers, and human-only E2E commands.

## Proof

- Verification:
  - focused: passed - 62 tests, 430 assertions across the dispatcher, executor architecture, and executor behavior suites
  - broader: passed - full gateway Pest suite: 6,200 tests, 51,182 assertions, 4 skipped; repository-wide `composer quality-check` passed at candidate 881baf21335643ff19de8a433f691b6211d21054
  - runtime: passed - candidate=881baf21335643ff19de8a433f691b6211d21054; venue=retained-incus; environment=dev-fixture; target=agent-1; expected=real internal executor command uses Agent push and records only redacted token evidence; observed=operation succeeded on local lane with consumed token and agent_push dispatching/completed activities and zero unsafe token lines; result=passed; evidence=`.orbit/evidence/agent-push-retained-incus.json`
- Blast radius: complete - evidence=all 26 direct RemoteLocalExecutor construction sites and the generated transitional SSH inventory; result=every construction site supplies NodeAgentPushDispatcher and the only generated inventory change is the moved forced-host call line
- Review: passed - Claude Opus verified frame order, null-exit fallback, duration, stream envelope/error, transport choice, token sensitivity, DI wiring, and unchanged lifecycle/redaction; human-judgment=not-required
- Reviewed feature tip: 881baf21335643ff19de8a433f691b6211d21054
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 881baf21335643ff19de8a433f691b6211d21054
- Accepted main tip: 1b13f13d0ba4421aa90aaca73245e7fec6adda1f

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
