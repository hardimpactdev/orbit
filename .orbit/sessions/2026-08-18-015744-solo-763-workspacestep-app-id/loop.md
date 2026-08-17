# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/2/todo/p06-b-remove-redunda--763`
- Worktree: `/Users/nckrtl/orbit/.worktrees/solo-763-workspacestep-app-id`
- Branch: `solo-763-workspacestep-app-id`

## Goal

WorkspaceStep no longer stores a redundant app_id: Instance is the sole authoritative owner and App is derived through Instance (Instance.app_id), so the two can never disagree. The WorkspaceStep policy service, serializers, and queries resolve App via Instance; a migration audits/rejects any mismatched legacy rows (step.app_id != instance.app_id) fail-closed, then drops the app_id column.

## Scope

- Owned: apps/gateway WorkspaceStep model (remove app_id from fillable/casts/property docblock; replace the app() belongsTo(app_id) with an App accessor/relation derived through Instance), WorkspaceStepPolicyService (replace the app_id equality check + the app_id query filter with Instance-derived App resolution while keeping the instance_id authority check identical), workspace-step serializers/payloads (WorkspaceStepListPayload, WorkspaceLogPayload) if they read app_id, an SQLite-safe migration that audits legacy workspace_steps rows and fails closed on any step.app_id != instance.app_id (or instance missing) before dropping the app_id column, and focused Pest covering mismatched-row rejection, policy equivalence (App resolved via Instance == prior app_id behavior), and deleted/missing-instance relations.
- Constraints: Instance remains the authoritative workspace-step owner; keep serialized/API output and policy decisions identical for consistent rows; preserve existing error shapes; SQLite-safe migration; declare(strict_types=1); Mago/Rector clean. Do NOT run composer test:e2e*.
- Out of scope: WorkspaceRunStep ownership, workspace lifecycle/phase semantics, and unrelated workspace fields.

## Proof

- Verification:
  - focused: passed - gateway Pest workspace-step ownership/policy/actions/store suites green
  - broader: passed - composer quality-check exit 0 on 1d57dcb41, all 45 subgates zero, dirty false
  - runtime: passed - candidate=1d57dcb41193c168e7e7392d383605224a963cf2; venue=retained-incus; environment=dev-fixture; expected=WorkspaceStep drops app_id (Instance sole owner, App derived via Instance hasOneThrough), policy service resolves App via Instance with identical allow/deny and Instance-scoped query, migration fails closed on any step.app_id != instance.app_id or missing instance before dropping the column; observed=Part A workspace_steps has no app_id column on the retained gateway DB (fail-closed audit passed, 0 violating rows) + Part B 18 workspace-step tests passed on the retained-topology runtime; result=passed; command=`docker exec -e DB_DATABASE=/tmp/763-test.sqlite orbit-gateway php artisan test tests/Feature/Workspaces tests/Feature/Actions/Workspaces`; evidence=`.orbit/evidence/solo-763-retained-incus-proof.md`
- Blast radius: complete - evidence=repository-wide rg sweep of WorkspaceStep app_id consumers (model/policy/payloads/actions/migration); result=WorkspaceStep app_id fully removed, residual app_id refs are on the separate Workspace/Instance/ProxyRoute models (out of scope), WorkspaceRunStep untouched; see `.orbit/evidence/solo-763-blast-radius-inventory.md`
- Review: passed - human-judgment=not-required; independent Claude reviewer VERDICT PASS (app() hasOneThrough App via Instance, policy Instance-derived with unchanged instance_id authority, migration fail-closed audit before dropColumn, 31 passed/133 assertions, quality 1d57dcb41 45/45 zero)
- Reviewed feature tip: 1d57dcb41193c168e7e7392d383605224a963cf2
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 1d57dcb41193c168e7e7392d383605224a963cf2
- Accepted main tip: e8ba9fbb77f9aa7becf6ca2fb37a7ac8fc859dd8

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
