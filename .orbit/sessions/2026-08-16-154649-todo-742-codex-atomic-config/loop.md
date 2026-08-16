# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/2/todo/p22-a-make-codex-con--742`
- Worktree: `/Users/nckrtl/orbit/.worktrees/todo-742-codex-atomic-config`
- Branch: `todo-742-codex-atomic-config`

## Goal

Codex App add and remove mutations execute as one target-side locked read, merge,
atomic replacement, and apply action, without lost concurrent updates or a
change to the existing apply-warning contract.

## Scope

- Owned: Codex App gateway service/controller, target-side command and local action, remote-shell adapter, focused Pest coverage, and owning product docs. primitive=one atomic target-side Codex App config mutation; transitions=success:commit coherent merged config then apply|failure:preserve prior config on lock read write or replacement failure|retry:caller may retry after explicit lock cleanup|stop-restart:released lock permits the next mutation|stale:merge reads configuration only after lock acquisition
- Constraints: Preserve apply failures as success warnings after a coherent commit; skip replacement for unchanged state; use failing-first coverage; never run `composer test:e2e*`; preserve the parent checkout's `.codex/config.toml` edit.
- Out of scope: Codex App list semantics, Codex CLI lifecycle, public command names or payloads, unrelated app configuration, and E2E execution.

## Proof

- Verification:
  - focused: passed - CLI target 11 tests/50 assertions, including lock-acquisition and read-failure cleanup; gateway API 5 tests/27 assertions; CLI public command 6 tests/21 assertions; gateway merger 2 tests/6 assertions; scoped CLI and gateway Mago format/lint/analyze clean; Codex Librarian strict lint 0 findings
  - broader: passed - root `composer quality-check` passed all ten monorepo units for candidate 6227bbddfdbe34cdbb215c54cfe06a582b8d9887; evidence `.orbit/quality-gates/quality-check-2026-08-16T133413Z-0720ae8f3940.json`; `composer quality-gate:final-check` reported no warnings and did not rerun quality-check or E2E lanes
  - runtime: passed - candidate=6227bbddfdbe34cdbb215c54cfe06a582b8d9887; venue=retained-incus; environment=dev-fixture; target=orbit-e2e-dev-b8a852-dev; expected=serialized concurrent add and remove with coherent config unchanged-file preservation lock and temporary-file cleanup and apply-warning success semantics; observed=both waiters serialized both effects survived JSON and unrelated data stayed coherent unchanged retry preserved inode and bytes locks were available and apply failures returned success warnings; result=passed; evidence=`.orbit/evidence/todo-742-codex-atomic-runtime.md`
- Blast radius: complete - evidence=independent general reviewer repository-wide inventory of callers for the removed write/apply target actions and methods, operation-id leftovers, and SDK/core Codex references; result=no orphaned references, the internal command name and signature remain stable, and all affected references are confined to the command, tests, RemoteCodexAppConfig, and owning docs
- Review: passed - local Solo general reviewer process 2424 found no actionable findings; human-judgment=not-required
- Reviewed feature tip: 6227bbddfdbe34cdbb215c54cfe06a582b8d9887
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 6227bbddfdbe34cdbb215c54cfe06a582b8d9887
- Accepted main tip: 3933392fbd15199059f6dd90e71a83ca92ed170a

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
