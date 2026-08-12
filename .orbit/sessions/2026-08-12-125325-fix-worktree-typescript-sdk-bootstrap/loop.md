# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: /Users/nckrtl/orbit/.worktrees/fix-worktree-typescript-sdk-bootstrap
- Branch: fix/worktree-typescript-sdk-bootstrap

## Goal

Every prepared Orbit worktree installs the locked TypeScript SDK tools that the quality gate requires.

## Scope

- Owned: `bin/orbit-prepare-worktree`, its focused regression test, and loop proof.
- Constraints: Install from the committed lockfile without enabling gateway or docs frontend builds.
- Out of scope: Dependency upgrades, npm audit remediation, frontend builds, and E2E.

## Proof

- Verification:
  - focused: passed - each regression test failed before its script change, then passed; full artifact test 43 tests/565 assertions; `bash -n`; real `NODE_ENV=production` preparation installed `node_modules/.bin/tsc`; TypeScript SDK typecheck and build passed in that environment.
  - broader: passed - corrected candidate passed `composer quality-check`, including TypeScript SDK typecheck and build, at `.orbit/quality-gates/quality-check-2026-08-12T105136Z-7d4e5db0be43.json`.
  - runtime: not applicable
- Blast radius: complete - evidence=repository-wide search for TypeScript SDK setup and quality-gate consumers; result=the worktree setup was the only missing dependency install, while `quality-check.sh` always runs the SDK typecheck and build.
- Review: passed - independent general reviewer found no actionable issues; human-judgment=not-required
- Reviewed feature tip: 2e72b4275900c15a014af202ed455e8abb090bb5
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 2e72b4275900c15a014af202ed455e8abb090bb5
- Accepted main tip: 763449d05b5637cbef4ce56fdeba814288c5eb7f

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
