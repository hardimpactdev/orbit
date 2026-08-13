# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `/Users/nckrtl/shared-knowledge/projects/orbit/superpowers/specs/2026-08-13-node-removal-final-corrections-design.md`
- Worktree: `/Users/nckrtl/orbit/.worktrees/fix-node-remove-final-corrections`
- Branch: `fix/node-remove-final-corrections`

## Goal

Make node removal retry-safe when private DNS activation fails, and render the documented self-removal note from the gateway response.

## Scope

- Owned: node-removal registry and DNS commit boundary, retry error, activity facts, CLI `removed_self` rendering, authority docs, and discriminating coverage; primitive=remove one node identity; transitions=success:peer and registry identity are absent with DNS reconciled|failure:DNS failure restores registry rows and reports retryable failure|retry:repeat peer teardown and complete registry removal|stop-restart:n/a|stale:already-absent WireGuard peer is accepted on retry
- Constraints: preserve remote WireGuard teardown before registry mutation; preserve unrelated work; never run human-only E2E lanes
- Out of scope: broad Doctor extraction, unrelated node lifecycle changes, and gateway retirement

## Proof

- Verification:
  - focused: passed - gateway node-removal rollback/retry and CLI self-removal renderer tests
  - broader: passed - `composer quality-check` exit 0 for candidate 035e0398b44f7ebc86deee5459f6b0e679cec75a; evidence `.orbit/quality-gates/quality-check-2026-08-13T184236Z-5d2025fe504a.json`
  - runtime: passed - candidate=035e0398b44f7ebc86deee5459f6b0e679cec75a; venue=retained-incus; environment=dev-fixture; target=operator_gateway_app-dev; expected=injected DNS activation error keeps the node and retry removes it while self-removal prints the access note; observed=immutable private DNS directory caused a retryable DNS error with the node and records retained, retry removed both, and self-removal printed the documented note; result=passed; evidence=`.orbit/evidence/node-remove-retry-incus.txt`
- Blast radius: complete - evidence=`.orbit/evidence/node-remove-blast-radius.txt`; result=gateway, CLI, SDK, generated schema, tests, error registry, and node-remove documentation use the same response and failure vocabulary with no unresolved consumer
- Review: passed - Claude Opus Solo process 2394 closed its documentation finding and found no actionable issue; BLAST_RADIUS=complete; human-judgment=not-required; VERDICT=PASS
- Reviewed feature tip: 035e0398b44f7ebc86deee5459f6b0e679cec75a
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 035e0398b44f7ebc86deee5459f6b0e679cec75a
- Accepted main tip: 909949bb49f23d6ede9224379da957e4f89be687

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
