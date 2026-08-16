# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: Solo Todo #748
- Worktree: /Users/nckrtl/orbit/.worktrees/solo-748-app-placement-shadows
- Branch: solo-748-app-placement-shadows
- Implementation Solo process id: 2414
- Retained-topology Solo process id: 2415
- General-reviewer Solo process id: 2417

## Goal

Instance is the single authority for app placement (node, environment, path,
document_root, domain) and adoption: all runtime/registration/deploy/proxy/
schedule/adoption/API readers resolve placement from the concrete Instance, the
synthetic runtime App clone/overlay in AppRuntimeContainerRenderer is removed,
App stops writing placement/adoption shadow data, and the `apps` shadow columns
(node_id, environment, domain, path, document_root, adopted) are dropped with a
safe backfill migration. Authority: PRODUCT_DECISIONS 2026-07-19 (apps own no
server/path/document-root/URL/domain/environment defaults) and 2026-08-05 (App
is the logical registry + shared-runtime-policy owner, no placement defaults).

## Scope

- Owned: apps/gateway App/Instance models, AppRegistrar + registration,
  AppRuntimeContainerRenderer + runtime policies, WorkspacePlacement, proxy/
  schedule/deploy/adoption readers, API resources/payloads + packages/sdk,
  migrations, factories/seeders, apps/docs/content placement docs.
- Constraints: phased reviewable commits with tests-first for non-UI behavior;
  migration backfills instances from app rows before dropping columns and fails
  closed on ambiguity; no broad new abstractions; environment derives per
  instance mirroring AppRegistrar::registrationEnvironment (domain + serving
  node role), matching existing AppRuntimeUser node-role logic.
- Out of scope: P08/P09 work beyond required reader migration; app-level worker/
  deploy_warmup/agent_ide_config columns; retained ADE rollback shadows.

## Proof

- Verification:
  - focused: passed - phased registration routing deploy process proxy schedule adoption API and migration checks; final gateway suite 6305 passed and 6 skipped; Mago analyze lint and format clean; Rector dry-run clean
  - broader: passed - `composer quality-check` and `composer docs-lint` exit 0 on candidate aae3c2abc491c616bc8dfe039fe77962dcd706b7; final evidence analyzer reports no warnings; receipts `.orbit/quality-gates/quality-check-2026-08-16T123029Z-ea95fe15d7e0.json` and `.orbit/quality-gates/docs-lint-2026-08-16T124344Z-03b117861962.json`
  - runtime: passed - candidate=aae3c2abc491c616bc8dfe039fe77962dcd706b7; venue=retained-incus; environment=dev-fixture; target=topology dev-fa33f8 kind operator_gateway_app-dev nodes operator-1 gateway app-dev-1; expected=logical App has no placement shadows and concrete Instance placement drives registration process routing and served source; observed=apps schema omitted all six shadows registration converged process reached running and the instance route returned proof-748 with HTTP 200; result=passed; evidence=`.orbit/evidence/todo-748-retained-incus-dev-fa33f8.md`
- Blast radius: complete - evidence=`.orbit/evidence/todo-748-general-review-process-2417.md`; result=repository-wide source inventory found no remaining App placement shadow reads App node relation loads synthetic runtime App source or calls to removed App helpers
- Review: passed - human-judgment=not-required; independent local Solo reviewer process 2417; evidence=`.orbit/evidence/todo-748-general-review-process-2417.md`
- Reviewed feature tip: aae3c2abc491c616bc8dfe039fe77962dcd706b7
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: aae3c2abc491c616bc8dfe039fe77962dcd706b7
- Accepted main tip: a7a5e03e356447241893015868a0c80370acd614

## Phases

- P1 5e95daf0c DONE: WorkspacePlacement::environmentFor/environmentForInstance; AppRegistrar delegates. Behavior-preserving; no column dropped.
- P2 b953a4c86 DONE: AppStoreController::ensureDefaultInstance sources placement from node/input/source; stops reading back App columns in creation path. Behavior-preserving.
- P3a e381ac29a DONE: renderer-internal clone removed; renderer + direct internal policies instance-authoritative via WorkspacePlacement runtime helpers. runtimeAppForInstance retained for external consumers.
- P3b split into 4 green sub-commits (approved):
  - P3b-1 61f8ed619 DONE: field-reading consumers (EnsureFrankenPhpRuntimeProcess, AppWorkerReadiness, InstanceEnvApplier+RemoteAppCacheClear, DoctorRestorer). full suite green.
  - P3b-2 e20a50b1d DONE: proxy enactment + probe (EnactAppRuntime, EnsureAppProxyRoute, ProxyRouteFixer, AppsProbe, AppOwningNodeResolver). full suite green.
  - P3b-3a 2425a5fdd DONE: deploy chain (DeployManager deployApp/runContext/warmup, AppCommandRouter, ActiveReleaseRuntimeActivator, DeployOperationRunner). full suite green.
  - P3b-3b d4c2915cb DONE: setup/env chain (SetupApp/Progress, InstanceEnvRenderer, AppSetupStepRunner, vite) + REMOVED runtimeAppForInstance clone. full suite green.
  - P3b-4 (ProcessRuntimeApp removal) split into 4 green sub-commits (approved):
    - P3b-4a 9e747c601 DONE: ApplicationLogPathResolver leaf.
    - P3b-4b 7a40c732d DONE: process-unit renderers (systemd/launchd/docker) + ProcessOwnerContext::runtimeApp resolve placement from process instance.
    - P3b-4a 9e747c601 DONE: ApplicationLogPathResolver.
    - P3b-4b 7a40c732d DONE: process-unit renderers + ProcessOwnerContext resolve from process instance.
    - P3b-4c (nullable-App contract) DONE: driver interface + 4 drivers + spec factory + 3 renderers + payload accept ?App; ProcessOwnerContext::runtimeApp()/ProcessExpectedRuntimeUnits return null for node-owned; RemoveApp/EnsureAppProcessRuntimeUnits/ProcessRuntimeContextResolver drop the clone. No synthetic App fabricated (per orchestrator acceptance correction).
    - P3b-4d ca36b4a7b DONE: deleted ProcessRuntimeApp. Both synthetic runtime App clones now removed; no `new App(` in gateway runtime code.

## Phase 4 (stop App placement/adoption writes + migrate remaining plain App-column readers) — complete
- Deferred to P4 (plain app-column reads, not clones): DeployManager::productionInstance app->environment; AppsProbe checkRecordCompleteness/checkOwnerNode/checkProductionSecurity/isProductionApp; AppRegistrar routeInstance + registration fallbacks; App model url()/documentRootPath().
- P4 planned: stop App placement writes (AppRegistrar, AppStoreController); AppsProbe/AppListController/App model helpers instance-authoritative.
- P5 planned: fail-closed backfill migration + drop apps.{node_id,environment,domain,path,document_root,adopted}; AppFactory placedOn() + test migration.
- P6 planned: docs — PRODUCT_DECISIONS new dated entry + reconcile 2026-07-19 L130 clause; confirm domains/5_app already aligned.
- P4c-P7 DONE in 0a77f56cf: stopped App placement/adoption writes; removed App::node and all synthetic runtime App objects; migrated registration, routing, deploy, process, proxy, schedule, adoption, and API readers to concrete Instance placement; added fail-closed backfill/drop migration; redesigned logical-only AppFactory fixtures; full gateway suite 6305 passed / 6 skipped.
- Docs DONE in aae3c2abc: recorded the 2026-08-16 product decision and logical-only App persistence invariant.

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
