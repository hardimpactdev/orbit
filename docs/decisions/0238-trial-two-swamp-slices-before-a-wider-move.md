---
title: "ADR 0238: Trial two Swamp slices before a wider move"
sidebarTitle: "0238 Swamp slice trials"
description: "In progress. Measure automatic production deploy policy and the tool command group in Swamp before moving more Orbit work."
---

# ADR 0238: Trial two Swamp slices before a wider move

Trial automatic production deploy policy and the `tool` command group in [orbit-swamp](https://github.com/hardimpactdev/orbit-swamp). Measure code, tests, CI, reliability, and effort before deciding whether to move more work. Each slice must keep every current feature.

## Status

In progress.

Principle: [Lean](/mission#principles). Move work only when measured savings justify its cost. The trial proposes a narrow exception to [One way, one name](/mission#principles): Swamp owns these two slices while the Gateway operation engine and agent runner keep their other users. Each migrated behavior has one active owner. There is no runtime fallback or duplicate writer.

The project owner accepted the thresholds on 2026-10-27 and amended the code gate and cutover requirements on 2026-10-28. Slice 1 (automatic production deploys) may continue. On 2026-10-28 the project owner authorized direct production cutover for slice 1 and waived its shadow phase because Hauzer and Mealou have no users. The 14-day live reliability window starts when this cutover release becomes active. Other slices retain the shadow requirement. The current [deployment](/reference/deployments), [Tool](/reference/tools), and [Node command](/reference/node-agent#commands) contracts remain in force until a slice ships.

## Context

The goal is a smaller Gateway and less Orbit CI time, with the same features. Moving logic, state handling, and workflow steps into orbit-swamp is intended. Its code growth is reported but does not gate the trial. A hard dependency on Swamp is accepted. Use Swamp's defaults: Deno, the official `@swamp-club/swamp-testing` kit, and `@swamp/software-factory` without a fork. Do not build an Orbit workflow wrapper or a second test framework.

A read-only study at Orbit commit `46afd964c4557140c9dae18ae6949eb349a46b70` found the move viable in part. These are estimates from that study, not measurements of the trials:

| Scope | Orbit source lines removed | Orbit test lines removed | CI runner-minutes saved per run | CI wall-clock saved |
| --- | --- | --- | --- | --- |
| Gateway orchestration only | 5,000–12,000 | 8,000–20,000 | 0.3–2 | Not estimated |
| Broad move, including most of the 321 agent commands | 60,000–90,000 | 60,000–100,000 | 10–20 | 3–8 minutes |

Most potential CI savings depend on Rust agent delivery builds. Moving tests alone does not remove those builds. A moved command can still leave shared code and dependencies behind.

The main risks are a write whose outcome is unknown after a worker drops, loss of live output, and loss of locks or authorization. The pinned Swamp studied returns console output with the final result. That alone cannot replace live deploy output. Record the exact Swamp version and package pins when each trial starts; remeasure after an upstream change.

Swamp runs on Deno, whose team [joined Cloudflare](https://deno.com/blog/cloudflare). Monthly bug and security releases continue until about October 2027, then official runtime development ends. Orbit follows Swamp's runtime choice; if Swamp moves off Deno, the slices' extensions and tests migrate with it. On 2026-10-23, a Swamp team member said in the Swamp Discord that extensions will not need a rewrite into another language because "plenty of runtimes" can run TypeScript, which lowers the migration risk to runtime-specific APIs such as `Deno.*` calls rather than a language rewrite.

## Decision

Run two bounded trials. Complete and measure each slice before approving a wider move. A successful first slice does not waive the second slice's gates.

### Slice 1: automatic production deploys

Swamp owns the production policy now in `RunAutomaticDeploysAction` and `AutomaticDeployHistory`: commit selection, gate checks, retry backoff, blocked commits, pause state, and alerts. It calls Gateway primitives to deploy. The Gateway keeps deployment effects, target locks, validated inputs, authorization, and deploy history. Orbit keeps deployment of development defaults; shared policy code is split by behavior and removed only where no retained caller needs it.

The [frozen slice 1 parity cases](/reference/automatic-deploy-parity) name the expected policy results and existing public-surface proof.

Two public contracts are prerequisites:

- **Validated pinned-commit deploy.** A supported Gateway API accepts a full commit SHA and starts the existing `instance.deploy` operation for exactly that commit. It validates the SHA, Instance eligibility, repository identity, and branch membership. A moved branch head cannot change the selected commit. The internal optional commit argument is not yet this public contract.
- **Trusted automatic trigger.** The Gateway derives automatic origin from an authorized caller capability. A caller cannot gain it by supplying `triggered_by`. The receipt binds caller, Instance, commit, and a stable request key. Repeating that request returns the same operation; reusing the key for different input fails. Manual deploys and rollbacks retain their identity and pause automatic deploys, including failures and running attempts. Resume retains the rollback block. The Gateway checks the pause again under the Instance lock before effects start, so a manual action after Swamp's policy read still wins under the current rules.

Keep the current policy cases: newest eligible green first-parent commit; no record for an unchanged commit; pending and failed check rechecks; busy versus deploying; retries after 10 and 20 minutes with three attempts at most; immediate blocking of lasting failures; explicit resume; rolled-away commit blocks after history pruning; and one alert per failure, stall, or pause. [Automatic deployments](/reference/deployments#automatic-deployments) owns the detailed rules.

Before coding, name eight policy flows for effort and case accounting: selection, unchanged/busy handling, gate recheck, backoff/blocking, manual pause, rollback/resume, alerts, and state/history projection. This denominator stays fixed even if one flow needs several functions.

### Slice 1 progress as of 2026-10-28

Three prerequisite PRs are merged. [Orbit #1546](https://github.com/hardimpactdev/orbit/pull/1546) adds pinned-commit deploys, the trusted automatic trigger, and frozen parity cases. [orbit-swamp #46](https://github.com/hardimpactdev/orbit-swamp/pull/46) adds the policy workflow with 73 passing tests. It remains inert. [Orbit #1548](https://github.com/hardimpactdev/orbit/pull/1548) adds Gateway contracts G01–G06 for source evidence, resume and block state, deployment kind, event delivery, publication, and receipt lookup. The slice 1 cutover removes the PHP production policy and binds the Gateway writer to Swamp. Development defaults remain on PHP. Live acceptance is pending the 14-day window. The owner accepts one possible repeat alert at cutover; Orbit adds no contract for stored alert keys. The adapter is delivered in [orbit-swamp #48](https://github.com/hardimpactdev/orbit-swamp/pull/48).

The measurements below come from the PR bodies and the implementation report for Orbit #1546. Its final line counts come from the merged diff. Line counts are physical lines, including comments and blanks. Each pair below is added/removed. Correction rows are commit deltas, not final PR totals.

| PR and scope | Agent wall-clock | Source | Tests and support | Fixtures | Docs | CI config | Generated |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Orbit #1546, first PR head | 24m 39s | 286/37 | 355/3 | 0/0 | 154/8 | 0/0 | 240/19 |
| Orbit #1546, final merged diff `6291bb03..f9059e10` | See timing below | 227/37 | 340/9 | 0/0 | 140/9 | 0/0 | 353/29 |
| orbit-swamp #46, initial implementation | 24m 14s | 1,016/0 | 1,310/0 | 0/0 | 86/0 | 72/0 | 0/0 |
| orbit-swamp #46, first correction | 8m 36s | 87/35 | 237/24 | 0/0 | 7/3 | 6/0 | 0/0 |
| orbit-swamp #46, delta correction | 4m 43s | 36/44 | 97/30 | 0/0 | 7/3 | 0/0 | 0/0 |
| Orbit #1548, final PR diff against `6c71412e` | 44m 54s | 974/135 | 489/9 | Included in tests | 38/1 | Not reported separately | 1,953/167 |

Orbit #1546 reports 24m 39s on 2026-10-27, from 21:42:00 to 22:06:39 UTC, or about 29m 42s including review runs. The first-head figures precede two fix commits: web schema regeneration, then contract-test fixes and removal of the automatic-deploy CLI command. The final line counts include both fixes. They come from `git diff --shortstat 6291bb03..f9059e10` scoped by area. Generated files cover OpenAPI, MCP, web types, navigation, and documentation context. Source includes the generator. Tests include Rust test files. Docs include the CLI reference and SDK guidance. The complete diff has 1,060 additions and 84 removals across 38 files. No separate timing for the fix commits was reported.

The total for orbit-swamp #46 is 37 minutes and 33 seconds across its three measured intervals. Orbit #1548 reports 23m 13s for implementation and 21m 41s for review fixes. Both totals exclude external review gaps. Owner time was not measured. These are implementation measurements, not proof that the slice passes its gates. Slice 1 alone offers little Orbit CI saving. Savings are expected as more slices reuse the Gateway contracts.

Swamp ticks every minute at second 30 (`30 * * * * *`). Gateway discovery and automatic admission require an enabled, active production Instance on a Node whose `app-prod` role is active. The Gateway owns that eligibility rule; Swamp uses `GET /api/v1/instances?auto_deploy=true`.

To switch policy back:

1. Disable `auto_deploy` on the affected Instances.
2. Wait until no automatic operation is running or queued.
3. Revert [orbit#1555](https://github.com/hardimpactdev/orbit/pull/1555) (code-only, no migrations).
4. Re-enable `auto_deploy` (PHP owns again).

### Slice 1 cutover measurements

This Orbit cutover is measured against `40561b4aec9288032476ea8114653b0fe91f3374`. Counts are physical diff lines, including comments and blanks. Source includes the Gateway, CLI, SDK, and OpenAPI generator. Tests exclude fixtures and expected renderings. Documentation includes SDK guidance. Generated files include OpenAPI, MCP, web types, navigation, and documentation context.

| Area | Removed | Added | Net removed |
| --- | --- | --- | --- |
| Source | 472 | 413 | 59 |
| Tests | 706 | 264 | 442 |
| Documentation | 27 | 88 | -61 |
| Generated | 10 | 302 | -292 |
| Fixtures and expected renderings | 0 | 61 | -61 |

Source detail: CLI and SDK 1 removed and 168 added; Gateway 469 removed and 228 added; generator 2 removed and 17 added. Fenced receipts, original-caller resolution, and Activity auditing are reusable Gateway boundary infrastructure. This cutover reuses the source, publication, event, and receipt contracts from #1548.

Whole-file removals are `apps/gateway/tests/Feature/Domain/AutoDeployTest.php` (652 lines), `GreenCommitInspector.php` (25 lines), and `GreenCommitInspection.php` (19 lines). The unused inspector binding, head-check state, and inspector tests are gone. `GreenCommitResolver::resolve()` remains for automatic Gateway releases; its `inspect()` walk returns the selected commit directly. The retained `RunAutomaticDeploysAction.php` removes 229 lines and adds 9; `AutomaticDeployState.php` removes 134 and adds 2; `AutomaticDeployHistory.php` removes 22 and adds 11. These classes retain development defaults, rollback evidence, public projection, and alert subjects. The command and scheduler remain because development defaults still use them. Production policy tests move to orbit-swamp; shared deploy, rollback, and history tests stay in Orbit.

Implementation agent wall-clock: 31m 37s, from 2026-10-28 10:32:15 UTC through final local verification. Independent review ran on the working diff; its time was not separately instrumented. Owner time was not measured. The cutover figures exclude the prerequisite PR deltas above and orbit-swamp #48. They do not establish cumulative slice savings or passed trial gates. No CI saving or live reliability result is claimed; the live window starts when the cutover release is active.

### Slice 2: the tool command group

Move the 45 `tool.*` agent commands and their Gateway host adapters into a Swamp extension. A Swamp worker on the target Node runs host work. Swamp also drives the host steps of the Gateway Tool actions. Orbit keeps Tool and manager records, input constraints, package protection, role-package ownership, authorization, and the public CLI, API, MCP, and web behavior. Thin Gateway actions enforce those rules and admit work; they do not keep a second host executor.

Cover apt, Composer, Homebrew formulae and casks, and Vite+ scopes and stores. Inventory, adoption, version checks, removal plans, manager materialization, and Project environment preparation belong in the trial. Count 45 ported commands even when the extension groups several commands into one method. [Tools](/reference/tools) owns the behavior checklist, including platform limits and refusal codes.

The `tool.*` group also serves provisioning, recipe convergence, development deployment, and task checks. Move their ordinary callers to the same extension path before removing those command entries. A command name is not proof that its implementation has no other users. Do not remove a shared helper while any caller remains. Recovery work must not acquire a Swamp dependency: isolate the fixed native bootstrap or repair effect it needs, or leave that command unported and mark the 45-command slice incomplete. Do not duplicate a general Tool executor for recovery.

Gateway admission and the Node worker preserve the existing Tool target and manager-scope locks. Homebrew formulae and casks still share their prefix lock. Locks also coordinate with retained role and recipe writers. Keep the current privilege, enrolled-account, platform, timeout, package-protection, checksum, and safe-removal rules. A Swamp worker receives only the authority needed for the authorized Node action; it does not bypass Gateway access checks by accepting arbitrary host commands.

### Shared machinery and recovery

Agent dispatch and transport, the Gateway operation engine, and the Rust build stay until their last user leaves. Record each remaining caller and dependency before claiming its removal. Keep Swamp worker bootstrap, Services repair, and Gateway self-release Orbit-native. A failed Swamp service must be repairable without Swamp. Do not count those retained paths as savings.

Swamp owns policy or Tool host execution; Gateway primitives own the effects that remain in Orbit. Each primitive has one authoritative effect receipt. Swamp stores its workflow state and references that receipt. Web and CLI history may combine these reads, but must not invent a second success status.

A lost connection is not proof that a write stopped. After worker loss, read the effect receipt and observed host state. Replay only an effect proved safe to repeat. Hold conflicting work until the previous writer has stopped and its outcome is known. A cancelled run must not release a lock while its host process still writes. An unresolved outcome blocks that target and fails the trial; it never becomes an automatic retry. This applies to installs and removals as well as deploy activation and user deploy steps.

Retain cooperative deploy cancellation and activation cleanup. Tool cancellation keeps its current bounded interruption behavior. A disconnected follower can reattach. Live output needs an explicit supported channel and durable bounded history. Use existing Gateway deploy output where the Gateway still runs the deploy. For Node-worker Tool work, prove an equivalent supported stream before switching writers. Console output delivered only at completion fails parity. Do not fork Swamp or add a generic Orbit workflow engine to fill a gap; keep the slice unshipped if supported upstream contracts cannot meet it.

### Relation to existing decisions

This ADR proposes limits to these decisions for the two slices during the trial. It does not supersede them yet.

| Contract | Trial limit | What still applies |
| --- | --- | --- |
| [0209: durable operations](/decisions/0209-run-long-operations-as-durable-queued-operations) | Swamp owns automatic production policy and Tool workflow execution rather than putting them into the Gateway engine | Pinned deploy effects still use Gateway operations, target exclusion, receipts, recovery, cancel, and history. Other operation kinds stay there |
| [0218: request path](/decisions/0218-take-long-commands-off-the-request-path) | Tool host execution may leave the bounded synchronous request path for a Swamp worker | Keep caller-visible deadlines, results, and errors. A new response or follow mechanism must pass parity in every client. Other commands and the deployment-history plan keep their scope |
| [0229: external orchestration](/decisions/0229-let-an-orchestrator-drive-task-work-through-gateway-operations) | Extend external orchestration beyond Tasks; Tool host effects may run on a Node worker instead of through Gateway-owned effects | Tasks contracts remain. Gateway-owned primitives retain their authority and receipts; this creates no permission for a broader move |
| [Node commands](/reference/node-agent#commands) | These Tool host commands may leave `AgentCommandRunner` for the Swamp extension | Every other Node action retains typed agent dispatch. Architecture rules need a narrow, tested Tool boundary when that slice ships |

This proposal adds no Laravel extension contribution. If implementation adds one, apply [Add a plug-in point](/reference/extensions#add-a-plug-in-point). A Swamp extension is not itself a Laravel plug-in point.

### Feature parity checklist

Freeze named cases and expected results before implementation. Run each case before and after in both web and CLI where the feature exists today. Add API and MCP checks for their contracts. Mark a surface absent only with evidence from the baseline; absence is not permission to drop another surface.

| Area | Required cases |
| --- | --- |
| Output | Same result fields, exit status, redaction, warnings, ordering, truncation, and stored tails; live lines arrive during work |
| Progress | Queued, running, current step, terminal status, reconnect, and concurrent targets remain visible |
| Cancel | Queued cancel, mid-step cancel, late deploy cancel, client disconnect, and worker loss preserve the current effects and lock rules |
| History | Web and CLI list, show, target links, commit/version, trigger, failure boundary, and retention match; a lost client finds its run |
| Errors | Same refusal codes, constraint blocks, busy outcomes, timeouts, and recovery advice; no false success after an unknown result |
| Authorization | Allowed and denied callers, target Node access, automatic-trigger spoofing, privilege, protected packages, role ownership, and shared locks |

Any feature loss fails a slice. A faster result cannot offset it.

### Measurement and proposed thresholds

Use the same scope, cases, hardware class, runner labels, cache state, and concurrency before and after. Record Orbit and orbit-swamp SHAs, Swamp and package pins, workflow run IDs, cases, and exclusions. Remeasure the immediate pre-trial main if CI or source changes after this baseline. Runner-policy changes in other PRs are not savings from this trial.

| Measure | Method | Proposed gate for the project owner |
| --- | --- | --- |
| 1. Net code | Count source and tests in both repos using the census below; include new glue and report reusable Gateway contracts separately | Positive Orbit net source and test removal per slice after cutover; report orbit-swamp growth without a code gate; no credit for retained shared files |
| 2. Test cost per behavior | Freeze equivalent cases; run old Rust/Pest and new official-kit Deno cases ten times each in CI | New median time at most 80% of old time per slice; no omitted cases |
| 3. Orbit CI | Compare ten successful full-main runs per side; split setup, checks, tests, and builds; charge new orbit-swamp CI | No net CI increase per slice; report measured agent build and size changes for Tools |
| 4. Features | Record expected and actual results for every frozen parity case | Every case passes; any loss fails |
| 5. Reliability | Run the live windows below; record failures, stuck runs, unknown steps, and intervention time | No new unexplained failure, stuck run, or unresolved unknown write; failure rate no worse than before |
| 6. Friction | Log paired PRs, extra wait for the second CI, deploy-order mistakes, and manual coordination | At most two paired PRs, five minutes median extra CI wait, and one hour of coordination per slice; zero deploy-order incidents |
| 7. Effort | Record agent wall-clock time and owner review/coordination time separately; divide by eight policy flows and 45 Tool commands | Owner time at most three hours for automatic deploys and eight hours for Tools; agent wall-clock time is recorded and projected, without a gate |

For net code, report Orbit removed and added lines and orbit-swamp removed and added lines separately. Orbit covers the Gateway, agent, and their tests. Orbit net removal is removed lines minus added lines and must be positive for both source and tests after each slice's cutover. orbit-swamp additions need no offset. Count Gateway contracts added for Swamp separately as reusable infrastructure: its "eyes and hands" for reads, receipts, event delivery, and publication. Report how much each following slice reuses them. Count each shared addition once. Report source, tests, and fixtures separately. For matched-case tests, report median seconds and runner-minutes per case set, with setup separate. Preserve negative, crash, cancel, and lock cases.

For CI, sum affected job durations for runner-minutes. Report their elapsed span and the whole workflow's wall-clock. For Tools, report release binary bytes per target, compressed delivery bytes, build-step time, removed dependencies, and remaining build users. Report zero build saving if none is measured.

Except for the project owner's slice 1 waiver on 2026-10-28, before each slice's cutover run Swamp against real data in read-only shadow mode for a set window. Record each decision it would take without effects. The active Orbit writer keeps ownership. Compare the shadow decisions with that writer's real decisions. Explain or fix every mismatch before cutover. The waived slice 1 proposal was at least three days and ten policy decisions across two production Instances. Other slices still require shadow evidence and must extend a quiet window until their counts are met.

For reliability, measure before and after for 14 consecutive live days and at least 30 production deploy attempts across at least three Instances. The 14-day live window after the change starts at cutover. Slice 1 starts directly at the production cutover under the owner's shadow waiver; other slices start after shadow mode ends. Also measure 100 Tool installs, 25 updates, and 25 removals across apt, Composer, formulae, casks, and Vite+. Extend a quiet window until the counts are met. A run is stuck beyond its existing deadline plus five minutes. Prove worker-loss and cancel cases in a disposable environment too.

Most work is done by agents. Agent wall-clock time is the sum of agent run time across threads and task groups, including runs that overlap. Record the project owner's review and coordination time separately. Both measures cover design, implementation, tests, docs, deployment, and repairs in both repos. Report shared setup separately and include it in the full projection. Agent wall-clock time is recorded and projected but has no gate.

The proposed rule for a full move combines measures 1, 2, and 7. Use observed Orbit net removal per flow or command, reported orbit-swamp growth, matched-case test cost, and agent wall-clock and owner time per unit. Report reusable Gateway infrastructure and its reuse by other slices separately in the projection too. Inventory the remaining eligible behaviors and classify their complexity before projecting. Do not multiply 321 by the Tool average without accounting for database, deploy, bootstrap, and recovery differences. Include retained machinery, new integration code, shared setup, CI setup, and both repos' CI. Publish a conservative range; use its least favorable end for the decision.

For a broad move, propose that the conservative projection removes at least 75,000 Orbit source lines and 80,000 Orbit test lines, and saves at least 15 Orbit runner-minutes and five minutes of Orbit CI wall-clock per full run. orbit-swamp growth is reported without an offset requirement. Added orbit-swamp CI must stay below removed Orbit CI. Matched-case tests must meet the 80% gate. Report projected agent wall-clock time and owner review/coordination time, and gate owner time at 60 hours total, including the trials. For a Gateway-only expansion, propose at least 8,500 Orbit source lines and 14,000 Orbit test lines removed, and a saving of one Orbit runner-minute in each full run, with the same Orbit net-removal, reported orbit-swamp growth, and 80% test-cost rules. These gates use Orbit-side savings at the middle of the study's ranges. These are approval thresholds, not forecasts.

Continue beyond the slices only if the applicable projection clears those gates, parity and reliability show no loss or unresolved unknown writes, and friction stays within its limits. If a slice fails a safety or parity gate, do not ship it. If the slices work but the projection is weak, keep the working slices and stop. Delete any replaced writer at cutover; do not retain a selectable fallback. Restoring a failed slice to Orbit requires a reviewed change that restores one owner.

### Measured source baseline

Measured on 2026-10-27 at `46afd964c4557140c9dae18ae6949eb349a46b70`. Counts are physical lines, including comments and blanks, not executable statements. They are a scoped inventory, not predicted deletions. Count each file once. Fixtures, generated contracts, migrations, shared providers, dispatch, transport, and shared deploy/rollback effects are outside these totals. Record every trial change to those files as a separate trial delta.

| Slice and scope | Production source lines | Test and test-support lines | Files |
| --- | --- | --- | --- |
| Automatic deploy policy, Gateway PHP | 1,043 | 1,039 | 8 source, 3 test |
| Automatic deploy policy, Rust agent | 0 | 0 | No policy-specific Rust files; deploy effects stay shared |
| Tools, Gateway PHP | 6,951 | 9,546 | 75 source, 35 test/support |
| Tools, Rust agent | 4,869 | 5,171 | 29 Rust files, with inline tests split out |
| Tools, combined | 11,820 | 14,717 | Same files as the two Tool rows |

All Gateway paths below are relative to `apps/gateway`. Expand the patterns at the baseline commit. The counts include retained record and constraint code; the trial earns credit only for actual deletions.

| Source manifest | Lines |
| --- | --- |
| `app/Actions/Instances/RunAutomaticDeploysAction.php` | 420 |
| `app/Domain/Instances/Deployment/AutomaticDeployHistory.php` | 143 |
| `app/Domain/Instances/Deployment/AutomaticDeploy{Alerts,Failure,Outcome,Result,State}.php` | 433 |
| `app/Console/Commands/AutoDeployCommand.php` | 47 |
| `app/Actions/Tools/*.php` | 2,380 |
| `app/Domain/Tools/*.php` | 1,168 |
| `app/Infrastructure/Tools/*.php` | 2,159 |
| `app/Data/Tools/*.php` | 240 |
| `app/Http/Requests/Tools/*.php` | 257 |
| `app/Http/Controllers/Api/{Tools,ToolInventory,ToolManagers}Controller.php` | 201 |
| `app/Models/{Tool,ToolManagerRecord}.php` | 120 |
| `app/Actions/Doctor/ToolDoctorProbe.php` | 426 |

The three automatic-deploy test files are `tests/Feature/Domain/AutoDeployTest.php` (652), `tests/Feature/Domain/DeployDevelopmentDefaultsTest.php` (226), and `tests/Feature/Api/InstanceUpdateAutoDeployTest.php` (161). These include development and retained API cases. Production pause assertions also exist in the shared `InstanceRollbackOperationTest.php`; exclude that file from the line total and include those cases in parity testing. The 563 lines in the named policy pair are also not all removable: both classes serve development defaults.

The Tool test manifest is `tests/{Feature/Infrastructure,Unit/Domain}/Tools/*.php`; `tests/Feature/Api/{Tools,ScanToolInventory,AdoptTool}Test.php`; `tests/Feature/Domain/{ToolActions,UpdateToolAction,RemoveToolAction,ToolReadActions,AdoptToolAction,ToolDoctorProbe}Test.php`; and `tests/Support/{FakeToolManager,InspectsToolsIndividually,VpStoreAgentRunner,FakeToolManagerMaterializer,ResolvedVp,ImmediateToolOperationLock,ToolManagerFakeSshExecutor}.php`. Their group totals are 3,424 infrastructure, 290 unit, 1,476 API, 3,831 domain, and 525 support lines.

The Rust manifest is `apps/agent/src/commands/{tools_apt,homebrew,homebrew_scope,composer_global,composer_scope,vp_global,vp_scope,vp_store,vp_materialize,vp_project,tool_output}.rs` and every `.rs` file under the matching module directories. Files named `tests.rs` count entirely as tests. In source files, each `#[cfg(test)]` plus its following `mod tests;` counts as two test lines. The inline test modules at the end of `vp_scope.rs` and `vp_store/downloads.rs` count entirely as tests from `#[cfg(test)]` to end of file. Everything else counts as source. This splits 10,040 physical Rust lines into 4,869 source and 5,171 test lines. The committed `apps/agent/contract/commands.json` measures 321 commands, of which 45 start with `tool.`.

Reproduce a file count with `git show 46afd964c4557140c9dae18ae6949eb349a46b70:<path> | wc -l`. Apply the manifests above for totals. Repeat the same classification after the trial. Separately count fixture and generated-file changes so moved test data cannot disappear from the cost comparison.

### Measured CI baseline

Use the ten most recent successful `CI` runs on main at measurement time. All ran on 2026-10-27, from 06:30 to 19:07 UTC. Nine were pushes; run `40477211458` was scheduled. Each contains the same ten affected successful jobs. Their source commits differ; this is a recent-main timing baseline, not ten repetitions of one commit.

| Run | Commit | Affected runner-minutes | Affected elapsed minutes |
| --- | --- | --- | --- |
| [40566304272](https://github.com/hardimpactdev/orbit/actions/runs/40566304272) | `46afd964` | 45.37 | 18.60 |
| [40562373874](https://github.com/hardimpactdev/orbit/actions/runs/40562373874) | `e747efd2` | 41.75 | 13.65 |
| [40558670199](https://github.com/hardimpactdev/orbit/actions/runs/40558670199) | `28380dc7` | 39.35 | 15.00 |
| [40552460544](https://github.com/hardimpactdev/orbit/actions/runs/40552460544) | `0dddbeb1` | 47.60 | 20.92 |
| [40551955689](https://github.com/hardimpactdev/orbit/actions/runs/40551955689) | `c00f81cd` | 45.88 | 20.30 |
| [40548327509](https://github.com/hardimpactdev/orbit/actions/runs/40548327509) | `5488091a` | 47.77 | 20.23 |
| [40535085891](https://github.com/hardimpactdev/orbit/actions/runs/40535085891) | `b9d34911` | 46.65 | 18.80 |
| [40477211458](https://github.com/hardimpactdev/orbit/actions/runs/40477211458) | `9e13f78e` | 40.77 | 15.70 |
| [40472503008](https://github.com/hardimpactdev/orbit/actions/runs/40472503008) | `9e13f78e` | 37.77 | 13.32 |
| [40461536608](https://github.com/hardimpactdev/orbit/actions/runs/40461536608) | `003733d1` | 46.05 | 18.92 |

Durations come from `completedAt - startedAt`. Calculate runner-minutes by summing job durations, without billing multipliers or rounding per job. Elapsed time spans the first affected job start to the last affected job finish, including waits between those jobs. It excludes the initial change-selection job and final workflow jobs. Do not add elapsed times of parallel jobs. Setup is included in job durations. Step durations below isolate tests and builds.

| Job | Median job seconds, n=10 | Step: median seconds, n=10 |
| --- | --- | --- |
| Gateway | 171 | Quality checks: 101 |
| Gateway shards (1) | 100 | Pest shard: 77.5 |
| Gateway shards (2) | 96 | Pest shard: 71 |
| Gateway shards (3) | 96 | Pest shard: 71 |
| Gateway shards (4) | 127.5 | Pest shard: 101 |
| Gateway shards (5) | 90.5 | Pest shard: 65.5 |
| Gateway privileged | 448.5 | Agent release build: 103.5; privileged Pest: 27 |
| Rust agent | 1,105.5 | Clippy: 22; tests: 88; delivery build: 539.5 |
| Rust agent on macOS | 409 | Delivery build: 275; command tests: 75.5; enrollment test: 1 |
| Combine agent delivery | 13.5 | Artifact verification and combination; no slice tests |

The Gateway quality job plus five shards cost a median 11.99 runner-minutes and span 2.85 minutes. This is the shared job baseline for production policy. The four agent/privileged/delivery jobs cost a median 33.32 runner-minutes and span 18.69 minutes. Tools can affect both groups. Across all ten jobs, the median per-run sum is 45.63 runner-minutes and the median span is 18.70 minutes. Aggregate medians are calculated per run, not by summing the individual job medians. These are measured job costs, not savings attributable to either slice.

Run `40566304272` exposes compressed artifact sizes of 22,481,694 bytes for `agent-delivery-linux`, 21,440,972 for `agent-delivery-macos`, and 43,922,228 for the combined `agent-delivery`. These are GitHub artifact archive sizes, not raw binary sizes. The size of each raw release binary and slice-only test timings are not available in these job summaries. No estimate is substituted. Capture raw binaries and the ten matched-case test runs before implementation; do not infer slice time from a whole shard. Live reliability, friction, and engineering effort also need the prospective windows above; this read-only baseline cannot measure them.

Reproduce the run selection and fetch each run's jobs and steps with:

```bash
gh run list -R hardimpactdev/orbit --branch main --workflow CI --status success --limit 10 --json databaseId,headSha,createdAt
gh run view 40566304272 -R hardimpactdev/orbit --json jobs,startedAt,updatedAt,headSha,url,event
gh api repos/hardimpactdev/orbit/actions/runs/40566304272/artifacts
```

The first command selects newer runs as main advances. Use the fixed IDs in the table to reproduce this baseline. Inspect `.github/workflows/ci.yml` at the measured commit for commands and runner setup. Recheck comparable CI after unrelated runner or architecture changes; do not credit this trial for their savings.

## Rejected alternatives

- **Move most agent commands now.** The estimates do not prove net savings, feature parity, or recovery after an unknown write. Two slices measure those costs first.
- **Move only Gateway orchestration and declare success.** Its estimated CI saving is small and does not test whether Rust delivery gets cheaper.
- **Replace the whole operation engine first.** Shared users and native recovery still need it. An early replacement adds cost before showing a return.
- **Keep both writers or an automatic native fallback.** A second writer makes lock, pause, and retry ownership unclear and adds code the trial aims to remove.
- **Fork Swamp or keep a custom runtime.** The accepted dependency includes upstream defaults. A fork would turn deleted Orbit machinery into another maintenance burden.

## Consequences

- Two measured slices can remain useful even if no wider move is justified.
- The trial adds Node workers and cross-repository contracts. Report their code, CI, rollout order, and repair cost. Gate code on Orbit net removal; report orbit-swamp growth without a code gate. The CI and effort gates still apply.
- A working port may fail the proposed payoff gate. Correctness and parity are required, but do not prove that a wider move is worth its cost.
- A successful trial cannot remove dispatch, transport, the operation engine, or the Rust build while another user needs them.
- If approved and built, absorb the shipped ownership rules and lasting reasons into the owning reference pages. Keep trial results with those rules and retire this ADR through the normal lifecycle.

## Affects

The trials also affect `apps/agent` and hardimpactdev/orbit-swamp. This proposal changes documentation only.

- Components: apps/docs, apps/gateway, apps/cli, apps/web, packages/php-sdk
- ADRs: limits [0209](/decisions/0209-run-long-operations-as-durable-queued-operations), [0218](/decisions/0218-take-long-commands-off-the-request-path), and [0229](/decisions/0229-let-an-orchestrator-drive-task-work-through-gateway-operations) for the two trials; supersedes none.
- Detail: [Automatic deployments](/reference/deployments#automatic-deployments), [Tools](/reference/tools), [Node commands](/reference/node-agent#commands), and [Operations](/reference/operations).
- Verify: the frozen parity cases; official-kit Deno tests against the replaced Rust/Pest cases; disposable worker-loss, cancellation, authorization, and shared-lock checks; the live windows and before/after CI comparison above. For this docs-only proposal: `composer docs-build`, `composer docs-lint`, `composer docs-nav`, `npx mint validate`, and `npx mint broken-links`.
