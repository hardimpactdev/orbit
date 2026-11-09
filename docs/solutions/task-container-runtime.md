---
title: "Task container runtime"
description: "Plan for step 3 and the parity gate of ADR 0250: the build PRs that add task containers beside task VMs, their tests and proofs, and the decisions that are still open."
---

# Task container runtime

This is the plan for step 3 of [ADR 0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers#build-order), the task container runtime, and for step 4, the parity gate. [Task containers](/reference/task-containers) specifies the runtime and its charts. This page orders the work and records the decisions it rests on. It is a plan, not a contract: each PR moves the part it builds into the owning reference pages.

## Rules for every PR

Another thread proves commands on the task VM proof fleet at the same time. A regression on a VM host stops its work, so every PR follows these rules.

| Rule | How a PR keeps it |
| --- | --- |
| Nothing that runs today changes | Task VM hosts keep their behavior |
| The VM intent stays the same | A Pest snapshot test per intent shows that a VM intent gains only `runtime: vm` and `slot.tunnel_unit` |
| Old intents keep working | Ansible and the workflows read a missing `runtime` as `vm`, so a run admitted before the release still passes |
| Container work has its own task files | Ansible includes `tasks/task-container-*.yml` only on a container host |
| `incus` gets no stray input | Every task and script that runs `incus` closes standard input, which `incus` reads as YAML when it is not a terminal |
| The VM task list changes only by its includes | A gate test compares `ansible-playbook --list-tasks` of the VM tags with the base branch. The only new lines are the named container includes |
| Workflow step names stay | A step whose machine work differs reads it from the intent or the phase |
| Gateway and Swamp ship together | One PR, as the [contributor guide](/contributor-guide) requires |
| A changed command starts with its spec | The first commit of the PR updates the command's chart, page and cases |
| The checks pass | `bin/swamp-shape-gate`, `bin/check-flows`, `composer docs-lint` and the tests of each changed project |
| Live checks stay on the container side | They run on Beast's container places, which normal placement skips. Beast's task VMs keep their work |
| VM hosts prove themselves after the merge | The next release refreshes every VM host. A failed refresh keeps the host's base, and the PR is reverted |
| Docs move with the code | A PR moves the part it builds from [Task containers](/reference/task-containers) into the owning page |

## Build PRs

The PRs land in this order. PR 5 can run beside PR 4.

### PR 1: the container setting and its records

This PR changes the Gateway, the CLI, the PHP SDK and the `node:update` command. No workflow reads the new fields yet, and nothing creates a container. `node:update` had no cases file, so the spec commit adds `swamp/proof/node/update.cases.yaml` with the new member and its refusals, and gives each branch of its chart a path id.

- `settings.task_vms` gets the optional member `containers` with `task_places`, `ci_places`, `project`, `network` and `cidr`.
- `config/task_vms.php` `host_defaults.containers` holds the container defaults. `TaskVmHost` validates the member, and the settings data stores it.
- `containers` uses another project and bridge name than the host's VMs.
- Its `cidr` overlaps neither the VPN subnet, the host's VM bridge nor any bridge of another host.
- `node:update` refuses a collision with `node.settings_invalid`. Stored settings that break the rule fail with `task_vm.invalid_config`.
- A host with a live task container keeps its `containers` member. A settings update that removes it, or changes its `project`, `network` or `cidr`, fails with HTTP 409 `task_vm.runtime_in_use`.
- A migration adds `runtime` to `task_vms` and `task_vm_image_builds`, default `vm`.

The rest of the Gateway then reads the runtime:

- Capacity counts container places apart from VM places.
- Normal placement skips container places while `task_vms.container_placement` is `false`, the default.
- Placement reads the recorded base per host and runtime. A row records the runtime it starts with.
- A base refresh takes a VM CI place.
- The API, the MCP schema, the web types, the SDK and `node:update --task-vms` accept `containers`.

Tests: Pest for the validation, the defaults, both refusals, the cross-host overlap, separate capacity, and placement by runtime and by `container_placement`.

The first plan also put the refresh per runtime, `runtime` and `slot.tunnel_unit` in every intent, and the container bridge in the agent's egress watch list into this PR. They moved: nothing reads them before a container host is prepared and a container starts. The egress bridge goes to PR 2 and the rest to PR 3.

### PR 2: prepare a container host

This PR adds the container branch of the `host-prepare` phase.

- `tasks/task-vm-host-prepare.yml` includes `tasks/task-container-host-prepare.yml` on a container host.
- The new file sets up the project, its restrictions with `restricted.containers.lowlevel=allow` and `limits.virtual-machines=0`, the stock container alias, the kernel modules and the sysctls.
- It caps `zfs_arc_max` to the host's size and installs Incus from the channel that the recipe pins.
- It checks the host's subordinate ID range, that the pool is ZFS, and that `sctp` cannot load.
- It copies a small isolated test container and requires `zfs get written` under a few MB. Otherwise every copy would shift its files and stop being a clone.
- The recipe pins one Incus channel for the host and the inner Incus. Beast runs 7.0.1, and a fresh host would get 7.6.

The Gateway and the egress log get these parts:

- The egress log part runs on the container bridge unchanged. Only its input names the bridge.
- The Gateway's refresh intent names the container project, bridge and alias.
- `AgentWatchListAction::egress()` names the container bridge too, and the agent's egress fact covers both bridges. The watch list names one bridge today. This changes its contract with the agent.
- A change of the container bridge withdraws its DHCP and DNS input rules, as a change of the VM bridge does.

Tests: Molecule `unit` cases for the modules and sysctl templates, `ansible-lint`, and Pest for the refresh intent. Live: the container phase twice on Beast, the second time with no change. Beast's VM project, bridge and rules stay unchanged.

### PR 3: the base container, the start and the release

The candidate proof of a refresh is a start and a release, so these parts land together.

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
- **Device check.** The start refuses a copy with a device beyond its root disk and NIC, or with a `raw.*` key.
- **Release.** `incus delete --force`. The readiness step reads `slot.tunnel_unit`.
- **Gateway.** Refresh admission for container hosts, and the start and release intents.

The refresh and the intents learn the runtime:

- **Refresh per runtime.** The refresh admits one run per host and runtime, each with its own queue. It records the runtime on its build row.
- **Container candidate.** The candidate proof of a container refresh takes a container CI place.
- **Intents.** Every task VM intent carries `runtime`. The provision intent also carries `slot.tunnel_unit`, which is `wg-quick@orbit.service` on a VM. A Pest snapshot test per intent shows that a VM intent gains only these two fields.

The other parts:

- **Doctor.** The stray report covers the container project.
- **Power.** The agent's `incus.vm-state` reads only VMs. The agent only observes and gets no new command, so a container's presence comes from `incus.instances`.
- **Running state.** The container's own agent heartbeat gives its running state.

If the heartbeat is not exact enough for power, the PR stops and asks for a decision before it adds an agent read.

Where a shared unit renderer with `LoadCredential` fails in the nested fleet, the PR changes that renderer to pass a file that only the unit's user reads, for every Node. Renderers that work stay as they are. If `wg-quick` does not run two levels down with the `bootcmd` and the AppArmor device, the PR stops and asks for a decision.

Tests: Pest for the intents, their VM snapshots, the refresh per runtime and the stray report, Molecule `unit` cases for the two WireGuard units and the identity files, `swamp workflow validate`, the shape gate and the behavior-name lint.

Live, on Beast's container side:

1. The container refresh of Beast from stock promotes a base. Its candidate proof is one start, its ready check and its release.
2. A second refresh is a no-op apart from the snapshot.
3. The PR records the times of the copy, the start, the refresh and the stop of the nested fleet.
4. `zfs get written` on a fresh copy shows almost no data, so the copy is a clone and Incus shifted no files.
5. Inside a started container, `incus config show` lists no extra device, and no unit has `LoadCredential`.
6. Each nested container shows `N` at `/sys/module/apparmor/parameters/enabled`, and the hub handshake is recent.

### PR 4: proofs on a container

This PR makes the proof flows work on a container and lets the parity gate choose a host. It changes the `proof:run` command, so its spec commit updates `docs/cli/proof/run.mmd`, `run.mdx` and the `proof:run` cases.

- The step "Take base again" of `proof-fleet-verify` stops the nested containers before the snapshot on a container.
- `proof.verify` and `proof.command` pass the runtime to their children.
- `proof:run` gets the operator options `--host=<node>` and `--runtime=<vm|container>`. They pin every shard to the task places of one runtime on one host.
- `--host` with `--runtime=container` reaches container places while `container_placement` is off. A `ci-runner` caller cannot use either option.

Tests: Pest for the admission of `--host` and `--runtime` (operator only, an active configured host, a usable base of that runtime) and for the proof intents, and `swamp workflow validate`.

Live: `orbit proof firewall:allow --group <group>` on a group in a container on Beast. The PR records the restore times. The UpCloud spike measured 3–4 s, so the limit should hold. If the 95th percentile is over 45 seconds, the limit becomes that percentile plus a margin, and the PR says which.

### PR 5: start a stopped container again

This PR covers a `ready` container that stopped, for example after a host reboot.

- The resume admission runs only mode `converge` for a container row. A container is never parked.
- The step "Wait for the guest agent" runs `incus exec … systemctl is-system-running --wait` on a container.

Tests: Pest for the admission by runtime, and `swamp workflow validate`. Live: stop a `ready` container on Beast with `incus stop`, start a workspace operation, and see the container run again.

## Parity gate

Step 4 of ADR 0250 passes when the command proof cases give the same verdicts on task containers as on task VMs. Both halves run on Beast. The VM half takes Beast's VM task places, so it runs in a window agreed with the operations migration thread.

1. Pick one commit of `main` after PR 5.
2. Run `proof:run --all --host=beast --runtime=container` for that commit.
3. Run `proof:run --all --host=beast --runtime=vm` for the same commit.
4. Compare the verdicts per case. Every case that passes on a VM must pass on a container.
5. A case that fails on both runtimes is a case defect. It does not block the gate.
6. Run one real task group in a container on Beast: a start, a turn, a handoff check and a publish. Then release it.
7. Record the case table on this page. Add the times of a start, a restore and a refresh on both runtimes.
8. Record the peak memory of a container place. It sets the container place counts.

A case that fails only on a container blocks the gate. The whole run passes again after the fix. After the gate, step 5 [switches](/reference/task-containers#switch-a-host) Beast, then Shark, in a window with no proofs or merges in flight. An UpCloud host passes the same gate before it gets container placement.

## UpCloud report

A spike on 2026-11-09 ran this runtime on an UpCloud server, which has no nested KVM. Verdict: it works with changes. UpCloud is a verified container host and a target: an UpCloud host passes the same parity gate before it gets container placement.

The spike used `STARTER-4xCPU-8GB` with a separate 20 GB block device for the pool. It ran the outer container, the inner Incus on a directory pool, Docker and WireGuard in nested containers, and the stop, snapshot and copy of the base.

| Step | Time |
| --- | --- |
| Outer snapshot, stopped | 0.15 s |
| Outer copy | 0.57 s |
| Copy start to nested systemd ready | 5.3 s |
| Take nested `base` (stop, snapshot, start) | 21.4 s |
| Nested restore, 500 MB changed / small change | 3.9 s / 2.9 s |

The findings changed this plan and the [reference](/reference/task-containers):

| Finding | Change |
| --- | --- |
| A restricted project refuses `security.idmap.size` | `restricted.containers.lowlevel=allow`, and the start refuses `raw.*` keys |
| Incus 7 reports empty `kernel_features` | A test copy with `zfs get written` under a few MB replaces the `idmapped_mounts` check. The copy wrote 300 KB |
| `wg-quick` segfaults in the outer container | `ip` and `wg` only for the outer container. Nested Nodes keep `wg-quick`, which works with the AppArmor-off device |
| `LoadCredential` gives the unit no credential and no error | The rule stays: `SetCredential` or a file |
| The template kernel lags and SCTP loads | Host preparation needs a full upgrade and reboot, the SCTP block, a `zfs_arc_max` cap and, on UpCloud, a separate pool device |
| Zabbly stable is 7.6, Beast runs 7.0.1 | The recipe pins one Incus channel for host and inner Incus |
| A directory pool copied the base in 55 s, btrfs moved it in 171 s | The host pool must be ZFS |
| Nested restores took 3–4 s | The 45-second limit holds |
| `incus` reads non-terminal standard input as YAML | Ansible tasks and Swamp scripts close standard input |

An idle task container with two nested containers and Docker used about 0.5 GB, and the base used 2.5 GB of disk. The real fleet is heavier, so one task group gets `STARTER-4xCPU-16GB` with a 50 GB pool disk until the parity gate measures a place. An 8 GB server fits one group only with the ARC capped near 1 GiB.

## Decided

These decisions were made on 2026-11-09. The PRs build on them without asking again.

| Decision | What it means |
| --- | --- |
| A task container keeps the task VM's Node identity until step 6 | The workspace, Pi, Git, the guards and every Swamp step work unchanged. [ADR 0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers#one-runtime-kind-the-container) records it as a temporary exception, and step 6 removes it |
| Beast is the container test host | The build PRs and the parity gate run on Beast's container places. Normal placement skips them until the switch, so Beast's task VMs keep their work |
| UpCloud must pass the same gate | It is a verified container host and a target, not the test host. The [UpCloud report](#upcloud-report) holds the spike's findings |
| Nested Nodes keep `wg-quick` | The fleet build's `bootcmd` turns off the `wg` and `wg-quick` profiles, and each nested container gets the AppArmor-off device. The shared Node converge does not change |
| Container places get the VM place counts until the parity gate | The gate measures a place's peak memory, and the counts follow from it |
| A slow restore raises the limit | If PR 4 measures a restore over 45 seconds, the limit becomes the measured 95th percentile plus a margin. The UpCloud spike measured 3–4 s |
| `LoadCredential` becomes a file only where it fails | PR 3 changes a shared renderer to a file that only the unit's user reads, for every Node, only where it finds one that fails in the nested fleet |
| Names stay until step 7 | `settings.task_vms`, `HOST-tvm-N`, the `task_vm.*` kinds and codes, and the VM step names. Step 7 renames them once |
