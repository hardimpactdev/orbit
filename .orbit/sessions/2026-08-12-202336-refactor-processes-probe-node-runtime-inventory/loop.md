# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: /Users/nckrtl/orbit/.worktrees/refactor-processes-probe-node-runtime-inventory
- Branch: refactor/processes-probe-node-runtime-inventory

## Goal

Move the node-wide managed app container inventory out of ProcessesProbe without changing Doctor process issues, ordering, or progress.

## Scope

- Owned: node-wide container scan parsing, orphan-container drift, Doctor process-family wiring, and focused parser coverage.
- Constraints: preserve the `process` family, exact issue keys and payloads, scan sentinel behavior, container order, one node-inventory progress step, and Throwable-to-error behavior.
- Out of scope: per-process runtime observation, expected runtime unit construction, per-process drift checks, restore behavior, product documentation behavior, and human-only E2E lanes.

## Proof

- Verification:
  - focused: passed - 10 parser, transport, and drift tests; 39 assertions
  - broader: passed - 218 process and Doctor tests; 1,515 assertions; gateway Mago format, lint, and analyze passed; `composer quality-check` passed
  - runtime: passed - candidate=c09831cc64e4ff2d21ceea8160b353ebfb6d2af4; venue=retained-incus; environment=dev-fixture; command=orbit doctor --node=app-dev-1 --family=process --json; expected=Doctor completes the process-family scan successfully; observed=exit code 0, healthy true, zero issues and all action counts zero after exact-candidate sync; result=passed; evidence=`.orbit/evidence/retained-incus-process-inventory.txt`
- Blast radius: complete - evidence=repository-wide search; result=DoctorProcessFamilyProbe is the only caller, old ProcessesProbe node-inventory methods have no remaining references, and exact Doctor controller coverage passed
- Review: passed - Claude Opus confirmed exact issue data, ordering, progress, scan sentinels, Throwable handling, and restore consumers remain unchanged; human-judgment=not-required
- Reviewed feature tip: c09831cc64e4ff2d21ceea8160b353ebfb6d2af4
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: c09831cc64e4ff2d21ceea8160b353ebfb6d2af4
- Accepted main tip: ca2bff245aa912e39b85e9a658ac2c7368b68ae4

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
- Agent session capture waivers: Claude Opus process 2326 returned `exact_marker_not_found`; its exact terminal verdict is retained at `.orbit/evidence/claude-opus-review.txt`. Retained Incus terminal 2327 is a terminal process and has no provider session; its commands and result are retained at `.orbit/evidence/retained-incus-process-inventory.txt`.

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
