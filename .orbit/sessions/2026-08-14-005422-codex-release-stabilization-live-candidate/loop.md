# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `/Users/nckrtl/shared-knowledge/projects/orbit/evidence/stabilization-live-2026-08-13/`
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-release-stabilization-live-candidate`
- Branch: `codex/release-stabilization-live-candidate`

## Goal

Make release-candidate fleet updates fail closed on wrong runtime images and
repair a missing caller-local Orbit launcher from the exact checksummed
candidate artifact.

## Scope

- Owned: gateway archive-backed runtime deployment and verification; caller-local CLI artifact selection, checksum/version verification, launcher repair; matching product docs and focused coverage
- Constraints: preserve version 0.1.192; no broad Doctor extraction; never run human-only `composer test:e2e*`; retain the existing release candidate until its replacement is proven
- Out of scope: public GitHub release publication; unrelated Doctor drift; new feature or extraction work

## Proof

- Verification:
  - focused: passed - CLI 119 tests/550 assertions; gateway 65 tests/450 assertions
  - broader: passed - `composer quality-check` passed 45/45 subgates at exact clean candidate in `.orbit/quality-gates/quality-check-2026-08-13T225124Z-0638ea50dc76.json`; standalone `composer docs-lint` exit 0 in `.orbit/quality-gates/docs-lint-2026-08-13T225140Z-646d63c32e5a.json`; `composer quality-gate:final-check` reported no warnings
  - runtime: passed - candidate=51039cc89dba57cfdc34dc606dd78189d4c05701; venue=retained-incus; environment=dev-fixture; target=dev-8af004 operator launcher repair and gateway local-image Swarm service; expected=missing same-version launcher is repaired from the checksummed manifest artifact and the loaded candidate-style service image reaches 1/1 without registry resolution; observed=launcher 0.1.192 matched manifest sha256 and registry.invalid/orbit-proof:ba49 reached 1/1; result=passed; evidence=`.orbit/evidence/51039-retained-incus-proof.txt`
- Blast radius: complete - evidence=Fable process 2389 inventoried candidate manifest fields, RunsLocalUpdate implementations, runtimeRoleImage consumers, role image consumers, and all GatewaySwarmManager deployment callers; result=all affected paths use the exact candidate artifact contract and unrelated registry-backed deployment paths remain unchanged
- Review: passed - Fable process 2389; human-judgment=not-required; no actionable findings or open recommendations
- Reviewed feature tip: 51039cc89dba57cfdc34dc606dd78189d4c05701
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 51039cc89dba57cfdc34dc606dd78189d4c05701
- Accepted main tip: c27de055546c2d32782ebc6d3c888f56ee3e1412

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
