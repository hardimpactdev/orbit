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
| The merge-base replay pieces of the [task VM proof run](#the-task-vm-proof-run) | [`proof:run` in copies](/cli/proof/run#the-run), once it replays flagged cases | When the replay is ported to copies |
| `GatewayRuntimeFiles::RetiredSettings`, the four `ORBIT_TASK_VMS_UPCLOUD_*` keys and `ORBIT_SWAMP_DIRECT_WRITERS` | Nothing: the `gateway` role's convergence removes them from `shared/gateway.env` | When no Gateway has them |
| [`instance:migrate`](/cli/instance/migrate), its kind `instance.migrate`, the workflow `operation-instance-migrate` and the playbook `instance-migrate.yml`; and checkout Instances on an `app-dev` Node, with their PHP-FPM pools | Every new Instance is a [container](/reference/development-containers); the command moves each checkout Instance into one | When the last checkout Instance has moved, with the switch of every `app-dev` Node ([PR 16](/solutions/development-container-runtime#pr-16-switch-every-app-dev-node)) |
| `bin/e2e-topology`, its harness `apps/e2e` and the per-issue topology it composes, with its virtiofs share of the worktree | The nested fleet ([stage 2](/reference/command-development-pipeline#2-development-environment)) | When its harness is removed: no subtask can [declare a topology](/reference/compute-drivers#declare-a-topology) |

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

The maintenance run, the package install, the repository script, the idle save and the Pi turn (`tasks.maintenance`, `tasks.package`, `tasks.script`, `tasks.idle_save`, `tasks.turn`) are removed too. The routes `package` and `script` and their MCP tools are gone. `task:turn` runs only the [copy turn](/cli/task/turn#in-a-task-copy); its role `consult` and its `--model` option are gone. The Gateway registers no `pi` agent driver. Project maintenance still admits its groups and their copies, but no maintenance run follows. The columns `idle_saved_commit` and `idle_saved_at` stay, and nothing reads them.

### The task VM lifecycle

The lifecycle that only the Pi loop used is removed as well:

- the resume and the slot release of a task VM (`task_vm.resume`, `task_vm.slot_release`);
- the slot pool, its claim and the Artisan command `task-vms:resume`;
- the task VM branches of `task:start`, `task:sync`, `task:check`, `task:publish`, `task:cancel` and `task:complete`.

A task without a [task copy](/reference/tasks#task-copy) has no workspace that Orbit can use or remove. Its sync, check and publish answer `tasks.no_workspace`. Its cancel ends it at admission and starts no run. When it still holds a task VM workspace, its complete asks for removal assistance.

`tasks.provision` stays registered as the request kind of `task:start`, which scopes the route's `Idempotency-Key`. No Operation of it starts. Its registry entry names `operation-instance-create`, the workflow a start runs through its task copy, and declares no vault pattern. The rows of `task_vms` stay, and nothing changes them.

The task VM workflows that #3849 kept are deleted: the start `operation-task-provision` with its children `task-vm-start` and `task-vm-hub-peer`, and the place release `operation-task-vm-slot-release` with its children `task-vm-slot-phase` and `task-vm-hub-peer-remove`. The playbook `swamp/ansible/task-vm-start.yml`, which only the start ran, goes with them. `operation-task-cancel` calls no release any more; a cancel starts no run.

The read-only task places view is removed too: the `capacity` of `task:status`, the `capacity_wait` of an Operation and of a task, the `input.capacity_request` summary, the places table of the CLI and the queue panel of the web app. Nothing admitted a waiting task VM start any more. A CLI from before this release refuses the `task:status` reply until it is updated. The column `capacity_wait_reason` stays. The `task_vm.*` error codes and the doctor codes `node.task_vm_orphaned`, `node.task_vm_missing`, `node.task_vm_inspection_failed` and `node.task_vm_stray_*`, which nothing produced, are deleted.

### The Pi agent server

The Pi agent server `apps/pi-server` is removed with its CI check, its Dependabot entry and its page. It ran Pi coding-agent sessions on a task VM, as the VM's one user `orbit`, with a token per VM. The decisions 0167 (resume a Pi turn after a server restart), 0168 (offload large Pi tool output) and 0193 (run task agents as a dedicated user) described it; their old paths lead here.

No Node enrollment, certificate signing, VPN or worker material and no Process install accepts a `tasks.provision` Operation any more. A Node joins the fleet only through `node.add`.

The task VM start installs no Pi server any more. Its fetch and checksum steps, the `pi_path` playbook input, the Ansible task file `task-vm-pi.yml`, the Pi line of the role's end check and the Pi unit of the VM identity are deleted.

The machine recipe pins no Pi build: the tool `pi`, its entry in the task VM profile `base` and its catalog line are deleted, so the recipe identity changed. `ORBIT_PI_PORT` stays reserved: a Node service may not use the port, and the task VM hub rules name it, until the task VM settings are removed. The `pi` fields of the task VM settings, the Pi unit check of `tasks.provision` and the Pi firewall rules of the boot phase stay with the task VM runtime.

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
