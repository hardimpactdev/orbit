# Phase 4g: production smoke, rollback and repair

**Approved design.** Smoke every opted-in production deploy; verified test failures restore good code and create a repair task. Unavailable results keep the release live and hold automatic deploys. App dependency squash auto-merge stays off until the nckrtl.com proof. Packages remain excluded. Batch and repair PRs go to Nick during proof.

## Shape and trust boundaries

Each repo owns Pest browser tests in `tests/Browser/`, tagged `smoke`, run by `composer test:smoke` through `.github/workflows/production-smoke.yml`. Features and smoke tests change together. `SMOKE_BASE_URL` selects local PR CI or production. The nckrtl.com pilot discovers public app GET routes without auth/guest middleware or required parameters, then crawls same-origin links (100 pages, five hops; fail on exhaustion). Exclude vendor/framework and secret-derived routes explicitly. Require 200 and expected content type; HTML gets same-origin error and all-origin image checks, including lazy images. Inertia also requires client mount/render proof. Ignore third-party scripts. Use 15-second assertion timeouts without rerunning failed tests; fail empty selection. No production writes or migrations.

Only projects we host may opt in through their private extension: `productionSmoke` names Instance, HTTPS URL, workflow/ref and timeout (initially ten minutes). Validate Project/repository ownership, an active Orbit production Instance and an active public Route HTTPS origin; refuse everything else. DLF qualifies; Oh Dear never does. Enrollment needs passing baseline smoke and retained rollback target. Dependency auto-merge has a separate, initially false switch.

**Use the repo's existing `ci.yml` `runs-on`.** Personal-account `nckrtl/website` uses `ubuntu-latest`. Never add/register runners; registration belongs to Nick. Gateway dispatches Actions with its GitHub App's `Actions: write`, accepted by Nick once per installation. CI checks out the deployed SHA with read-only checkout permission, no persisted Git credentials and no production, Gateway or Swamp secrets. Services runs trusted policy/Gateway calls, never app code. Task VMs run repairs.

Use native Swamp steps, durable waits/resources and unchanged official `@swamp/software-factory`. Swamp owns policy; Gateway owns effects. Automatic starts retain the [Gateway-peer contract][deploy]. The [Swamp deploy-writer cutover (#48, merged)][cutover] precedes integration; keep one deploy writer.

## Normal and failed runs

1. Observe every successful deploy, including manual/non-dependency deploys, independently of task cleanup. Record deployment/operation IDs, SHA, selection and previous smoke-passed retained release. Hold subsequent automatic deploys until smoke settles.
2. Dispatch smoke; bind run/attempt, workflow ref, checkout SHA, deployment ID and URL. Save output, test counts and screenshots. Accept a pass only while that release remains selected. Cancel older smoke holders on supersession; preserve their unknown receipts.
3. Only verified JUnit failures/errors from executed smoke tests block the SHA and trigger `instance-rollback` to the captured good release. Dispatch failure, GitHub outage, a never-started/cancelled run, workflow failure with clean JUnit, deadline expiry or unreadable/ambiguous evidence **never trigger rollback**, a SHA block or a code repair task. Keep the release live; hold Instance automatic deploys, raise assistance, alert Nick and retry smoke with backoff until a result exists. Then apply its verdict; a verified pass clears only its smoke hold and assistance. Guard rollback admission **and execution** against the failed deployment/release; changed selection gets fresh smoke. Leave main unchanged.
4. Verify rollback completion/selection and smoke the restored release; unavailable evidence follows the same hold/alert/backoff rule. After a verified pass, explicitly resume `auto_deploy`; verify the pause cleared and block remains. If rollback cancelled a queued auto deploy, manually redeploy the pinned restored SHA, smoke, then resume, as existing recovery requires. Normal automatic starts retain their API contract.
5. After a verified test failure, regardless of recovery success, create one default-agent Orbit repair task per failed deployment with SHA/releases, source PR, CI/smoke output, recovery receipts and migration constraint. Require a regression test and missing smoke coverage. Its PR follows CI → deploy → smoke → rollback/repair until passing. After proof, enabled policy may squash-merge repairs with verified task lineage.

Non-dependency merges use the same failure loop. Dependency batches, including agent-repaired batches, use the same automatic merge gates. A constraint or locked top-level breaking semver change (major for 1.x and above, minor for 0.x, patch for 0.0.x) requires hand-testing: keep its PR open and development Instance active. Unrelated repair and feature PRs use the ordinary path. Newer green commits may deploy and must pass smoke.

**Store blocks in `auto_deploy_state.blocked`.** A keyed failure record persists the deployed SHA block before rollback, even if recovery fails or selection changes. Read `auto_deploy.blocked`. Resume never clears blocks. A repaired SHA passing smoke resolves the incident; the bad SHA stays blocked permanently. No unblock or database edits.

**Failures stay visible.** Persist retry generations/backoff. Reconcile keys/run identity; bounded same-key Gateway admissions may retry, but never repeat an uncertain external GitHub send. Read final results before deadlines; late evidence remains usable. Retry transient refusals after the native wait, retaining codes and a separate budget. Failed rollback, missing target or failed restored smoke raises assistance and holds automatic starts; never cycle through older releases. Conditional resume preserves operator pauses. Show pending smoke and recovery failures; never leave automation silently paused. Repo CI proves migration compatibility with the previous release. Self-hosted runners also depend on GitHub.

Before Orbit hosts nckrtl.com, use read-only smoke directly on Beast against Laravel Cloud. Never invent a deployment ID; workflow dispatch requires a real Orbit production deployment.

## Orbit contract pairs

Pair contracts with Orbit: smoke dispatch/results; failure blocks, guarded rollback, hold/retry and conditional resume; keyed repair creation/lookup; CI-gated squash admission. Reuse history, operations and tasks. Repo workflows gain no deploy/merge authority. Write schemas/recovery in each pair, cross-link both PRs and review/merge/deploy together.

## Slices and proof

This design covers all slices; request DESIGN again only for changed shape, contract or trust boundary. Keep PRs under about 800 lines excluding generated files, fixtures and lockfiles. Split larger slices; never trim tests. Stop after each slice. Slice 6 ships with auto-merge off for every Project; enabling follows the live proof as a separate change.

| Order | PR scope and acceptance |
| --- | --- |
| 1 | nckrtl/website: smoke group and Actions workflow; prove render/console/status failures against a local fixture and run the group in PR CI. Website is private on a free plan: rulesets/protection return 403, so Nick accepts merge convention. No live mutation. |
| 1b | Website: discover/crawl every public app page, type-specific checks, all-origin lazy images; prove internal 404s, broken images and automatic new-route discovery. |
| 2 | Orbit + Swamp pair: keyed dispatch/results, hosted opt-in, baseline smoke, bounded admission recovery and native wait/restart tests. Unknown results cannot become test failures. |
| 3a | Orbit + Swamp pair: verified-JUnit failure block and guarded rollback; prove dispatch/outage/deadline/unreadable results never roll back, block or create code repairs. Test moved releases, pruning and lost replies. Live rollback stays off. |
| 3b | Orbit + Swamp pair: conditional recovery/resume, deploy-writer hold and persisted backoff/assistance/alerts for unavailable smoke; test late results, queued cancellation, operator pauses and restart recovery. |
| 4 | Orbit + Swamp pair: idempotent default-agent repair creation and existing task-factory routing; repeated failure produces a new incident, restart produces no duplicate task. |
| 5 | nckrtl.com acceptance/evidence: successful smoke, then **ask Nick and receive yes before the deliberately broken production deploy**. Record failure, rollback, block, restored smoke, resume, repair PR, repair deployment and passing smoke. Prove non-dependency failure too. |
| 6 | Orbit + Swamp pair: app-only squash admission with fresh exact-head required CI checks plus the named `Production smoke / smoke` check (even where GitHub cannot require it), default-branch and Services-created task lineage guards. Require the policy's exact smoke workflow and complete dependency-only changed-file evidence, unchanged manifest instructions, unchanged dependency names, registry-only constraints and registry-attested package identities bound to the manifest graph; refuse breaking semver changes while retaining their live development Instance; mint the squash token without Workflows write. Recheck the private merge switch before an unproven invocation. Ship disabled for every Project. Use the conversion-kit Gateway transport and Project-bound secret references for the squash effect. Enable nckrtl.com only in a separate change after row 5; test package exclusion, pending/failed CI and changed PR heads. |

Run affected tests with at most four workers; CI runs builds and static checks. Use official Swamp native recovery tests and Gateway contract tests. No production experiment is authorized here.

[deploy]: https://github.com/hardimpactdev/orbit/blob/main/docs/reference/deployments.md#swamp-policy-contracts
[cutover]: https://github.com/hardimpactdev/orbit-swamp/pull/48
