# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: Solo project 57
- Worktree: `/Users/nckrtl/orbit/.worktrees/refactor-doctor-proxy-restorer`
- Branch: `refactor/doctor-proxy-restorer`

## Goal

Move proxy restore behavior out of `DoctorReportRunner` into proxy-owned services without changing Doctor actions, ordering, or error details.

## Scope

- Owned: proxy issue routing, proxy restore and adopt actions, proxy action error details, direct tests, and `DoctorReportRunner` delegation.
- Constraints: preserve supported keys and modes, action order and data, route lookup, fallback-node behavior, and remaining-issue verification.
- Out of scope: proxy probing, final action verification policy, other Doctor families, and product behavior changes.

## Proof

- Verification:
  - focused: passed - 142 tests, 1,050 assertions; scoped Mago format, lint, and analysis passed
  - broader: passed - full Doctor unit and feature/API selection: 325 tests, 2,354 assertions; `composer quality-check` passed with exit 0 and all subgates at 0 in `.orbit/quality-gates/quality-check-2026-08-12T195453Z-bdbaa3a92cea.json`
  - runtime: passed - candidate=51c3604db8502b894c0265f2e743e9e68bf9dc53; venue=retained-incus; environment=dev-fixture; target=app-dev-1; expected=key-scoped managed Caddy restore converges and full proxy verification is healthy; observed=restore exit 0 fixed 1 with no failures followed by full verify exit 0 issues 0; result=passed; evidence=`.orbit/evidence/doctor-proxy-restorer-retained-incus.json`
- Blast radius: complete - evidence=old/new `DoctorReportRunner` method inventory and extracted proxy-key inventory; result=only proxy restore methods moved, while cross-family verification and all non-proxy methods remain
- Review: passed - Claude Opus found no remaining concrete findings; human-judgment=not-required
- Reviewed feature tip: 51c3604db8502b894c0265f2e743e9e68bf9dc53
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 51c3604db8502b894c0265f2e743e9e68bf9dc53
- Accepted main tip: 1c95f41cb4a4a670cc37ea4312daedaab41f897a

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
