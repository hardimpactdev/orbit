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
| The `certificate`, `pi_port`, `control_ip` and `gateway_ip` of a task start identity: the CA files and the three `identity.env` lines (`ORBIT_PI_PORT`, `ORBIT_CONTROL_IP`, `ORBIT_GATEWAY_IP`) a task start still writes | The boot phase of the base converge (`boot.trust`) bakes the CA and the firewall into the base; the `orbit-identity` unit mounts its own share | When both hosts' bases are refreshed after the boot phase merged |
| The project names in `TaskVmHostLeftovers::RetiredProjects` (`orbit-baselines`, `orbit-measure-nr`) and `ImageProjects` (`orbit-proto`) | Nothing: the refresh's `host-clean` phase removes them from every host | When no task VM host has them |
| The Project 62 branch of `TaskProvisionIntent` (`proof-reaper` and `ProofReaper` were deleted on 2026-11-06) | Nothing: only the removed `bin/swamp-proof` made Project 62 groups. The `$size` the refresh passes to the same intent stays | When no Project 62 group is open |
| The kinds `task_vm.fleet_build`, `task_vm.ready_copies` and `task_vm.converge`, with their workflows, commands, projections and charts | The fleet phase of the common converge builds the nested fleet from stock on a base VM without one ([build the nested fleet in the converge](/reference/compute-drivers#build-the-nested-fleet-in-the-converge)) | When a live refresh of an empty host and its task start pass |
| `bin/e2e-topology` and the per-issue topology of a subtask that declares `topology`, with its virtiofs share of the worktree | The nested fleet ([stage 2](/reference/command-development-pipeline#2-development-environment)) | When no subtask declares a topology |

The admission of a task run mints no GitHub token. The provision clone, the setup steps, a package install and the publish step mint their own when they run ([GitHub App](/reference/github-app#how-orbit-publishes-a-task-pull-request)). Admission holds no token for them.

`apps/agent/**` is being stripped to an observer: commands that nothing calls leave in small pull requests, and the rest leave with their Swamp ports.
