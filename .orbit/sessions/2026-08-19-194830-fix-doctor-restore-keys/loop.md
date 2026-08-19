# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad:
- Worktree: /Users/nckrtl/orbit/.worktrees/fix-doctor-restore-keys
- Branch: fix-doctor-restore-keys

## Goal

Doctor stops flagging the sanctioned 0775 user-private-group app directory shape as security drift, and instance-family issues actually dispatch to the app restorer instead of silently no-oping while the catalog advertises them as restorable.

## Scope

- Owned: `apps/cli` LocalAppIntrospectProbe permission policy (stat-based, linux and darwin); `apps/gateway` DoctorReportRunner instance-family dispatch with public-to-internal key translation; AppsFixer fixInstance security routing via instance placement; matching Pest coverage in both apps
- Constraints: world-writable always rejected; genuine group drift (foreign group) still flagged; existing app-family restore behavior unchanged; runner delegate call shape preserved for the architecture contract
- Out of scope: fleet redeploy of the fixed probe (ships with the next release), the accepted deferred container-isolation posture

## Proof

- Verification:
  - focused: passed - AppsFixerTest 16 passed 49 assertions including the instance security dispatch case; InternalAppIntrospectProbeCommandTest 4 passed with real 0775 and 0777 fixture directories; both new cases red-proofed against the pre-fix code
  - broader: passed - `composer quality-check` on clean commit c44fa3d5cf7908a07ab9c2ace9fa0de50514df58 exit 0, 45/45 subgates (`.orbit/quality-gates/quality-check-2026-08-19T174442Z-468c2b8078e0.json`)
  - runtime: passed - candidate=c44fa3d5cf7908a07ab9c2ace9fa0de50514df58; venue=retained-incus; environment=dev-fixture; target=orbit-e2e-dev-cc227b-gateway; expected=exact candidate accepts the sanctioned group-writable shape and rejects world-writable in the probe while instance-family drift dispatches through the runner to the fixer in the routed retained environment; observed=matching AppsFixer sha256 dfca5eb44ecd with 128 gateway tests passed 991 assertions in the gateway instance and 4 probe tests passed in the operator instance; result=passed; evidence=`.orbit/evidence/fix-doctor-restore-keys-retained-incus-runtime.txt`
- Blast radius: complete - evidence=fleet-observed false positives on five provisioned production apps plus repository search for permission-bit checks and restore dispatch arms; result=probe policy now mirrors LocalAppRuntimeAction's accepted shapes via portable stat modes, single dispatch site gains the instance arm with vocabulary translation before the match, fixInstance security routing reuses the existing app security repair with instance placement, architecture contract preserved
- Review: passed - orchestrator Claude reviewer VERDICT PASS: policy check portable across GNU and BSD stat with fail-closed null mode, key translation confined to the dispatch boundary, no change to app-family behavior, kan-defect expectation follows the todo-213 precedent; human-judgment=not-required
- Reviewed feature tip: c44fa3d5cf7908a07ab9c2ace9fa0de50514df58
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: c44fa3d5cf7908a07ab9c2ace9fa0de50514df58
- Accepted main tip: e2d92e0c905b8d8cb2d17392f9af4a24f0ac8b0e

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
