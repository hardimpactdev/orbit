---
title: "Old paths"
description: "Code that still runs but that a stage of the command development pipeline replaces. Check this page before you build on it."
covers:
  - apps/gateway/app/Infrastructure/TaskVms/RecipeImageBuilds.php
  - apps/gateway/database/migrations/2027_03_03_000000_drop_task_vm_tables.php
---

# Old paths

Check this page before you build on something. Each row is code that still runs, was not removed, and is replaced by a stage of the [command development pipeline](/reference/command-development-pipeline). Keep it and do not extend it. When its stage lands, delete it with its tests and remove the row. Code that is replaced and has no live caller is deleted, not listed here.

[Off in the live fleet](#off-in-the-live-fleet) lists code that stays but does not run today.

| Item | Replaced by | Removed at |
| --- | --- | --- |
| The `instance-create-stage-`, `instance-create-redact-token-` and `instance-create-redact-model-` key prefixes in the create's declared copies, the Gateway grants, the create workflow's input pattern and `ContainerDestroyExecutionCopies` | The one-line launch copy `instance-create-launch-<operation>`; the bare copies are not written | When no Instance created on release 2079 or 2084 is left (the destroy deletes their copies) |
| The merge-base replay pieces of the [task VM proof run](#the-task-vm-proof-run) | [`proof:run` in copies](/cli/proof/run#the-run), once it replays flagged cases | When the replay is ported to copies |
| `GatewayRuntimeFiles::RetiredSettings`, every `ORBIT_TASK_VMS_*` key, `ORBIT_PI_PORT`, `ORBIT_SWAMP_PROOF_CLUB_API_KEY` and `ORBIT_SWAMP_DIRECT_WRITERS` | Nothing: the `gateway` role's convergence removes them from `shared/gateway.env` | When no Gateway has them |
| `swamp/ansible/tasks/node-converge-hub-filter-absent.yml`, which removes the [task VM hub filter](#the-task-vm-lifecycle) | Nothing: the hub converge removes the filter | When no vpn Node has the filter |
| [`instance:migrate`](/cli/instance/migrate), its kind `instance.migrate`, the workflow `operation-instance-migrate` and the playbook `instance-migrate.yml`; and checkout Instances on an `app-dev` Node, with their PHP-FPM pools | Every new Instance is a [container](/reference/development-containers); the command moves each checkout Instance into one | When the last checkout Instance has moved, with the switch of every `app-dev` Node ([PR 16](/solutions/development-container-runtime#pr-16-switch-every-app-dev-node)) |

The admission of `task:start` mints no GitHub token. The publish step mints its own when it runs ([GitHub App](/reference/github-app#how-orbit-publishes-a-task-pull-request)). Admission holds no token for them.

`apps/agent/**` is being stripped to an observer: commands that nothing calls leave in small pull requests, and the rest leave with their Swamp ports.

The capacity model replaced the shared CI reserve, `task_max_vms`, resource-unit admission and the `task:park` command. Hosts still expose separate task and CI place counts, which nothing takes. See [capacity](/reference/command-development-pipeline#capacity-ideal-state).

## The task VM proof run

The task VM proof run is removed: the workflow `operation-proof-run`, the task VM first restore, token re-mint and last restore of `proof-fleet-verify`, and the `proof.command` arm of `TaskGitHubTokens`. These parts stay until the merge-base replay is ported to copies:

- the steps of `proof-fleet-verify` that replay flagged cases on the merge base (`Restore the head checkout`, the jobs `can-fail` and `can-fail-batches`);
- the batch restore of `proof-fleet-verify-batch`;
- the workflows `proof-fleet-restore`, `task-nested-apply` and `task-nested-ready`.

## The Pi task loop

The Pi task loop ran on task VMs with the Pi agent server. It is removed with the VMs; a function that is wanted again comes back as its own change on task copies. Its Gateway side is removed in parts.

`task:prep`, `task:prep-fix`, the build and the review round (`tasks.prep`, `tasks.prep_fix`, `tasks.build`, `tasks.review_round`) are removed. Their routes and MCP tools are gone, and the [gate check](/reference/tasks#gate-check) starts none of them. The rows of `task_review_rounds` stay, and nothing reads them.

The maintenance run, the package install, the repository script, the idle save and the Pi turn (`tasks.maintenance`, `tasks.package`, `tasks.script`, `tasks.idle_save`, `tasks.turn`) are removed too. The routes `package` and `script` and their MCP tools are gone. `task:turn` runs only the [copy turn](/cli/task/turn#in-a-task-copy); its role `consult` and its `--model` option are gone. The Gateway registers no `pi` agent driver. Project maintenance still admits its groups and their copies, but no maintenance run follows. The columns `idle_saved_commit` and `idle_saved_at` are dropped with the [task VM settings](#task-vm-settings).

### The task VM lifecycle

The lifecycle that only the Pi loop used is removed as well:

- the resume and the slot release of a task VM (`task_vm.resume`, `task_vm.slot_release`);
- the slot pool, its claim and the Artisan command `task-vms:resume`;
- the task VM branches of `task:start`, `task:sync`, `task:check`, `task:publish`, `task:cancel` and `task:complete`.

A task without a [task copy](/reference/tasks#task-copy) has no workspace that Orbit can use or remove. Its sync, check and publish answer `tasks.no_workspace`. Its cancel ends it at admission and starts no run. When it still holds a task VM workspace, its complete asks for removal assistance.

`tasks.provision` stays registered as the request kind of `task:start`, which scopes the route's `Idempotency-Key`. No Operation of it starts. Its registry entry names `operation-instance-create`, the workflow a start runs through its task copy, and declares no vault pattern. The rows of `task_vms` stay, and nothing changes them.

The task VM hub filter that the removed `task_vm.hub_converge` installed on the vpn Node goes with the next converge that applies the network on the hub. That is a full converge of the vpn Node, or of any Node that uses it as its hub. It removes the unit `orbit-task-vms-hub.service`, its unit file, `/etc/orbit/task-vms/hub.nft`, the nft table `inet orbit_task_vms`, and every UFW rule whose comment starts with `orbit:task-vm-`. [Converge the hub](/reference/wireguard-endpoints#converge-the-hub) has the steps.

The task VM workflows that #3849 kept are deleted: the start `operation-task-provision` with its children `task-vm-start` and `task-vm-hub-peer`, and the place release `operation-task-vm-slot-release` with its children `task-vm-slot-phase` and `task-vm-hub-peer-remove`. The playbook `swamp/ansible/task-vm-start.yml`, which only the start ran, goes with them. `operation-task-cancel` calls no release any more; a cancel starts no run.

The read-only task places view is removed too: the `capacity` of `task:status`, the `capacity_wait` of an Operation and of a task, the `input.capacity_request` summary and the places table of the CLI. Nothing admitted a waiting task VM start any more. A CLI from before this release refuses the `task:status` reply until it is updated. The column `capacity_wait_reason` stays. The 22 `task_vm.*` error codes that left the [error table](/reference/compute-drivers#errors), and the doctor codes `node.task_vm_orphaned`, `node.task_vm_missing`, `node.task_vm_inspection_failed` and `node.task_vm_stray_*`, which nothing produced, are deleted.

### The Pi agent server

The Pi agent server `apps/pi-server` is removed with its CI check, its Dependabot entry and its page. It ran Pi coding-agent sessions on a task VM, as the VM's one user `orbit`, with a token per VM. The decisions 0167 (resume a Pi turn after a server restart), 0168 (offload large Pi tool output) and 0193 (run task agents as a dedicated user) described it; their old paths lead here.

No Node enrollment, certificate signing, VPN or worker material and no Process install accepts a `tasks.provision` Operation any more. A Node joins the fleet only through `node.add`.

The task VM start installs no Pi server any more. Its fetch and checksum steps, the `pi_path` playbook input, the Ansible task file `task-vm-pi.yml`, the Pi line of the role's end check and the Pi unit of the VM identity are deleted.

The machine recipe pins no Pi build: the tool `pi`, its entry in the task VM profile `base` and its catalog line are deleted, so the recipe identity changed. `ORBIT_PI_PORT` and the `pi` fields of the task VM settings are removed with those settings ([Task VM settings](#task-vm-settings)), so port 3774 is free for a Node service.

### Task VM settings

The task VM settings and the Node setting `task_vms` are removed:

- `config/task_vms.php` and `TaskVmSettings`, with the Pi fields, the Project size lists, the Caddy guard range and the Swamp hostname.
- Every `ORBIT_TASK_VMS_*` key, `ORBIT_PI_PORT` (`orbit.pi.port`) and `ORBIT_SWAMP_PROOF_CLUB_API_KEY` are in `GatewayRuntimeFiles::RetiredSettings`, so the `gateway` role's convergence removes them from `shared/gateway.env`.
- Port 3774 is free: [`instance:node-service:attach`](/cli/instance/node-service-attach) accepts it.
- The Node setting `task_vms` with its `containers` member, and the field `image_refresh_hold`. The API refuses either member as unknown.
- `node:update` has no `--task-vms` or `--image-refresh-hold` option. Node reads and the SDK return neither field.

The Node work that served them goes too:

- The firewall catalog's task bridge input rules (`orbit:task-vms-*`, `orbit:task-containers-*`) and the VPN Node's task VM hub rules.
- The route rules on the VPN Node whose comment starts with `orbit:task-vm-`. Its next converge deletes them; the Gateway names none of them.
- The Caddy guard: every route block of every Node Caddyfile loses its `@orbit_task_vms remote_ip` matcher and its `abort @orbit_task_vms` at the Node's next build.
- The task VM term of a Node's subordinate-ID demand.
- The `arc` flag of the Instance host intent. Every Node's Instance side now keeps the ZFS ARC within its budget.
- The task VM deny rule on an Instance container's T3 port.
- The `Withdraw task host input` step of the `node:update` run. Node removal still withdraws those rules by comment.

Two later items from the Pi loop removal go as well.

The Ansible builder has no task VM path for the kind `tasks.provision` and reads no `ORBIT_INTENT_KEY`, because no run sends them. It keeps `task_vm.nested_ready`, which the proof fleet restore runs.

`tasks.provision`, `tasks.cancel` and `tasks.maintenance_finish` hold no Route Nodes per step. No Operation of the first starts. `operation-task-cancel`, the run of the other two, publishes no Route. `instance.register` and the Cluster Router writes keep their step holds.

The WireGuard range `10.44.0.128/25` stays reserved. The address allocator never hands an address of it to a Node. The guard of the ai-router admits it as before.

A later release drops what nothing read any more. Its migration `2027_03_01_000000_drop_vm_era_columns_and_task_vms_setting` removes the key `task_vms` from every Node's stored `settings` and drops the columns `nodes.image_refresh_hold`, `tasks.topology`, `tasks.idle_saved_commit` and `tasks.idle_saved_at`. Its rollback adds the four columns back empty; it cannot bring the settings key back, so the release takes a backup first. With the stored value go its three readers:

- The Instance bridge allocation keeps clear only of each Node's Instance and CI bridges, not of a stored task bridge.
- The agent watch list sends `egress: null` for every Node, so no agent reads a task bridge's log for the egress fact.
- The Gateway refuses every `egress` fact with `agent.fact_not_expected`. The recorder that added its rows to a task group is deleted.
- The rows already in `task_group_egress` stay readable through `task:egress`.
- The fleet reboot intent of a Node has no `task_project`, and the pass has no `task_hosts`. Swamp drains no task guests: the step `Drain the task guests` and its task files `fleet-reboot-drain-tasks.yml` and `fleet-reboot-park.yml` are deleted.

The busy check of the fleet reboot, `FleetReboot::busy`, drops the Operation kind pattern `task_vm.%`, because no `task_vm.*` kind starts. No admission calls the check any more. It goes with `rebootingTaskHosts` and `refuseTaskWork` ([the task VM readers](#the-task-vm-readers)). `capacity_wait_reason` stays, unread by the API. A later release dropped [the task VM tables](#the-task-vm-tables).

### The task VM readers

No task VM is created any more. When `task_vms` had no live row left, this release removed the Gateway code that read `task_vms`, `task_vm_image_builds` or `task_vm_image_recipe` only to find a task VM, which none of them found. The path that ran when no VM existed is now the only path. Instances, Projects and tasks lose these guards:

- The Instance guards of create, clone and transfer.
- The Instance save rule `task_vm.foreign_instance` and the access grant rule `task_vm.access_refused`.
- The Conn check `native_create` with its code `instance.task_vm_node`.
- The Project removal refusal `project.task_vm_not_destroyed`.
- The task VM preflight of `project:update`, in the action and in its source preflight.
- The task VM term of `task:cancel` and of the final review.

Nodes and the fleet lose these:

- The Node removal refusal `node.has_task_vms` and the task VM guest check of its admission.
- The `task_vm_id` checks of the Node removal records. The snapshot keeps `task_vm_id` as `null`, and the intent keeps `task_vm_guest` as `false`.
- The task VM skips of enrollment, the Instance host intent and the metrics exporter.
- The fleet rollout reason `sandbox`, and the task VM filters of the fleet reboot members and the Swamp worker known hosts.
- The fleet reboot's `busy` check and its task host refusal `tasks.host_rebooting`.
- The `parked` update of the fleet reboot projection, and Doctor's `node.task_vm_parked`.

The code that served only those readers goes too:

- The SSH credential guard `TaskVmCredentialGuard`. The `incus` transport kept its refusal `task_vm.git_credential_refused` until [the guest agent chain](#the-guest-agent-chain) went.
- The relations `Node::liveTaskVm`, `Node::taskVms` and `Task::latestVm`.
- `TaskVmStepClock`, `TaskVmFailedException` and the check runner's task VM setup branches.
- The slot lookup and slot update of `TaskProvisionProjection`.

The task group field `vm_power` stays and is always `null` ([VM power](/reference/compute-drivers#vm-power)). No other API, agent or Swamp input field changes.

The refusal `task_vm.parked` is gone.

The SSH task workspace path is removed too (S10c). Since the task VM readers went, its guard refused every command, so none reached SSH. These go:

- The guard `TaskVmWorkspaceGuard`, its refusal `task_vm.workspace_mismatch`, and `TaskWorkspaceExecutor`.
- The `Workspace ownership refused:` prefix of a removal failure.

The check runner goes with these:

- The check runner `RemoteTaskCheckRunner` and the interface `TaskCheckRunner`.
- The scripts the runner installed: `tasks/check`, `tasks/composer`, `tasks/metadata` and `tasks/unit-state.py`.
- The composer credential the runner passed on file descriptor 3, `orbit_setup_composer_auth`.
- The runner's helpers `TaskUnitProgram`, `TaskWorkspaceMetadata`, `TaskCheckProcess`, `TaskCheckReading` and `TaskWorkspaceSnapshot`.
- The check cancel of a subtask stop. A stop still refuses a running check that has no process identity. Every check that a Swamp workflow runs has none.

The workspace readers go too:

- The workspace diff readers `RemoteTaskWorkspaceDiffReader` and `AgentViewTaskWorkspaceDiffReader` and the interface `TaskWorkspaceDiffReader`, which nothing read.
- The workspace state reader `RemoteTaskWorkspaceStateReader` and the interface `TaskWorkspaceStateReader`.
- The evidence writers `TaskCheckEvidence::recordSubtaskStart` and `recordReading`. Nothing has called them since checks moved to Swamp. The check's projection records the subtask start and the result.
- The workspace file read of the deliverable paths. Deliverable path validation reads the origin, with or without a task copy.

What stays: a task copy is removed through its `instance.destroy`, and any other workspace a group owns is refused with `operation.kind_unavailable` (503) and stays.

`ProblemRecovery` resolves an open `recipe-image:` alert as `resource_gone` and reads neither `task_vm_image_builds` nor `task_vm_image_recipe`. The answers `verified_image_build` and `recipe_generation_superseded` are gone.

### The task VM tables

The release after the readers drops what was left. Its migration `2027_03_03_000000_drop_task_vm_tables` drops the tables `task_vms`, `task_vm_image_builds` and `task_vm_image_recipe`. No other table has a foreign key to them. Its rollback creates the three tables again, empty, with the columns, defaults, indexes and foreign keys they had. It cannot bring the rows back: the Gateway SQLite snapshot that the release takes before it migrates holds them.

With the tables go:

- The models `TaskVm` and `TaskVmImageBuild`, and the enums `TaskVmState`, `TaskVmImageBuildState`, `TaskVmImageBuildStep`, `TaskVmSize`, `TaskVmProviderKind`, `TaskVmPublishState` and `TaskVmRuntime`, with `TaskVmResources`.
- The cleanup deletes. Node removal deletes no `task_vms` or `task_vm_image_builds` rows, and Project removal deletes no `task_vms` rows.
- The task wait `task_vm`, which nothing produced or read, and the unused class `App\Infrastructure\TaskVms\TaskVmRuntime`.
- The row error helpers of `TaskVmException`. The class went with [the guest agent chain](#the-guest-agent-chain).

`TaskVmError` loses every case that nothing produces any more: `task_vm.invalid_config`, `task_vm.unknown_host`, `task_vm.host_configured`, `task_vm.fleet_unavailable`, `task_vm.setup_failed`, `task_vm.job_failed`, `task_vm.vm_missing`, `task_vm.not_settled` and `task_vm.awaiting_host_sweep`. Its last case, `task_vm.git_credential_refused`, went with [the guest agent chain](#the-guest-agent-chain). `task_vm.workspace_mismatch` went with the SSH task workspace path ([the task VM readers](#the-task-vm-readers)). The Ansible host preparation still fails with its own `task_vm.setup_failed`, and the frozen agent contract keeps `task_vm.vm_missing`.

The contract does not change. The task group field `vm_power` stays and is always `null`. The Node removal input keeps `task_vm_id` as `null` and `task_vm_guest` as `false`, and the Operation inputs of a task copy end and of a cancel keep `task_vm_id: null`. `tasks.provision` stays registered. No other API, agent or Swamp input field changes.

### The guest agent chain

S10d removed the last VM-era code that nothing called. Nothing built an agent guest, so the guest runner, its `incus` transport and the error that transport raised had no caller. These go:

- `AgentGuest`, the interface `GuestAgentCommandRunner` and its container binding, and `NativeAgentCommandRunner::runGuest()` and `installGuest()`.
- `AgentTransports::guest()` and `IncusAgentTransport`, with its refusal `task_vm.git_credential_refused`.
- The enum `TaskVmError`, which held only that refusal, and `TaskVmException`, which only the transport threw.
- The deliverable evidence verifier: `TaskDeliverableVerifier::failures()` and the class `TaskDeliverableEvidence`. Nothing has called them since checks moved to Swamp.

`AgentDelivery`, `SshAgentTransport` and `NativeAgentCommandRunner` take a Node only. What stays: the Node agent transports (local, SSH, a workspace, and the connection of a Node that is being admitted), `TaskDeliverableVerifier::matches()` and `unconfirmed()`, `TaskCheckOutputBuilder`, and `TaskVmAptCache`, `TaskVmModelRoute` and `TaskVmNestedFleet`. `GitReadScript` still marks its input as one that carries a credential. No API, agent or Swamp field changes.

### What stays

Old Operations of all these kinds stay as history, as for every [kind that left the registry](/reference/operations#kinds-that-left-the-registry).

The Swamp workflows that only the Pi loop ran are deleted. They are the Pi turn, the script, the package install, the idle save, the maintenance run, the prep, the prep fix, the build, the review round and the VM resume. Their child workflows, extensions, playbook and tests go with them. The grants release after the Pi loop removed the five vault patterns that nothing writes from the declarations and from `swamp/config/gateway-workflow-grants.json`: `^(swamp-proof-club-api-key|task-vm-slot-(wireguard|ssh-host|agent)-[1-9][0-9]*)$`, `^task-vm-pi-(claim|token)-[1-9][0-9]*$`, `^task-vm-pi-token-[1-9][0-9]*$`, `^pr-comment-write-[a-f0-9-]{36}$` and `^task-provision-deps-(composer|npm|token)-[1-9][0-9]*$`. `^nested-fleet-club-api-key$` stays.

## Off in the live fleet

This section describes the live Orbit fleet, not the code. On 13 November 2026 these parts stay in the code but do not run:

| Part | Comes back as |
| --- | --- |
| Task VMs (0 task places on every host) and their image refresh (held on every host, and nothing starts it) | Tasks that start from the Orbit container's snapshot |
| Automatic deploys of development checkouts, and the development part of the shared `automatic-production-deploy` producer | [One deploy per new commit, for container defaults only](/reference/deployments#automatic-development-defaults): built, and on once the release that ships it is live |
| The nightly fleet reboot (`ORBIT_FLEET_REBOOT=false`) | The same pass, after one safe test |
| Automatic Gateway releases (disabled; a manual deploy also pauses them) | The same tick, when the container path is stable |
