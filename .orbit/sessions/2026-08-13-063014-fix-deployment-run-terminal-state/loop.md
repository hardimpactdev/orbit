# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: Solo project 74
- Worktree: /Users/nckrtl/orbit/.worktrees/fix-deployment-run-terminal-state
- Branch: fix/deployment-run-terminal-state

## Goal

Every deployment run reaches a terminal status when execution throws, and an older run cannot overwrite the instance status of a newer run.

## Scope

- Owned: Deployment run lifecycle writes in the gateway deploy flow; primitive=deployment run and instance latest deployment status; transitions=success:completed|failure:failed|retry:new run remains authoritative|stop-restart:hard process death remains Doctor-owned|stale:older terminal run cannot replace newer status
- Constraints: Preserve deploy response and error contracts. Preserve the public detached operation flow.
- Out of scope: Preventing concurrent deployments, changing Doctor stuck-run detection, or changing deploy steps.

## Proof

- Verification:
  - focused: passed - 41 deploy tests, 228 assertions; scoped Mago format and lint passed
  - broader: passed - `composer quality-check` receipt `.orbit/quality-gates/quality-check-2026-08-13T041224Z-f56585bb7448.json`
  - runtime: passed - candidate=3af0239a33176ed7da74f11d4cd7b2b8797a7aed; venue=retained-incus; environment=dev-fixture; target=gateway deployment run lifecycle; expected=deploy exception records a terminal outcome and newest run remains authoritative; observed=run 1 recorded terminal exit code 1 and run 3 remained latest after run 2 finished; result=passed; evidence=`.orbit/evidence/deployment-run-lifecycle-retained-incus-dev-c43f44.json`
- Blast radius: complete - evidence=repository-wide search; result=no remaining DeployManager detach argument or deploy status writer outside DeploymentRunLifecycle
- Review: passed - Claude Opus general review; human-judgment=not-required; blast-radius=complete; no actionable code findings
- Reviewed feature tip: 3af0239a33176ed7da74f11d4cd7b2b8797a7aed
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 3af0239a33176ed7da74f11d4cd7b2b8797a7aed
- Accepted main tip: a1df248a9b1ef8cf03ffb8dd438c767ef4cd4e64

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
