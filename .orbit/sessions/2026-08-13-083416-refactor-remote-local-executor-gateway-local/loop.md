# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/78/scratchpad/gateway-local-execut--429`
- Worktree: `/Users/nckrtl/orbit/.worktrees/refactor-remote-local-executor-gateway-local`
- Branch: `refactor/remote-local-executor-gateway-local`

## Goal

Make gateway-local command execution an explicit dependency of `RemoteLocalExecutor` without changing operation records, activity records, token authorization, command construction, environment, output redaction, or forced-host behavior.

## Scope

- Owned: in-container gateway-local command construction, token authorization, trusted environment merge, and `RemoteOrbitGatewayExecutor` dispatch.
- Constraints: Preserve operation and activity ordering, command argv and options, token consumption, input/cwd/timeout/throw behavior, result mapping, and exact errors.
- Out of scope: Agent-push dispatch, forced-host SSH, operation token minting, operation/activity lifecycle, redaction policy, and broader executor redesign.

## Proof

- Verification:
  - focused: passed - 60 tests and 428 assertions across the gateway-local dispatcher, executor architecture, and executor behavior suites
  - broader: passed - full gateway Pest suite: 6,202 tests, 51,191 assertions, 4 skipped; repository-wide `composer quality-check` passed at candidate 0fbbb561ae1ef03f3d604ca525ef2f127d0fca2e
  - runtime: passed - candidate=0fbbb561ae1ef03f3d604ca525ef2f127d0fca2e; venue=retained-incus; environment=dev-fixture; target=gateway; expected=gateway-local internal executor verification succeeds with one-use token consumption and gateway_local activity records; observed=exit 0, operation lane local and status succeeded, token consumed, dispatching and completed activities recorded with token redacted; result=passed; evidence=`.orbit/evidence/gateway-local-retained-incus.json`
- Blast radius: complete - evidence=all 26 direct RemoteLocalExecutor construction sites, repository-wide references to the moved collaborators, and unchanged generated transitional SSH inventory; result=every construction site supplies GatewayLocalCommandDispatcher, both inline service-locator calls are removed, and the forced-host marker/call remain at lines 1009/1010
- Review: passed - Claude Opus independently reviewed the exact candidate and reported VERDICT PASS, BLAST_RADIUS complete, human-judgment=not-required
- Reviewed feature tip: 0fbbb561ae1ef03f3d604ca525ef2f127d0fca2e
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 0fbbb561ae1ef03f3d604ca525ef2f127d0fca2e
- Accepted main tip: 7bd91f75071ee89f200d301bce6dcd1bad4ddcd9

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
