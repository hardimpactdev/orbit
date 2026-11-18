---
title: "Fixture retirement"
description: "Which shape-gate fixture families a proof cases file covers today, whether a proof batch of it fits in five minutes, and which family can retire now under ADR 0262."
---

# Fixture retirement

[ADR 0262](/decisions/0262-prove-changed-operations-on-the-pull-request#build-order) retires the shape-gate fixtures, family by family. A family retires only when two things are true:

- A [proof cases file](/reference/verification-cases) covers the family's realistic behaviour.
- A proof batch of it runs within the five minutes that a pull request may take ([Implementation loop](/reference/implementation-loop)).

The deleting pull request of a family merges only after the proof is switched on and has gated one real pull request green.

## Survey

This table groups the 178 fixture files registered under `.github/ci/fixtures/` by the operation they test. **Cost** is the single-worker seconds that the registries declare. **Covered** compares the family's realistic behaviour with the cases of today, not with their `uncovered` lists.

| Family | Fixture files | Files, cases, cost | Proof cases file | Covered | Batch wall time | Retire now |
| --- | --- | --- | --- | --- | --- | --- |
| Shape gate and Swamp source checks | `swamp/bin/*`, `*shape_gate_tools*`, `guard_syntax`, `workflow_expression_ends`, `end_check_paths`, `workflow_absent_secrets`, `sensitive_method_grants` | 13, 195, 62 | None: they stay quick checks under the ADR | Not needed | None | No, they stay |
| Workflow engine and shared behaviors | `workflow_*` engine, start and placement tests, `ansible_inputs`, `ansible-input/write`, `incus_exec_stdin`, `github_mint`, schedule and service placement | 17, 90, 125 | None | Not needed: pure-code | None | No, waits for the pure-code question |
| Maintenance, dependencies and Projects | `automatic-deploy/*`, `*-maintenance/*`, `horizon-new-dawn/*`, `batch_maintenance`, `dependency_*`, `maintenance_*`, `project_*` | 23, 401, 79 | None | Not needed: mostly pure-code | None | No, waits for the pure-code question |
| Node firewall in a role converge | `ansible_firewall_test.py` | 1, 3, 6 | `firewall/allow`, `node/role-add` | No: no case moves a Node allow with its pair | Over 5 min, estimated: the role-add batch runs converges | No |
| Node converge, add and hub | `node_converge_*`, `ansible_node_add`, `ansible_hub_*`, `*wireguard_hub_lan`, `node_network_hub_prune`, `ansible_artifact`, `ansible_static_php`, `apt_proxy`, `caddy_candidate` | 14, 172, 405 | `node/converge`, `node/role-add` | Partly: 16 and 20 `uncovered` entries | Over 5 min, estimated: its converges run one at a time | No |
| Gateway and Swamp roles | `ansible_gateway_*`, `gateway_release_*`, `gateway_swamp_load`, `swamp_worker_*`, `swamp_role_worker_*`, `ansible_swamp_start_cache` | 9, 75, 1971 | None (`node/role-add` lists the Swamp role as uncovered) | No | None | No |
| Instance host | `ansible_instance_host_*`, `ansible_instance_bridge`, `ansible_instance_egress`, `ansible_instance_volmode`, `ansible_container_host_system`, `ansible_model_forwarder`, `instance_host_probe_contract` | 12, 135, 1898 | None | No: the proof fleet has no Instance host | None | No |
| Instance containers | The three [instance-container](/reference/fixture-retirement/instance-container) files, `instance_clone*`, `instance_create_native`, `instance_node_*`, `instance_machine_name`, `instance_workspace_modes`, `instance_restore`, `process_destroy_failed_cache` and their neighbours | 20, 438, 5865 | `instance/sleep`, `instance/wake`, `instance/node-service-*`: refusals only | No | About 15 min with an Instance host | No, kept until the math fits |
| Instance complete | `instance_complete_*` | 2, 17, 31 | `instance/complete`: one refusal | No | Seconds today | No |
| Instance migrate and mail | `*instance_migrate*`, `migrate_result`, `ansible_instance_mail*` | 10, 335, 8492 | `instance/migrate`, `instance/mail-update`: refusals only | No: the proof fleet has no Instance pool | None for the move | No |
| Instance destroy and unregister | `instance_destroy_*` (but not the Route release), `destroy_push_check`, `instance_unregister`, `instance_register_grants_native` | 7, 29, 62 | `instance/destroy`: no case; `instance/unregister`: one refusal | No | Seconds today | No |
| Routes | `route_*`, `ansible_route_site`, `ansible_instance_route_withdraw`, `routes/update-command`, `instance_destroy_route_release` | 10, 89, 78 | None | No | None | No |
| Conn environment restart | `workflow_conn_environment_restart_test.ts` | 1, 15, 312 | `conn/environment-restart`: one refusal, 13 `uncovered` | No | Seconds today | No |
| Fleet reboot | `fleet_reboot_*` | 2, 39, 312 | None | No | None | No |
| Tasks and task copies | `ansible_task_copy_turn`, `task-copy-turn/*`, `t3_copy_rpc`, `task_copy_*`, `task_instance_*` | 15, 139, 1589 | None | No | None | No |
| Proof copy and nested fleet | `proof_*`, `ansible_task_vm_*`, `ansible_instance_fleet_test`, `instance_fleet_shrink`, `instance_fleet_runtime`, `fleet_converge_inputs`, `workflow_instance_deploy_fleet`, `ansible_task_container_host_prepare`, `ansible_task_host_firewall_remove` | 22, 258, 4569 | None: no proof can cover it | Not needed | None | No, they stay under the [ADR's exception](/decisions/0262-prove-changed-operations-on-the-pull-request#exception-the-code-that-makes-the-proof-copy) |

## Batch wall time

Every batch pays a fixed part before its cases:

- The restore of `base`, bounded at 45 seconds, and the wait for the boot of the nested containers.
- The preparation of the fleet, bounded by its budget in [Verification cases](/reference/verification-cases). Current observers on the nested Nodes make it short.

A batch of read cases, such as `node:show`, fits in well under five minutes: each read case takes about a second. A case that converges a Node, or that runs an operation on an Instance, takes from tens of seconds to minutes, and cases on the same Node run one after another. So the batches of `node/converge` and `node/role-add` do not fit in five minutes, and an Instance batch does not fit without the changes in [The five-minute math](/reference/fixture-retirement/instance-container#the-five-minute-math).

## Result

No family can retire now. The families with a fast proof batch have proof cases that check only refusals of the CLI. The families whose proof cases check real effects run converges, which do not fit in five minutes. The proof copy family and the shape gate checks stay by decision.
