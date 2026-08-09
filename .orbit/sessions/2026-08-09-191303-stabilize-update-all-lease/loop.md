# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/2/scratchpad/update-all-lease-liv--421` (ID 421)
- Worktree: `/Users/nckrtl/orbit/.worktrees/stabilize-update-all-lease`
- Branch: `stabilize-update-all-lease`

## Goal

Only one `update:all` run can enter preflight or mutation, and its fleet plus nested update leases remain heartbeat-valid until terminal cleanup.

## Scope

- Owned: `apps/gateway` update start, runner, lease, and heartbeat; `apps/cli` update conflict rendering; focused coverage; and `apps/docs/content/domains/11_operation/2_update-all/` contracts; primitive=fleet update lease lifecycle; transitions=success:terminal run releases every lease|failure:terminal failure releases owned leases|retry:expired reservation may be acquired by a later run|stop-restart:dead runner stops heartbeats and expiry permits recovery|stale:active lease blocks concurrent start until release or expiry
- Constraints: preserve response-before-launch, durable journal, immutable plan, atomic unique-index exclusion, typed conflicts, and fail-closed ownership checks; never run `composer test:e2e*`.
- Out of scope: operation stream schema, update pipeline behavior, lease table redesign, Doctor/provisioning refactors, release artifacts.

## Proof

- Verification:
  - focused: passed - 99 focused gateway tests with 714 assertions, plus 62 CLI update command and renderer tests with 309 assertions
  - broader: passed - gateway Pest passed 5,918 tests with 49,769 assertions; the corrected CLI quality lane passed 2,529 tests with 10,583 assertions; CLI Mago format/lint/analyze and Rector passed; docs lint passed with zero errors; a preceding PTY-wrapped shard attempt received SIGTERM before mixed_3 completed and was superseded by the complete non-PTY lane
  - runtime: passed - candidate=eba25a9c3809a993935dd03cf1e3af61d51ee927; venue=retained-incus; environment=dev-fixture; target=Incus topology dev-ec2895 operator/gateway/app-dev; expected=one heartbeated fleet owner renews its nested leases while concurrent human and JSON starts are rejected before a second runner and terminal cleanup permits retry; observed=candidate sidecar renewed fleet and nested expiries together, concurrent starts returned the typed conflict with one settled PTY frame and preserved JSON metadata, terminal cleanup left zero active leases, and a later start passed reservation; result=passed; evidence=`.orbit/evidence/update-all-lease-retained-incus-2026-08-09.txt`
- Blast radius: complete - evidence=repository-wide search for `fleet:update-all`, `orbit:update-runner`, `fleet-lease-id`, and `update_lease_conflict`, plus human, JSON, and PTY conflict proofs; result=runtime ownership remains confined to the gateway start/runner path and both CLI renderer paths preserve their documented contracts
- Review: passed - human-judgment=not-required; fresh Claude Opus process 2254 reviewed the complete diff, confirmed the prior start-time 409 gap and PTY final-frame defect are fixed, and found no blocking correctness, security, contract, or blast-radius issue
- Reviewed feature tip: eba25a9c3809a993935dd03cf1e3af61d51ee927
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: eba25a9c3809a993935dd03cf1e3af61d51ee927
- Accepted main tip: 4f659b57ae23ea34c85489f045fe3e4a5e10bb5a

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
