# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/2/todo/i15-b-use-a-minimal-con--773`
- Worktree: `/Users/nckrtl/orbit/.worktrees/solo-773-minimal-topology-context`
- Branch: `solo-773-minimal-topology-context`

## Goal

The source-less E2E topology Docker image builds from a minimal context that contains no unrelated repository source while preserving local and remote image identity.

## Scope

- Owned: E2E Docker topology-image context selection, obsolete root-context ignore policy for that image, and focused context-contract coverage.
- Constraints: Keep the topology Dockerfile source-less; preserve local and remote build parity; prove fresh builds and declared inputs; do not run human-only E2E test lanes.
- Out of scope: The gateway image-input manifest tracked by Solo todo #752, other Docker image contexts, and product command behavior.

## Proof

- Verification:
  - focused: passed - RED: `composer --no-interaction --working-dir=apps/e2e exec -- pest --compact tests/Feature/E2ESupport/Commands/E2EPrepareDockerRuntimeCommandTest.php` exited 1 with 2 expected failures: topology build context was the repository root and `Dockerfile.dockerignore` remained a declared input. GREEN: the same command passed 9 tests and 91 assertions; `bin/orbit-gateway-pest --compact tests/Feature/E2ESupport/VerificationScriptsTest.php` passed 42 tests and 494 assertions; fresh local and Beast builds plus per-architecture digest comparisons passed with inputs recorded in `.orbit/evidence/solo-773-topology-build.txt`.
  - broader: passed - configured E2E and gateway Mago format, lint, and analysis checks passed; configured E2E and gateway Rector dry runs passed; `composer docs-lint` passed with zero errors; `bin/orbit-secret-scan` passed; `composer quality-check` passed all 10 units with exact clean candidate receipt `.orbit/quality-gates/quality-check-2026-08-16T212109Z-7f4c634ffe6f.json`.
  - runtime: passed - candidate=0426ce02250c3b76bb973bfc05c553000a611a37; venue=retained-incus; environment=dev-fixture; target=dev-f72dac/operator; expected=the source-mounted candidate selects the minimal source-less topology image context and all focused preparation contracts pass; observed=VM launcher and source hashes matched the candidate and the focused suite passed 9 tests with 91 assertions; result=passed; evidence=`.orbit/evidence/solo-773-retained-incus.json`
- Blast radius: complete - evidence=repository-wide `rg` inventory of active topology Dockerfile, context, ignore-policy, and candidate-path references; result=only the topology runtime context changed, the obsolete policy remains only as an absence assertion, gateway and Reverb root contexts stay unchanged, todo #752 gateway manifest inputs stay out of scope, and plan references are historical artifacts.
- Review: passed - human-judgment=not-required; independent re-review found no actionable findings after confirming the exact candidate-bound retained-incus receipt and released topology.
- Reviewed feature tip: 0426ce02250c3b76bb973bfc05c553000a611a37
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 0426ce02250c3b76bb973bfc05c553000a611a37
- Accepted main tip: 10bfd6839a9867bd393daf344a44daa13c6c6591

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
