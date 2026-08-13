# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: source-thread audit and Solo project 2 process 2389
- Worktree: /Users/nckrtl/orbit/.worktrees/fix-operation-replay-cursor-contract
- Branch: fix/operation-replay-cursor-contract

## Goal

Operation-backed CLI subscribers resume durable replay from the operation-local
event sequence when global journal row IDs differ, without losing or duplicating
progress, process logs, or terminal delivery.

## Scope

- Owned: Core operation-stream cursor semantics; gateway replay parsing and querying; CLI durable-frame replay; discriminating cross-operation regression coverage; operation authority docs and missing dated product decisions. primitive=operation journal replay cursor; transitions=success:missed durable frames replay exactly once through terminal delivery|failure:malformed or incomplete live cursors fail closed while legacy journal frames retain their fallback|retry:reconnect resumes strictly after the last operation-local event sequence|stop-restart:a restarted subscriber uses the same operation-local sequence contract|stale:legacy cursorless or torn frames remain replay compatible
- Constraints: Keep the wire header name `Last-Event-ID`; preserve durable JSON fields, journal-first persistence, broadcast-failure recovery, terminal exit-code behavior, process-log streaming, and legacy compatibility; add no transport or schema; never run human-only E2E lanes.
- Out of scope: update:all lease corrections, Doctor extraction, node-removal teardown, publication, push, and release.

## Proof

- Verification:
  - focused: passed - Core operation stream 15 tests/27 assertions; CLI stream clients 14 tests/40 assertions; gateway event stream 10 tests/47 assertions; gateway journal/publish 41 tests/268 assertions; CLI operation/log flows 74 tests/341 assertions; scoped Mago, Librarian lint, secret scan, and diff check passed
  - broader: passed - `composer quality-check` passed every app and package; evidence `.orbit/quality-gates/quality-check-2026-08-13T125344Z-ef626336c6cf.json`
  - runtime: passed - candidate=6e44bda8206b1a96a5b69009725a1676e2b4676c; venue=retained-incus; environment=dev-fixture; target=dev-ccf7c6; expected=operation-local resume sequences replay later durable operation and process-log frames when global journal IDs differ; observed=global IDs 41-43 with local sequences 1-3 replayed sequences 2-3 including complete and process-log global ID 47 with local sequence 2 replayed after local sequence 1; result=passed; evidence=`.orbit/evidence/operation-replay-cursor-retained-incus.json`
- Blast radius: complete - evidence=repository-wide inventory of resumeSequence, eventId, eventSequence, lastSequence, lastEventId, and Last-Event-ID consumers across Core, gateway, CLI, and product docs; result=operation journal replay now uses the local sequence throughout, the separate legacy follower continues to use the gateway SSE id which is already local sequence, and browser process lifecycle SSE remains a distinct snapshot/high-water contract
- Review: passed - Fable process 2389 found no critical, major, or minor defects; confirmed the Core/gateway/CLI/docs cursor contract, discriminating tests, retained journal and process-log replay proof, legacy behavior, terminal delivery, and honest live-Reverb limitation; human-judgment=not-required
- Reviewed feature tip: 6e44bda8206b1a96a5b69009725a1676e2b4676c
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 6e44bda8206b1a96a5b69009725a1676e2b4676c
- Accepted main tip: 825d4a9bff476bec4f70043e5207915dff73e32b

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
