---
title: "Old paths"
description: "Code that still runs but that a stage of the command development pipeline replaces. Check this page before you build on it."
covers:
  - apps/gateway/app/Infrastructure/TaskVms/RecipeImageBuilds.php
---

# Old paths

Check this page before you build on something. Each row is code that still runs, was not removed, and is replaced by a stage of the [command development pipeline](/reference/command-development-pipeline). Keep it and do not extend it. When its stage lands, delete it with its tests and remove the row. Code that is replaced and has no live caller is deleted, not listed here.

[Off in the live fleet](#off-in-the-live-fleet) lists code that stays but does not run today.

| Item | Replaced by | Removed at |
| --- | --- | --- |
| The `instance-create-stage-`, `instance-create-redact-token-` and `instance-create-redact-model-` key prefixes in the create's declared copies, the Gateway grants, the create workflow's input pattern and `ContainerDestroyExecutionCopies` | The one-line launch copy `instance-create-launch-<operation>`; the bare copies are not written | When no Instance created on release 2079 or 2084 is left (the destroy deletes their copies) |
| The `<name>-topology-seed` clone name in `TaskVmSlotRuns::seedClone` for VMs without a place number | Every start's VM has a place and a `<name>-seed` clone | When no VM of the old pool (`tvm-610`, `tvm-663`) is live |
| The merge-base replay pieces of the [task VM proof run](#the-task-vm-proof-run) | [`proof:run` in copies](/cli/proof/run#the-run), once it replays flagged cases | When the replay is ported to copies |
| `GatewayRuntimeFiles::RetiredSettings`, the four `ORBIT_TASK_VMS_UPCLOUD_*` keys and `ORBIT_SWAMP_DIRECT_WRITERS` | Nothing: the `gateway` role's convergence removes them from `shared/gateway.env` | When no Gateway has them |
| The `certificate`, `pi_port`, `control_ip` and `gateway_ip` of a task start identity: the CA files and the three `identity.env` lines (`ORBIT_PI_PORT`, `ORBIT_CONTROL_IP`, `ORBIT_GATEWAY_IP`) a task start still writes | The boot phase of the base converge (`boot.trust`) bakes the CA and the firewall into the base; the `orbit-identity` unit mounts its own share | When both hosts' bases are refreshed after the boot phase merged |
| [`instance:migrate`](/cli/instance/migrate), its kind `instance.migrate`, the workflow `operation-instance-migrate` and the playbook `instance-migrate.yml`; and checkout Instances on an `app-dev` Node, with their PHP-FPM pools | Every new Instance is a [container](/reference/development-containers); the command moves each checkout Instance into one | When the last checkout Instance has moved, with the switch of every `app-dev` Node ([PR 16](/solutions/development-container-runtime#pr-16-switch-every-app-dev-node)) |
| `bin/e2e-topology`, its harness `apps/e2e` and the per-issue topology it composes, with its virtiofs share of the worktree | The nested fleet ([stage 2](/reference/command-development-pipeline#2-development-environment)) | When its harness is removed: no subtask can [declare a topology](/reference/compute-drivers#declare-a-topology) |

The admission of `task:start` mints no GitHub token. The provision clone, the setup steps, a package install and the publish step mint their own when they run ([GitHub App](/reference/github-app#how-orbit-publishes-a-task-pull-request)). Admission holds no token for them.

`apps/agent/**` is being stripped to an observer: commands that nothing calls leave in small pull requests, and the rest leave with their Swamp ports.

The capacity model replaces the shared CI reserve, `task_max_vms`, resource-unit admission and the `task:park` command. Hosts expose separate task and CI place counts. Publication deletes task VMs; later work claims a fresh VM. See [capacity](/reference/command-development-pipeline#capacity-ideal-state).

## The task VM proof run

The task VM proof run is removed: the workflow `operation-proof-run`, the task VM first restore, token re-mint and last restore of `proof-fleet-verify`, and the `proof.command` arm of `TaskGitHubTokens`. These parts stay until the merge-base replay is ported to copies:

- the steps of `proof-fleet-verify` that replay flagged cases on the merge base (`Restore the head checkout`, the jobs `can-fail` and `can-fail-batches`);
- the batch restore of `proof-fleet-verify-batch`;
- the workflows `proof-fleet-restore`, `task-nested-apply` and `task-nested-ready`.

## The Pi task loop

The Pi task loop ran on task VMs with the Pi agent server. It is removed with the VMs; a function that is wanted again comes back as its own change on task copies. Its Gateway side is removed in parts.

`task:prep`, `task:prep-fix`, the build and the review round (`tasks.prep`, `tasks.prep_fix`, `tasks.build`, `tasks.review_round`) are removed. Their routes and MCP tools are gone, and the [gate check](/reference/tasks#gate-check) starts none of them. The rows of `task_review_rounds` stay, and nothing reads them.

The maintenance run, the package install, the repository script, the idle save and the Pi turn (`tasks.maintenance`, `tasks.package`, `tasks.script`, `tasks.idle_save`, `tasks.turn`) are removed too. The routes `package` and `script` and their MCP tools are gone. `task:turn` runs only the [copy turn](/cli/task/turn#in-a-task-copy); its role `consult` and its `--model` option are gone. The Gateway registers no `pi` agent driver. Project maintenance still admits its groups and their copies, but no maintenance run follows. The columns `idle_saved_commit` and `idle_saved_at` stay, and nothing reads them.

Old Operations of all these kinds stay as history, as for every [kind that left the registry](/reference/operations#kinds-that-left-the-registry).

These parts are still in the code:

- the task VM resume and slot release, and the slot pool;
- the Pi agent server `apps/pi-server`.

The Swamp workflows of the removed kinds stay one release longer, because a rollback by one release still names them. Nothing starts them. The vault patterns `^task-vm-pi-token-[1-9][0-9]*$`, `^pr-comment-write-[a-f0-9-]{36}$` and `^task-provision-deps-(composer|npm|token)-[1-9][0-9]*$` stay declared on `tasks.provision` until the grants release after the Pi loop.

## Off in the live fleet

This section describes the live Orbit fleet, not the code. On 13 November 2026 these parts stay in the code but do not run:

| Part | Comes back as |
| --- | --- |
| Task VMs (0 task places on every host) and their image refresh (held on every host, and nothing starts it) | Tasks that start from the Orbit container's snapshot |
| Automatic deploys of development Instances | One deploy per new commit, for container defaults only |
| The nightly fleet reboot (`ORBIT_FLEET_REBOOT=false`) | The same pass, after one safe test |
| Automatic Gateway releases (disabled; a manual deploy also pauses them) | The same tick, when the container path is stable |
