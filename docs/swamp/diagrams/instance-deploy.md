---
title: "Instance deploy: legacy and Swamp flows"
description: "Diagrams of development and production Instance deploy before Swamp and on main, a step mapping, and the times and failures of recent runs."
---

# Instance deploy: legacy and Swamp flows

The legacy Gateway deployed from one queued job: a dev deploy made 2 agent calls plus one per deploy step, and a production deploy about 8 plus one per step and two per restarted Process. Production deploys of hauzer took 33 to 59 s. The Swamp ports split the same work into children or steps. The Kit-era production run took 27 min, and development on main now declares 83 steps. This page is the map for the F9 rebuild of `instance.deploy`.

Sources:

- Legacy: [`d65c5995de`](https://github.com/hardimpactdev/orbit/tree/d65c5995de), the last commit before `7be1afa8e7` (#1590, 29 October) moved dev and prod deploy and rollback to Swamp. Swamp side: orbit-swamp#77.
- Swamp: [`workflow-operation-instance-deploy-direct.yaml`](https://github.com/hardimpactdev/orbit/blob/main/swamp/workflows/workflow-operation-instance-deploy-direct.yaml) on main at `d29eabbf83`. Development changed last in #2243; production in #433 and #1964.
- Runs: Gateway Operation records, deployment records and beast's worker journal.

## Legacy flow

`POST /api/v1/instances/{id}/deploy` admitted an `instance.deploy` Operation inside the request and answered `202`. A `RunOperation` job on the `operations` queue then ran `InstanceDeployOperation` (one try, 1,700 s timeout). Each Node action was one orbit-agent command over SSH. Deploy-step output streamed live on Reverb channel `private-operation.{id}`. The job kept an 8 KiB tail per step and saved row events after each step.

```mermaid
flowchart TD
  C["Client or Swamp policy: deploy request"] --> A1

  subgraph REQ["Gateway PHP, in the request"]
    A1["StartInstanceDeployAction: validate commit,<br/>plan, Operation lock_key per target"]
  end

  subgraph JOB["Gateway PHP, RunOperation queue job"]
    J1["InstanceDeployOperation: recheck pause and blocks,<br/>create deployment row, resume succeeded steps"]
    J2["GitHub App read token (RepositoryReadAccess)"]
    J3["Dev: Route converge only when projection pending"]
    J4["Prod: InstantiateProjectRuntimeDefinitions<br/>after activation (first deploy)"]
    J5["Record result, history, release"]
  end

  subgraph DEVA["orbit-agent on the app-dev Node (default Instance only)"]
    D1["instance.resolve-development-target<br/>fetch default branch, pin check"]
    D2["instance.checkout-development-default<br/>in place; refuse dirty or diverged"]
    D3["instance.run-development-step × N<br/>(vp prep before JS steps)"]
  end

  subgraph PRODA["orbit-agent on the app-prod Node"]
    P1["inspect-release, prepare-release<br/>clone into releases/…, link .env and shared paths"]
    P2["inspect-environment-sync, write-environment"]
    P3["run-deploy-step × before_activation<br/>(vp prep before JS steps)"]
    P4["activate-release<br/>web-root ACLs, atomic swap of current"]
    P5["php.converge-production-runtime,<br/>php.refresh-production-cache"]
    P6["process.check-release + process.control restart<br/>per restartable Process"]
    P7["run-deploy-step × after_activation"]
    P8["prune-releases (failure only logged)"]
  end

  A1 --> J1 --> J2
  J2 -- development --> J3 --> D1 --> D2 --> D3 --> J5
  J2 -- production --> P1 --> P2 --> P3 --> P4 --> J4 --> P5 --> P6 --> P7 --> P8 --> J5
```

| Phase | Legacy action | Actor | File at `d65c5995de` |
| --- | --- | --- | --- |
| Admission | Validate, plan, lock the target, queue the job | Gateway, request | `apps/gateway/app/Actions/Instances/StartInstanceDeployAction.php` |
| Run | Pause and block recheck, deployment row, resumable steps | Gateway, queue | `Domain/Instances/Deployment/InstanceDeployOperation.php`, `OperationDeploymentSteps.php` |
| Source (dev) | Fetch, pin check, checkout in place | agent, 2 calls | `Actions/Instances/DeployDefaultInstanceAction.php`, `RemoteDevelopmentDeployment.php`; agent `dev_deploy.rs` |
| Source (prod) | Inspect and prepare a new release | agent, 2 calls | `Actions/Instances/DeployInstanceAction.php`, `RemoteProductionDeployment.php`; agent `production.rs` |
| Environment (prod) | Inspect and write `.env` | agent, 2 calls | `SynchronizeInstanceEnvironmentAction.php`, `RemoteInstanceEnvironmentAccess.php` |
| Steps | One agent call per step; Vite+ prep before JS steps | agent, N calls | `deploy_step.rs`, `dev_deploy.rs`, `PrepareVpEnvironmentAction.php` |
| Activation (prod) | Atomic swap of `current`; install captured runtime definitions | agent + Gateway | `production.rs`, `DeployInstanceAction.php` |
| PHP (prod) | Converge the FPM runtime, refresh the cache | agent, 2 calls | `RemoteProductionPhpRuntimeManager.php` |
| Processes (prod) | Check the release, restart | agent, 2 per Process | `RestartInstanceProcessesAction.php`, `RemoteProcessRuntimeManager.php` |
| Cleanup (prod) | Prune releases, keep 3 in total, selected and previous first | agent, 1 call | `DeploymentRelease.php` |

Gateway paths are under `apps/gateway/app/`; agent paths are under `apps/agent/src/commands/`. The production deadline was the step timeouts plus 900 s, capped at 1,600 s. A failed deploy recorded its boundary and did not roll back. Rollback was a separate Operation: inspect, activate, PHP refresh and Process restart.

Legacy tests for the parity port, at `d65c5995de` under `apps/gateway/tests/Feature/`: `Domain/DeployInstanceTest.php` (27), `Api/PinnedInstanceDeployTest.php` (22), `Api/InstanceRollbackOperationTest.php` (17), `Domain/DeployDevelopmentDefaultsTest.php` (14), `Infrastructure/Instances/ProductionReleaseCommandsTest.php` (12), `Api/InstanceDeployStepsTest.php` (11), `Api/ProjectDevelopmentDeployStepsTest.php` (10), `Domain/DevelopmentDeploymentTest.php` (9) and eight smaller files. Agent side: `dev_deploy_tests.rs` (15), `production_tests.rs` (16), `production_home_tests.rs` (14) and `deploy_step.rs` (5).

Later stages: #1590 ran the same actions as Kit children; #1867 made development one native behavior step on the Node's worker; #1894 removed Kit; #433 and #1964 gave production its two-behavior shape; #2243 moved development to SSH from Node 209.

## Swamp flow on main

One workflow, `operation-instance-deploy-direct`, holds both variants. Each step guards on `intent.deployment.variant`, or, for `install-processes`, gets an empty `forEach` list, so a run executes one variant and skips the other.

| Job | Placement | Steps | Runs for |
| --- | --- | --- | --- |
| `development` | Node 209; 40 steps reach the app-dev Node over `@swamp/ssh` | 83 | development |
| `development-result` | Node 209 | 1 | development |
| `deploy` | the production Node's own worker (`orbit-node-<node_id>`); `install-processes` runs on 209 | 3 (one is a `forEach` child workflow) | production |
| `failure` | the production Node's own worker | 1 | production, after a failed `deploy` |

### Development

The Gateway validates the request, captures public intent and names vault copies for each command, then starts the run. The run never calls the Gateway except for the GitHub token: for a private repository, the `fetch` script on the Node POSTs to the token endpoint.

```mermaid
flowchart TD
  subgraph GW1["Gateway: admission (PHP)"]
    A1["StartInstanceDeployAction<br/>lock Instance, snapshot intent,<br/>refuse a pending Route projection"]
    A2["DevelopmentDeployInput<br/>intent + vault copies: one per command"]
    A1 --> A2
  end

  subgraph DEV["job development: 83 steps on 209"]
    S0["dev_assets<br/>render scripts, 1 step, 209"]
    S1["source: fetch, check, clean, checkout<br/>4 SSH + 4 check steps"]
    S2["dev_manager: child workflow<br/>operation-tool-write-209 (vp manager)"]
    S3["Vite+: vp_project, vp_resolve, vp_install, vp_verify<br/>3 SSH + 4 local steps"]
    S4["dev_app_0 .. dev_app_31<br/>32 SSH + 32 check steps,<br/>one pair per deploy step; unused pairs skip"]
    S5["dev_inspect<br/>1 SSH + 1 check step"]
    S0 --> S1 --> S2 --> S3 --> S4 --> S5
  end

  R["job development-result: result<br/>1 step, runs always"]
  G["Gateway: terminal projection<br/>deployment record, history"]
  T["Gateway: POST /direct-operations/{id}/github-token"]

  A2 -- "StartSwampRun: intent + vault key names" --> S0
  S1 -. "fetch script on the Node" .-> T
  S5 --> R --> G
```

Guards and skips:

- Every development step skips for a production run.
- `dev_manager` and the four Vite+ steps skip when `intent.deployment.needs_vp` is false.
- `dev_app_N` runs only for a Project with more than N deploy steps.
- `dev_app_N` also needs checkout, Vite+ and every earlier app step to succeed.
- `dev_inspect` runs only when every earlier action succeeded.
- `dev_clean` and `dev_checkout` run under `flock --nonblock` on the checkout; a held lock exits 73. App steps take no lock.
- Source and Vite+ SSH steps time out after 600 s; an app step after its command timeout plus 10 s. Each `*_check` step is a local behavior that decodes the SSH output.

### Production

The `deploy` job runs on the production Node's own worker, not on 209. Two TypeScript behaviors do the work. Each runs several stages locally and keeps a checkpoint resource. Both read intent and private material from the Gateway and project progress back over HTTP.

```mermaid
flowchart TD
  subgraph GW1["Gateway: admission (PHP)"]
    A1["StartInstanceDeployAction<br/>lock Instance, snapshot intent, capture<br/>runtime definitions, environment, PHP input"]
  end

  subgraph DEP["job deploy: production Node worker"]
    P1["prepare-production: 1 behavior step<br/>stages: source_preparation, environment_sync,<br/>vp_environment, before_activation, activation, php_refresh"]
    P2["install-processes: forEach child workflow<br/>operation-process-create, one per Process member (on 209)"]
    P3["finish-production: 1 behavior step<br/>stages: runtime_definitions, process_restart,<br/>after_activation, cleanup, verification"]
    P1 --> P2 --> P3
  end

  F["job failure: report<br/>settles an inactive checkpoint"]
  G["Gateway: deployment record, history, release"]

  A1 -- "StartSwampRun" --> P1
  P1 -. "read intent, private material,<br/>project progress (HTTP)" .-> G
  P3 -. "project result (HTTP)" .-> G
  DEP -- "failed" --> F -. "project failure" .-> G
```

Each stage checks for cancellation before it starts and saves its checkpoint after it ends. The checkpoint lets a restarted run continue without repeating an effect. `activation` swaps `current` to the new release. `cleanup` prunes old releases.

## Mapping

Legacy call to Swamp steps on main. Development counts declared steps in jobs `development` and `development-result` (84); production counts steps in job `deploy`.

| Phase | Legacy calls | Swamp on main | Steps | Fan-out |
| --- | --- | --- | --- | --- |
| Dev: admission and token | Gateway + token in the job | Gateway admission; `dev_assets`; token POST from the `fetch` script | 1 | |
| Dev: source | 2 agent calls | `dev_fetch`, `dev_check`, `dev_clean`, `dev_checkout`, each with a `*_check` step | 8 | **2 → 8** |
| Dev: Vite+ | 1 to 4 calls per JS step | `dev_manager` child + `vp_project`, `vp_resolve`, `vp_install`, `vp_verify` with checks | 8 | once per deploy |
| Dev: deploy steps | N agent calls | `dev_app_0..31`, each with a check step | 64 declared, 2N run | **N → 2N** |
| Dev: finish | Gateway | `dev_inspect` + check, job `development-result` | 3 | new inspection |
| Prod: source, environment, Vite+, before steps, activation, PHP | about 7 + N_before calls | `prepare-production`: one behavior step | 1 | **many → 1** |
| Prod: Process definitions | Gateway, after activation | `install-processes`: one `operation-process-create` child per member, on 209 | P | **1 → P children** |
| Prod: Schedule definitions, restart, after steps, cleanup, verification | 2P + N_after + 1 calls | `finish-production`: one behavior step | 1 | **many → 1** |
| Prod: failure | job records the boundary | job `failure`: `report` | 1 | |

The two variants moved in opposite directions. Development went from 2 + N agent calls to 12 + 2N run steps (20 + 2N with Vite+), counting the result step, each paying placement, SSH and history overhead. Production went from separate agent calls to two large TypeScript behaviors on the Node's worker. Those behaviors call the Gateway over HTTP for intent, private material and progress, which the porting guide marks as an anti-pattern.

## Times and failures

No deploy has run on either current shape yet. The development port (#2243) went live at 07:37Z on 1 November. Its first admission, operation 6629, failed before any step ran with `swamp.vault_sync_unavailable`. No production deploy has run since #433 and #1964 replaced the Kit-era children with two behaviors. The numbers below come from the shapes just before each port, and from the legacy Gateway.

```mermaid
flowchart LR
  subgraph DEVT["Development, Instance 315 (orbit default, 7 deploy steps)"]
    D1["Before #2243: 1 behavior step<br/>on beast's worker<br/>47 runs: median 63 s, p95 150 s<br/>op 6567: step 57.0 s of 57 s"]
    D2["#2243 on main: 83 steps on 209<br/>no completed run yet<br/>❌ op 6629: vault sync, before any step"]
    D1 --> D2
  end
  subgraph PRODT["Production, Instance 71 (hauzer, 5 deploy steps)"]
    P1["Legacy Gateway<br/>runs on 21 to 28 Oct: 33 to 59 s"]
    P2["Kit-era Swamp children<br/>❌ op 3338: vp_environment deadline, 26 min 48 s<br/>op 3501: succeeded, 27 min 17 s, 3 attempts"]
    P3["Main: 2 behaviors + Process children<br/>no run yet"]
    P1 --> P2 --> P3
  end

  classDef fail fill:#fdd,stroke:#c00;
  class D2,P2 fail;
```

| Run | Shape | Result | Time |
| --- | --- | --- | --- |
| 47 development runs on Instance 315, 31 Oct 20:55Z to 1 Nov 05:39Z | One `@nckrtl/instance-deploy-direct.run` step on beast's worker | succeeded | median 63 s, p95 150 s, max 230 s |
| 6567 | same | succeeded | 57 s; the worker journal shows the single step took 57.0 s |
| 6291 | same | `deployment.interrupted` at `native_execution` | 51 s |
| 6569 | same | `operation.native_failed`, interrupted | 2 min 48 s |
| 6629 | #2243, 209 SSH | `swamp.vault_sync_unavailable`, before any step | 2 s |
| Production on Instance 71, 21 to 28 Oct | legacy Gateway queue job | succeeded | 33 to 59 s each |
| 3338 | Kit-era Swamp children | `deployment.deadline_exceeded` at `vp_environment` | 26 min 48 s |
| 3501 | same | succeeded after 3 attempts | 27 min 17 s |

The 30 October incident log names the causes of the production stalls:

- A Vite+ child stalled while Services ran out of inodes (`ENOSPC`).
- `install_instance_definitions` behavior names were 66 to 67 characters, over Swamp's 64-character limit (fixed in orbit-swamp#144).
- Every five minutes, `fleet.peer_dns` took Node locks, so deploy children returned `operation.target_busy`.
- PHP runtime children stalled during the nckrtl cutover.

Each split of one legacy call into children or steps added a queue wait and a failure point. The single-step development shape ran in about a minute. The 83-step shape will pay the per-step overhead measured for `instance.create` (20 to 60 s each) unless the F9 rebuild folds it into one step per phase.

