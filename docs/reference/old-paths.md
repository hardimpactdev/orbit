---
title: "Old paths"
description: "Retained transition code, its replacement and the evidence needed before removal."
covers:
  - apps/gateway/database/migrations/2027_03_03_000000_drop_task_vm_tables.php
---

# Old paths

Check this ledger before building on transition code. Remove an entry with its implementation and exclusive tests when its exit condition holds. A source change alone cannot prove that deployed files, rules or Instances are gone. Do not extend a removed runtime.

## Retirement ledger

Each row names what must replace the path and the evidence that permits its removal.

| Retained path | Replacement | Evidence needed before removal |
| --- | --- | --- |
| The `instance-create-stage-`, `instance-create-redact-token-` and `instance-create-redact-model-` execution-copy prefixes in destroy and grants | The one-line launch copy `instance-create-launch-<operation>` | No Instance created on release 2079 or 2084 still owns these copies |
| The Swamp workflow `operation-firewall-direct` for `firewall.allow`, `firewall.deny` and `firewall.remove` | The [Gateway engine](/reference/gateway-engine) step `ufw.ensure_rule` on the executor | The live Gateway lists the three kinds in `ORBIT_ENGINE_KINDS` and every Linux Node reports `ufw.ensure_rule`, which needs the executor on every Node |
| [Merge-base proof replay](#the-task-vm-proof-run) and its restore children | Replay in [`proof:run` copies](/cli/proof/run#the-run) | Flagged cases actually replay on the merge base through copies |
| `GatewayRuntimeFiles::RetiredSettings`: `ORBIT_TASK_VMS_*`, `ORBIT_PI_PORT`, `ORBIT_SWAMP_PROOF_CLUB_API_KEY`, `ORBIT_SWAMP_DIRECT_WRITERS` | Gateway-role convergence removes these keys from `shared/gateway.env` | Key-name inventory shows none on any Gateway; never copy their values into evidence |
| `swamp/ansible/tasks/node-converge-hub-filter-absent.yml` | Hub convergence removes the task VM filter | No vpn Node holds `orbit-task-vms-hub.service`, `/etc/orbit/task-vms/hub.nft`, nft table `inet orbit_task_vms` or UFW comments beginning `orbit:task-vm-` |
| Existing development checkout Instances, their PHP-FPM pools, [`instance:migrate`](/cli/instance/migrate), `instance.migrate`, `operation-instance-migrate` and `instance-migrate.yml` | [Container-only admission](/reference/development-containers#container-only-admission) and migration of existing checkouts | New development checkout admission is closed; inventory every app-dev Node and confirm the last checkout has moved before removing migration or pools |
| New production host checkouts from `instance:clone` | [Production containers](/reference/production-containers) | Ship and prove production container cloning, then remove the production-clone exception; inventory existing production checkouts separately |
| Null VM contract fields | A coordinated contract cleanup | Remove `vm_power`, `task_vm_id` and `task_vm_guest` together from Gateway schemas, SDK DTOs and fixtures, CLI, web and frozen agent contracts; pass their contract checks |
| Egress archive reads | Container observations | Inventory confirms no stored `task_group_egress` rows need the archive readers |
| Reserved `10.44.0.128/25` and its model-guard allowance | Container forwarders on registered Nodes | Confirm no peer needs the range; remove the reservation and obsolete allowance together, not the container model guard |

Current responses keep `vm_power` and `task_vm_id` null and `task_vm_guest` false. Consumers must not use them to infer workspace state. Operations from the removed runtime keep their recorded results. Stored `task_group_egress` rows are not container observations.

## The task VM proof run

Copy proofs do not yet send the `head` and `merge_base` inputs that activate merge-base replay. Retaining the old steps does not mean copy proofs run them. Keep these until replay is ported:

- The merge-base replay steps of `proof-fleet-verify`, including `Restore the head checkout`, `can-fail` and `can-fail-batches`.
- The batch restore of `proof-fleet-verify-batch`.
- `proof-fleet-restore`, `task-nested-apply` and `task-nested-ready`.

The removed `operation-proof-run` and task VM start are not an alternative proof runtime.

## The Pi task loop

Task VMs and the Pi task loop are removed, not disabled. Tasks run only in [task copies](/reference/tasks#task-copy); a group without one has no usable workspace. Old Operations remain [history](/reference/operations#kinds-that-left-the-registry). A capability from the old loop returns only as its own task-copy change.

### The task VM lifecycle

There is no VM start, resume, slot release or host sweep. `tasks.provision` remains the start route's request kind for idempotency, but the start admits the copy's `instance.create`. No Operation of `tasks.provision` starts. [Converge the hub](/reference/wireguard-endpoints#converge-the-hub) removes its retained network filter.

### The Pi agent server

`apps/pi-server`, its runtime delivery and the `pi` driver are removed. Stored thread rows remain readable. Current turns use [T3 in a copy](/reference/tasks#copy-turn-agents).

### Task VM settings

The settings, `task_vms` Node key, `image_refresh_hold`, slot capacity and image refresh are removed. The Gateway refuses those input fields. The environment scrubber and hub cleanup remain only until the fleet-dependent conditions in the ledger hold.

### The task VM readers

VM guards, SSH workspace readers and the remote check runner are removed. A copy uses its Instance runtime and normal destruction. The remaining null contract fields are listed above, not evidence of a live runtime.

### The task VM tables

The migration `2027_03_03_000000_drop_task_vm_tables` drops `task_vms`, `task_vm_image_builds` and `task_vm_image_recipe`. Its rollback recreates empty tables, not their rows. Earlier migrations remain necessary for upgrades and rollback.

### The guest agent chain

The guest agent runner and its Incus transport are removed. Node transports remain; container execution goes through the recorded host Node. Shared layout and model routing belong to Instances, and release recipe selection belongs to MachineSoftware. The task VM package cache and its Gateway selector are removed; nested containers keep their own egress proxy settings.

### The unreachable Swamp VM paths

Task check, publish and sync have no VM branch. The uncalled `operation-task-sync` workflow, the eight `task-check-*` VM children, their argv builders and the task VM base-build graph (`task-vm-host.yml`, `task-vm-role.yml` and their exclusive tasks) are removed. Node convergence keeps the shared package tasks as `node-converge-apt.yml`, `node-converge-apt-config.yml`, `node-converge-javascript.yml` and `node-converge-deno.yml`. The chain that replays the [merge-base proof](#the-task-vm-proof-run) stays. It keeps `task-vm-fleet-wait.yml` and `task-vm-nested-wait.yml`.

## Off in the live fleet

These are recorded live-fleet switches, not source-code retirement conditions. Their last recorded state is from 13 November 2026; this cleanup does not recheck or change deployed settings. Keep each row until deployed settings and recent runs establish its new state.

| Part | Re-enable condition |
| --- | --- |
| Automatic development checkout deploys and the development arm of `automatic-production-deploy` | The release delivering [automatic container-default deploys](/reference/deployments#automatic-development-defaults) is live and its policy is enabled |
| Nightly fleet reboot (`ORBIT_FLEET_REBOOT=false`) | One safe test of the same pass |
| Automatic Gateway releases (disabled; manual deploy also pauses them) | The container path is stable and the same tick is enabled |
