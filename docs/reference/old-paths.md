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
| The `<name>-topology-seed` clone name in `TaskVmSlotRuns::seedClone` for VMs without a place number | Every start's VM has a place and a `<name>-seed` clone | When no VM of the old pool (`tvm-610`, `tvm-663`) is live |
| `GatewayRuntimeFiles::RetiredSettings`, the four `ORBIT_TASK_VMS_UPCLOUD_*` keys and `ORBIT_SWAMP_DIRECT_WRITERS` | Nothing: the `gateway` role's convergence removes them from `shared/gateway.env` | When no Gateway has them |
| The `certificate`, `pi_port`, `control_ip` and `gateway_ip` of a task start identity: the CA files and the three `identity.env` lines (`ORBIT_PI_PORT`, `ORBIT_CONTROL_IP`, `ORBIT_GATEWAY_IP`) a task start still writes | The boot phase of the base converge (`boot.trust`) bakes the CA and the firewall into the base; the `orbit-identity` unit mounts its own share | When both hosts' bases are refreshed after the boot phase merged |
| The project names in `TaskVmHostLeftovers::RetiredProjects` (`orbit-baselines`, `orbit-measure-nr`) and `ImageProjects` (`orbit-proto`) | Nothing: the refresh's `host-clean` phase removes them from every host | When no task VM host has them |
| [`instance:migrate`](/cli/instance/migrate), its kind `instance.migrate`, the workflow `operation-instance-migrate` and the playbook `instance-migrate.yml`; and checkout Instances on an `app-dev` Node, with their PHP-FPM pools | Every new Instance is a [container](/reference/development-containers); the command moves each checkout Instance into one | When the last checkout Instance has moved, with the switch of every `app-dev` Node ([PR 16](/solutions/development-container-runtime#pr-16-switch-every-app-dev-node)) |
| The release trigger of `task_vm.image_refresh` (the release step "Admit VM refreshes", which runs `task-vms:refresh-images`) and its daily schedule | The [refresh after a release](/reference/development-containers#refresh-after-a-release) of the nested-fleet default Instance, on each switched-on Node | When the last proof runs in containers: CI proof shards in clones ([PR 12](/solutions/development-container-runtime#pr-12-switch-task-work)) |
| `bin/e2e-topology` and the per-issue topology of a subtask that declares `topology`, with its virtiofs share of the worktree | The nested fleet ([stage 2](/reference/command-development-pipeline#2-development-environment)) | When no subtask declares a topology |

The admission of `task:start` mints no GitHub token. The provision clone, the setup steps, a package install and the publish step mint their own when they run ([GitHub App](/reference/github-app#how-orbit-publishes-a-task-pull-request)). Admission holds no token for them.

`apps/agent/**` is being stripped to an observer: commands that nothing calls leave in small pull requests, and the rest leave with their Swamp ports.

The capacity model replaces the shared CI reserve, `task_max_vms`, resource-unit admission and the `task:park` command. Hosts expose separate task and CI place counts. Publication deletes task VMs; later work claims a fresh VM. See [capacity](/reference/command-development-pipeline#capacity-ideal-state).

## Off in the live fleet

This section describes the live Orbit fleet, not the code. On 13 November 2026 these parts stay in the code but do not run:

| Part | Comes back as |
| --- | --- |
| Task VMs (0 task places on every host) and their image refresh (held on every host) | Tasks that start from the Orbit container's snapshot |
| Proof CI in a VM (the repository variable `ORBIT_PROOF_ENABLED` is unset) | Proof on a copy of that snapshot |
| Automatic deploys of development Instances | One deploy per new commit, for container defaults only |
| The nightly fleet reboot (`ORBIT_FLEET_REBOOT=false`) | The same pass, after one safe test |
| Automatic Gateway releases (disabled; a manual deploy also pauses them) | The same tick, when the container path is stable |
