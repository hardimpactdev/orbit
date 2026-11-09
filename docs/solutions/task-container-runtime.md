---
title: "Task container runtime"
description: "Plan for step 3 and the parity gate of ADR 0250: the build PRs that add task containers beside task VMs, their tests and proofs, and the decisions that are still open."
---

# Task container runtime

This is the plan for step 3 of [ADR 0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers#build-order), the task container runtime, and for step 4, the parity gate. [Task containers](/reference/task-containers) specifies the runtime and its charts. This page orders the work and lists the decisions that are still open. It is a plan, not a contract: each PR moves the part it builds into the owning reference pages.

## Rules for every PR

Another thread proves commands on the task VM proof fleet at the same time. A regression on a VM host stops its work, so every PR follows these rules.

| Rule | How a PR keeps it |
| --- | --- |
| Nothing that runs today changes | Task VM hosts keep their behavior |
| The VM intent stays the same | A Pest snapshot test per intent shows that a VM intent gains only `runtime: vm` and `slot.tunnel_unit` |
| Old intents keep working | Ansible and the workflows read a missing `runtime` as `vm`, so a run admitted before the release still passes |
| Container work has its own task files | Ansible includes `tasks/task-container-*.yml` only on a container host |
| The VM task list changes only by its includes | A gate test compares `ansible-playbook --list-tasks` of the VM tags with the base branch. The only new lines are the named container includes |
| Workflow step names stay | A step whose machine work differs reads it from the intent or the phase |
| Gateway and Swamp ship together | One PR, as the [contributor guide](/contributor-guide) requires |
| A changed command starts with its spec | The first commit of the PR updates the command's chart, page and cases |
| The checks pass | `bin/swamp-shape-gate`, `bin/check-flows`, `composer docs-lint` and the tests of each changed project |
| Live checks stay off live hosts | They run on the container test host of [open question 2](#2-where-the-containers-run-before-the-switch) |
| VM hosts prove themselves after the merge | The next release refreshes every VM host. A failed refresh keeps the host's base, and the PR is reverted |
| Docs move with the code | A PR moves the part it builds from [Task containers](/reference/task-containers) into the owning page |

## Build PRs

The PRs land in this order. PR 5 can run beside PR 4.

### PR 1: the runtime setting and its records

This PR changes the Gateway and the `node:update` command. No workflow reads the new fields yet. `node:update` has no cases file today, so the spec commit adds `swamp/proof/node/update.cases.yaml` with the new field and its refusals.

- `TaskVmHost` gets `runtime`: `vm` or `container`, default `vm`.
- `config/task_vms.php` `host_defaults`, the settings data and `AgentWatchListAction::egress()` take the container defaults from the runtime.
- A settings update refuses a `runtime` change on a host with a live row: HTTP 409 `task_vm.runtime_in_use`.
- A `runtime` change that keeps the old `project`, `network` or `cidr` fails with `task_vm.invalid_config`.
- A `runtime` change withdraws the recorded base.
- A migration adds `runtime` to `task_vms` and `task_vm_image_builds`, default `vm`.

The rest of the Gateway then reads the runtime:

- Normal placement skips container hosts while `task_vms.container_placement` is `false`, the default.
- Placement reads the recorded base per host and runtime. A row records the runtime it starts with.
- Every task VM intent carries `runtime`. The provision intent also carries `slot.tunnel_unit`, which is `wg-quick@orbit.service` on a VM.
- The API, the MCP schema, the web types and `node:update --task-vms` accept `runtime`.

Tests: Pest for the validation, the defaults, both refusals, the base withdrawal, placement by runtime and by `container_placement`, and the intent snapshots.

### PR 2: prepare a container host

This PR adds the container branch of the `host-prepare` phase.

- `tasks/task-vm-host-prepare.yml` includes `tasks/task-container-host-prepare.yml` on a container host.
- The new file sets up the project, its restrictions with `limits.virtual-machines=0`, the stock container alias, the kernel modules and the sysctls.
- It checks the host's subordinate ID range and that `sctp` cannot load.
- It checks that `incus info` reports `idmapped_mounts`. Without them, every copy would shift its files and stop being a clone.
- The egress log part runs on the container bridge unchanged. Only its input names the bridge.
- The Gateway's refresh intent names the container project, bridge and alias.

Tests: Molecule `unit` cases for the modules and sysctl templates, `ansible-lint`, and Pest for the refresh intent. Live: the phase twice on the test host, the second time with no change.

### PR 3: the base container, the start and the release

The candidate proof of a refresh is a start and a release, so these parts land together. Open questions 3 and 6 must be decided before this PR starts.

- **Base.** The phases `base-start`, `base-snapshot`, `base-discard` and `base-prune` get container branches.
- **Base start.** `incus init` from `ubuntu-26.04-ct` with the [outer settings](/reference/task-containers#the-outer-container) and `boot.autostart=false`.
- **Base snapshot.** It stops the nested containers, then the base, then snapshots the base.

The converge and the fleet build get these branches:

- **Converge.** Container branches of `software` (the inner Incus), `tools` (subordinate IDs, the pool, `oe-nested`) and `check`.
- **Boot.** `orbit-wireguard.service` for the interface `orbit`, with `ip` and `wg`. The identity's units get `ConditionPathExists=` on their identity files.
- **Operator tunnel.** `orbit-wg-nested.service` for `wg-nested`, with `ip` and `wg`.
- **Fleet.** The AppArmor device on each nested container, and a check that fails on any `LoadCredential` unit.
- **Base retake.** The task "Take the base snapshots" of `task-vm-role.yml` stops the nested containers before the snapshot.

The start, the release and the Gateway get these parts:

- **Start.** `incus copy` of the snapshot, the identity by `incus file push`, the limits, `boot.autostart=true`, and `incus start`.
- **Device check.** The start refuses a copy with a device beyond its root disk and NIC.
- **Release.** `incus delete --force`. The readiness step reads `slot.tunnel_unit`.
- **Gateway.** Refresh admission for container hosts, and the start and release intents.
- **Doctor.** The stray report covers the container project.
- **Power.** The agent's `incus.vm-state` reads only VMs. The agent only observes and gets no new command, so a container's presence comes from `incus.instances`.
- **Running state.** The container's own agent heartbeat gives its running state.

If the heartbeat is not exact enough for power, the PR stops and asks for a decision before it adds an agent read.

Tests: Pest for the intents and the stray report, Molecule `unit` cases for the two WireGuard units and the identity files, `swamp workflow validate`, the shape gate and the behavior-name lint.

Live, on the test host:

1. `task-vms:refresh-image <host> --from-stock` promotes a base. Its candidate proof is one start, its ready check and its release.
2. A second refresh is a no-op apart from the snapshot.
3. The PR records the times of the copy, the start, the refresh and the stop of the nested fleet.
4. `zfs get written` on a fresh copy shows almost no data, so the copy is a clone and Incus shifted no files.
5. Inside a started container, `incus config show` lists no extra device, and no unit has `LoadCredential`.
6. Each nested container shows `N` at `/sys/module/apparmor/parameters/enabled`, and the hub handshake is recent.

### PR 4: proofs on a container

This PR makes the proof flows work on a container and lets the parity gate choose a host. It changes the `proof:run` command, so its spec commit updates `docs/cli/proof/run.mmd`, `run.mdx` and the `proof:run` cases.

- The step "Take base again" of `proof-fleet-verify` stops the nested containers before the snapshot on a container.
- `proof.verify` and `proof.command` pass the runtime to their children.
- `proof:run` gets an operator option `--host=<node>`. It pins every shard to the task places of one host.
- `--host` reaches a container host while `container_placement` is off. A `ci-runner` caller cannot use it.

Tests: Pest for the admission of `--host` (operator only, an active configured host, a usable base) and for the proof intents, and `swamp workflow validate`.

Live: `orbit proof firewall:allow --group <group>` on a group on the test host. Every restore must stay under the 45-second limit. A slower restore goes to [open question 5](#5-a-restore-over-45-seconds) before the parity gate.

### PR 5: start a stopped container again

This PR covers a `ready` container that stopped, for example after a host reboot.

- The resume admission runs only mode `converge` for a container row. A container is never parked.
- The step "Wait for the guest agent" runs `incus exec … systemctl is-system-running --wait` on a container.

Tests: Pest for the admission by runtime, and `swamp workflow validate`. Live: stop a `ready` container on the test host with `incus stop`, start a workspace operation, and see the container run again.

## Parity gate

Step 4 of ADR 0250 passes when the command proof cases give the same verdicts on task containers as on task VMs. The VM half takes task places on a VM host, so it runs in a window agreed with the operations migration thread.

1. Pick one commit of `main` after PR 5.
2. Run `proof:run --all --host=<container host>` for that commit.
3. Run `proof:run --all --host=<VM host>` for the same commit.
4. Compare the verdicts per case. Every case that passes on a VM must pass on a container.
5. A case that fails on both runtimes is a case defect. It does not block the gate.
6. Run one real task group on the container host: a start, a turn, a handoff check and a publish. Then release it.
7. Record the case table on this page. Add the times of a start, a restore and a refresh on both runtimes.
8. Record the peak memory of a container place.

A case that fails only on a container blocks the gate. The whole run passes again after the fix. After the gate, step 5 sets `task_vms.container_placement` and switches Beast, then Shark, in a window with no proofs or merges in flight.

## Open questions

These need a decision before the PR that depends on them. Each has a recommendation.

### 1. Identity of a task container in steps 3 to 5

ADR 0250 says that a container is never a Node. But the task workspace is an Instance on a Node. The agent runner, Git transfers, Pi, the hub filter and the model router guard use the Node's WireGuard address.

| Option | Effect |
| --- | --- |
| A: keep the identity until step 6 | The task container keeps the task VM's Node identity. Every flow works unchanged. Step 6 removes it when Instances get their own runtime |
| B: no identity now | Steps 3 to 5 wait for the Instance runtime of step 6. Every task flow changes at once |

Recommendation: A. It is the shortest path to parity and to UpCloud. The ADR records the exception until step 6.

### 2. Where the containers run before the switch

A host runs one runtime, so the parity gate needs a container host. The test host gets two task places for `--host` runs and one CI place for its refresh proof.

| Option | Effect |
| --- | --- |
| A: a new UpCloud Node | It proves the UpCloud requirement in the same gate and touches no live host. The ADR's Verify line then says "on a container host", not "on Beast" |
| B: Sabre | No new machine. Sabre's CI places stop running VM proofs |
| C: Beast, with a runtime per pool | It matches the ADR's Verify line. It adds a setting that step 7 removes |

Recommendation: A.

### 3. WireGuard of the nested Nodes

The nested Gateway sets up WireGuard on its Nodes with the shared Node converge, which uses `wg-quick`. Today the fleet build turns off the `wg` and `wg-quick` AppArmor profiles in each nested container with a cloud-init `bootcmd`. One level deeper, ADR 0250 says to use `ip` and `wg` instead.

| Option | Effect |
| --- | --- |
| A: keep the `bootcmd` and add the AppArmor device | No profile loads in the nested containers, so `wg-quick` runs without one. The shared converge does not change. PR 3 proves it, and ADR 0250's WireGuard line changes |
| B: a container branch in the Node converge | It uses `ip` and `wg`. It changes a phase that every Node runs |

Recommendation: A. Use B only if PR 3 shows that A fails.

### 4. Places on a container host

A container needs no reserved guest memory, so a host may hold more containers than VMs.

| Option | Effect |
| --- | --- |
| A: measure first | Keep the VM places through the parity gate, then set them from the measured peak memory |
| B: raise them now | More places at once, with no measurement |

Recommendation: A.

### 5. A restore over 45 seconds

This question applies only if PR 4 measures a slower restore on the directory pool.

| Option | Effect |
| --- | --- |
| A: raise the limit | The limit becomes the measured 95th percentile plus a margin |
| B: restore the whole container | A snapshot of the task container per task. A restore loses the agent's uncommitted work |

Recommendation: A.

### 6. `LoadCredential` in shared units

Three shared renderers write `LoadCredential`: the node worker unit in `apps/agent/src/commands/node_worker.rs` and `swamp/extensions/behaviors/node-worker/config.ts`, and the model router guard in `swamp/ansible/ai-router-task-guard.yml`. A proof case that runs one of them inside the nested fleet fails only on a container. The [Swamp shape guide](https://github.com/hardimpactdev/orbit/blob/main/swamp/docs/swamp-shape.md) approves `LoadCredential` for the Swamp Node's worker key.

| Option | Effect |
| --- | --- |
| A: a file for every Node | The renderer passes a file that only the unit's user reads. One rule for every Node, and the shape guide changes |
| B: a container branch | Only units in nested containers change. Two forms of each unit |

Recommendation: A, only for the renderers that PR 3 finds in the nested fleet.

### 7. Names

The runtime keeps `settings.task_vms`, `HOST-tvm-N`, the `task_vm.*` kinds and codes, and the VM step names.

| Option | Effect |
| --- | --- |
| A: rename at step 7 | One rename, after the VM runtime is gone |
| B: rename now | Every flow that the other thread proves changes |

Recommendation: A.
