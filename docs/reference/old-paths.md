---
title: "Old paths"
description: "Code that still runs but that a stage of the command development pipeline replaces. Check this page before you build on it."
covers:
  - apps/gateway/app/Infrastructure/TaskVms/RecipeImageBuilds.php
  - apps/gateway/app/Domain/Tasks/TaskProvisionIntent.php
---

# Old paths

Check this page before you build on something. Each row is code that still runs, was not removed, and is replaced by a stage of the [command development pipeline](/reference/command-development-pipeline). Keep it and do not extend it. When its stage lands, delete it with its tests and remove the row. Code that is replaced and has no live caller is deleted, not listed here.

| Item | Replaced by | Removed at |
| --- | --- | --- |
| The `<name>-topology-seed` clone name in `TaskVmSlotRuns::seedClone` for VMs without a place number | Every start's VM has a place and a `<name>-seed` clone | When no VM of the old pool (`tvm-610`, `tvm-663`) is live |
| `GatewayRuntimeFiles::RetiredSettings`, the four `ORBIT_TASK_VMS_UPCLOUD_*` keys | Nothing: the `gateway` role's convergence removes them from `shared/gateway.env` | When no Gateway has them |
| The `certificate`, `pi_port`, `control_ip` and `gateway_ip` of a task start identity: the CA files and the three `identity.env` lines (`ORBIT_PI_PORT`, `ORBIT_CONTROL_IP`, `ORBIT_GATEWAY_IP`) a task start still writes | The boot phase of the base converge (`boot.trust`) bakes the CA and the firewall into the base; the `orbit-identity` unit mounts its own share | When both hosts' bases are refreshed after the boot phase merged |
| The project names in `TaskVmHostLeftovers::RetiredProjects` (`orbit-baselines`, `orbit-measure-nr`) and `ImageProjects` (`orbit-proto`) | Nothing: the refresh's `host-clean` phase removes them from every host | When no task VM host has them |
| The Project 62 branch of `TaskProvisionIntent` (`proof-reaper` and `ProofReaper` were deleted on 2026-11-06) | Nothing: only the removed `bin/swamp-proof` made Project 62 groups. The `$size` the refresh passes to the same intent stays | When no Project 62 group is open |
| [`instance:migrate`](/cli/instance/migrate), its kind `instance.migrate`, the workflow `operation-instance-migrate` and the playbook `instance-migrate.yml`; and checkout Instances on an `app-dev` Node, with their PHP-FPM pools | Every new Instance is a [container](/reference/development-containers); the command moves each checkout Instance into one | When the last checkout Instance has moved, with the switch of every `app-dev` Node ([PR 16](/solutions/development-container-runtime#pr-16-switch-every-app-dev-node)) |
| `bin/e2e-topology` and the per-issue topology of a subtask that declares `topology`, with its virtiofs share of the worktree | The nested fleet ([stage 2](/reference/command-development-pipeline#2-development-environment)) | When no subtask declares a topology |

The admission of `task:start` mints no GitHub token. The provision clone, the setup steps, a package install and the publish step mint their own when they run ([GitHub App](/reference/github-app#how-orbit-publishes-a-task-pull-request)). Admission holds no token for them.

`apps/agent/**` is being stripped to an observer: commands that nothing calls leave in small pull requests, and the rest leave with their Swamp ports.

The capacity model replaces the shared CI reserve, `task_max_vms`, resource-unit admission and the `task:park` command. Hosts expose separate task and CI place counts. Publication deletes task VMs; later work claims a fresh VM. See [capacity](/reference/command-development-pipeline#capacity-ideal-state).
