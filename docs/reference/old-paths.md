---
title: "Old paths"
description: "Retained transition code, its replacement, and the evidence required before removal."
covers:
  - apps/gateway/database/migrations/2027_03_03_000000_drop_task_vm_tables.php
---

# Old paths

Check this ledger before building on transition code. Remove an entry, its implementation, and its exclusive tests only when its fleet evidence condition holds. Do not extend removed runtimes.

## Retirement ledger

Each row names a retained path, its replacement, and the evidence required before deletion:

| Retained path | Replacement | Evidence needed before removal |
| --- | --- | --- |
| `instance-create-stage-`, `instance-create-redact-token-`, `instance-create-redact-model-` prefixes in destroy and grants | One-line `instance-create-launch-<operation>` copy | No live Instance still owns those execution copies |
| Swamp workflow `operation-firewall-direct` (`firewall.allow`, `deny`, `remove`) | [Gateway engine](/reference/gateway-engine) step `ufw.ensure_rule` | Live Gateway enables all three in `ORBIT_ENGINE_KINDS` and every Linux Node reports `ufw.ensure_rule` |
| [Merge-base proof replay](#the-task-vm-proof-run) and restore children | Replay inside [`proof:run` copies](/cli/proof/run#the-run) | Flagged cases replay on merge base through copies |
| `ORBIT_TASK_VMS_*`, `ORBIT_PI_PORT`, `ORBIT_SWAMP_PROOF_CLUB_API_KEY`, `ORBIT_SWAMP_DIRECT_WRITERS` scrubbers | Gateway-role convergence strips them from `shared/gateway.env` | Key-name inventory confirms none remain on any Gateway |
| `swamp/ansible/tasks/node-converge-hub-filter-absent.yml` | Hub convergence removes the task VM filter | No `vpn` Node carries `orbit-task-vms-hub.service`, `/etc/orbit/task-vms/hub.nft`, `inet orbit_task_vms`, or `orbit:task-vm-` UFW comments |
| Existing dev host checkouts, PHP-FPM pools, [`instance:migrate`](/cli/instance/migrate), `operation-instance-migrate` | [Container-only admission](/reference/development-containers#container-only-admission) | Inventory confirms zero host checkouts remain on any `app-dev` Node |
| Production host checkouts from `instance:clone` | [Production containers](/reference/production-containers) | Production container cloning is proven and existing checkouts inventoried |
| Null VM contract fields (`vm_power`, `task_vm_id`, `task_vm_guest`) | Coordinated schema/SDK/CLI/web/agent cleanup | All surfaces remove the fields together and pass contract checks |
| Egress archive readers (`task_group_egress`) | Container observations | Inventory confirms no stored archive rows are still needed |
| Reserved `10.44.0.128/25` range and model-guard allowance | Container forwarders on registered Nodes | Confirm no peer needs the range before removing it |

Responses keep `vm_power` and `task_vm_id` as `null` and `task_vm_guest` as `false`; consumers must not infer workspace state from them.

## The task VM proof run

Container copy proofs do not yet pass `head` and `merge_base` to run merge-base replay. Until replay is ported to copies, keep:

- Merge-base replay steps in `proof-fleet-verify` (`Restore the head checkout`, `can-fail`, `can-fail-batches`).
- Batch restore in `proof-fleet-verify-batch`.
- `proof-fleet-restore`, `task-nested-apply`, and `task-nested-ready`.

## The Pi task loop

Task VMs and the Pi task loop are removed; tasks run exclusively in [container task copies](/reference/tasks#task-copy).

### The Pi agent server

`apps/pi-server` and the `pi` driver are removed. Stored thread rows remain readable; current turns run through [T3 in a copy](/reference/tasks#copy-turn-agents).

### Task VM settings

Task VM settings, `task_vms` Node keys, `image_refresh_hold`, slot capacity, VM readers, and VM workflows are removed (`tasks.provision` remains only as an idempotency request kind for `instance.create`). Migration `2027_03_03_000000_drop_task_vm_tables.php` drops `task_vms`, `task_vm_image_builds`, and `task_vm_image_recipe` (rolling it back recreates empty tables, not data).

## Off in the live fleet

These switches in the live fleet remain off until their re-enable conditions are verified on deployed machines:

| Switch | Re-enable condition |
| --- | --- |
| Automatic dev checkout deploys and dev arm of `automatic-production-deploy` | [Automatic container-default deploys](/reference/deployments#automatic-development-defaults) are live and enabled |
| Nightly fleet reboot (`ORBIT_FLEET_REBOOT=false`) | One safe verification pass completes |
| Automatic Gateway releases | Container release path is stable and the release tick is enabled |
