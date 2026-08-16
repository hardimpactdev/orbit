# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/2/todo/p06-a-make-json-and--745`
- Worktree: `/Users/nckrtl/orbit/.worktrees/solo-745-workspace-setup-plan`
- Branch: `solo-745-workspace-setup-plan`

## Goal

Make JSON and SSE workspace setup and workspace creation execute the same ordered operation-specific plan and return the same operation-specific result or failure model, with parity for phases, errors, rollback state, and final results.

## Scope

- Owned: `apps/gateway/app/Actions/Workspaces/**`, `apps/gateway/app/Http/Controllers/Api/Workspace{Setup,Store}Controller.php`, mapped gateway parity tests, and workspace new/setup product contracts; primitive=operation-specific ordered workspace plan with JSON and SSE adapters; transitions=success:same final result in JSON and SSE|failure:same phase-specific failure in JSON and SSE|retry:rerun the same ordered plan from retained state|stop-restart:n/a|stale:workspace intent and source rollback state remains explicit
- Constraints: Preserve public JSON and SSE envelope shapes except for the intentional parity correction required by product authority; use test-first literal RED evidence; keep setup and create plans separate; preserve parent `.codex/config.toml`; local Solo only.
- Out of scope: Generic setup/deploy/teardown workflow engines, CLI renderer redesign, unrelated workspace lifecycle changes, and manual E2E lanes.

## Proof

- Verification:
  - focused: passed - evidence=`.orbit/evidence/solo-745-fix-7c69cc618eca.txt`
  - broader: passed - evidence=`.orbit/evidence/solo-745-fix-7c69cc618eca.txt`
  - runtime: passed - candidate=7c69cc618eca5d8ef5efd43408a59f535b6e9d9e; venue=retained-incus; environment=dev-fixture; expected=canonical JSON and terminal SSE setup parity plus lifecycle expected and durable adoption after complete E2E fixture ownership repair; observed=JSON and SSE setup converged with canonical envelopes, adoption persisted in setup show and list, and exact eight-file hashes matched the synced topology; result=passed; target=dev-69c129; evidence=`.orbit/evidence/solo-745-retained-incus-7c69cc618eca.json`
- Blast radius: complete - evidence=`.orbit/evidence/solo-745-rereview-7c69cc618eca.txt`; result=repository-wide inventory found 11 direct Workspace creations across 9 files, 0 missing instance_id, and all 13 relevant App seed blocks logical-only
- Review: passed - evidence=`.orbit/evidence/solo-745-rereview-7c69cc618eca.txt`; human-judgment=not-required
- Reviewed feature tip: 7c69cc618eca5d8ef5efd43408a59f535b6e9d9e
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 7c69cc618eca5d8ef5efd43408a59f535b6e9d9e
- Accepted main tip: e73d2891ce3a3b2901dace0e10133c52391e22dd

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
