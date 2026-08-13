# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/2/scratchpad/orbit-stabilization--416`
- Worktree: `/Users/nckrtl/orbit/.worktrees/fix-operation-follow-liveness`
- Branch: `fix/operation-follow-liveness`

## Goal

Operation-backed CLI commands stop with the existing stream failure when journal replay makes no progress, instead of reconnecting forever.

## Scope

- Owned: `GatewayOperationFollower` empty replay budget, production configuration and binding, focused CLI tests, and the operation-stream documentation contract; primitive=durable operation follow; transitions=success:terminal complete|failure:stream failure after bounded empty replays|retry:new events reset the empty replay count|stop-restart:journal cursor resumes after reconnect|stale:duplicate event ids stay suppressed
- Constraints: Preserve the journal cursor, duplicate suppression, transient HTTP retries, terminal frames, environment override, and existing output and error contracts.
- Out of scope: Gateway runner watchdogs, a new heartbeat protocol, command-specific follower rewrites, and human-only E2E lanes.

## Proof

- Verification:
  - focused: passed - follower and command-stream integration, 40 tests and 169 assertions; scoped Mago format and lint passed; `composer docs-lint` passed
  - broader: passed - `composer quality-check` on f92967d1ca602e85887fb2e212abdb883fcb5ccb; 9,276 Pest tests passed across gateway, CLI, docs, core, and SDK, with Mago, Rector, Cargo, docs, generated catalog, and repository contracts passing
  - runtime: passed - candidate=f92967d1ca602e85887fb2e212abdb883fcb5ccb; venue=retained-incus; environment=dev-fixture; target=orbit-e2e-dev-b9f531-operator; expected=bounded empty journal replays return StreamClosedBeforeTerminal; observed=real gateway run with no events returned StreamClosedBeforeTerminal at the configured bound; result=passed; evidence=`.orbit/evidence/operation-follow-liveness-retained-incus.txt`
- Blast radius: complete - evidence=repository-wide search of `GatewayOperationFollower` and `StreamsGatewayProgress`; result=one shared follower covers nine trait consumers plus both `update:all` render paths, and no second empty-replay policy exists
- Review: passed - Claude Opus process 2382 found no actionable findings and verified the bound, reset rule, configuration floor, retained proof, and caller inventory; human-judgment=not-required
- Reviewed feature tip: f92967d1ca602e85887fb2e212abdb883fcb5ccb
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: f92967d1ca602e85887fb2e212abdb883fcb5ccb
- Accepted main tip: 2f9fcfaa70e606a9e3b378296bc2182158ed6f8a

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
