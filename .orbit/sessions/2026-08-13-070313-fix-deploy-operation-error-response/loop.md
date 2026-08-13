# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/75/scratchpad/retained-operation-j--425`
- Worktree: /Users/nckrtl/orbit/.worktrees/fix-deploy-operation-error-response
- Branch: fix/deploy-operation-error-response

## Goal

Retained Incus gateways keep the durable operation journal readable when live operations Reverb is unavailable, so `deploy:run` returns the documented `node.agent_unreachable` error instead of a generic HTTP 500.

## Scope

- Owned: Operations Reverb credentials in retained gateway API shims and journal fallback for deployment terminal errors.
- Constraints: Preserve the product operation-stream contract and the detached deployment response.
- Out of scope: Production Reverb configuration, deployment step behavior, and Agent transport retry policy.

## Proof

- Verification:
  - focused: passed - E2E gateway API tests and full E2E harness suite passed
  - broader: passed - `composer quality-check`; receipt=`.orbit/quality-gates/quality-check-2026-08-13T045148Z-306645777577.json`
  - runtime: passed - candidate=9b443ebd66b04a228d5275f069957143ab9853d4; venue=retained-incus; environment=dev-fixture; target=deploy operation terminal error replay; expected=the CLI reads the journal terminal error; observed=the CLI returned node.agent_unreachable and operation event 7 was the terminal error frame; result=passed; evidence=`.orbit/evidence/deploy-operation-error-retained-incus-dev-c61899.json`
- Blast radius: not-required - the change is limited to the disposable retained E2E gateway shim and its command-builder test; production already owns the same required credentials
- Review: passed - human-judgment=not-required; Claude Opus found no actionable issues and confirmed the shim now matches the production gateway credential contract
- Reviewed feature tip: 9b443ebd66b04a228d5275f069957143ab9853d4
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 9b443ebd66b04a228d5275f069957143ab9853d4
- Accepted main tip: 4a13205998552f1a151159476ee3a476a0752a3b

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
