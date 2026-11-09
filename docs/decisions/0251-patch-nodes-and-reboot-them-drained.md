---
title: "ADR 0251: Patch Nodes and reboot them drained, one at a time"
sidebarTitle: "0251 Patch and reboot Nodes"
description: "In progress. Every managed Ubuntu Node installs security updates through unattended-upgrades without rebooting itself. A nightly fleet.reboot pass in Swamp drains each due Node, reboots it and checks that it came back, one Node at a time. A system shutdown never waits on a long unit drain."
---

# ADR 0251: Patch Nodes and reboot them drained, one at a time

Every managed Ubuntu Node installs Ubuntu security updates on its own and never reboots on its own. When an update needs a reboot, a nightly Swamp pass reboots the due Nodes one at a time: it drains each Node's work, reboots it and checks that it came back. A system shutdown itself never waits on a long unit drain; the draining happens before it, while the network is up.

## Status

In progress.

Proposed on 2026-11-09. Nick decides the points under [Open decisions for Nick](#open-decisions-for-nick). The build starts after them. An independent review of the first draft changed the shape: one Swamp pass like the fleet rollout, draining with Swamp's own tools, and no Gateway holds.

This is requirement 1, "Patch", of ADR 0250 (PR #3266, not merged). It builds on the reboot fixes of PR #3269 (the kernel policy, `/run/orbit` at boot, Horizon's shutdown-only fast stop) and does not repeat them.

Principle: [Deterministic first](/mission#principles) and [One way, one name](/mission#principles).

## Context

On 2026-11-09 every managed Node was rebooted by hand into kernel 7.0.0-43. Three faults showed up; PR #3269 fixes them. One more fault class stays: a systemd unit with a long stop timeout holds the whole shutdown.

- Horizon held the Gateway for 16 minutes. PR #3269 adds an `ExecStop` that kills Horizon at once, but only while `systemctl is-system-running` reports `stopping`. systemd runs `ExecStop` before it sends the kill signal, so a shutdown no longer waits, and a normal restart, such as a release, still drains.
- The Swamp workers on Node 209 have `TimeoutStopSec=4500` and `KillMode=mixed` ([ADR 0243](/decisions/0243-serve-swamp-through-a-swamp-role)). A reboot of Node 209 can wait up to 75 minutes. Serve adds up to 6 minutes (`TimeoutStopSec=360`, `--shutdown-drain-timeout 5m`).

The long drains are right for rolling restarts: a Gateway release drains Horizon while the old release finishes its jobs, and a worker renewal drains one worker while two others accept work. They are wrong for a shutdown: the network goes down in the middle, so the work they wait for cannot finish anyway.

Ubuntu marks a needed reboot in `/var/run/reboot-required`. Package scripts write it for a new kernel, `libc6`, `systemd`, `dbus`, firmware and microcode. [ADR 0247](/decisions/0247-keep-fleet-software-current-through-tested-recipe-changes) already decided that Ubuntu `-security` updates install through `unattended-upgrades`, while recipe packages stay held at their pins.

After an unattended library update, Ubuntu's `needrestart` restarts the services that use the old library. Orbit's own apt calls set `NEEDRESTART_MODE=l` (list only), but the unattended run does not. A `libssl` update in the afternoon would restart Swamp serve and the workers (a 75-minute drain), Horizon, Docker and Incus.

## Decision

Updates install on their own. Reboots run in one nightly Swamp pass that the Gateway admits, as the [fleet rollout](/reference/gateway-recovery#fleet-rollout) does. The sections below fix each part.

### Updates

`node:add` (phase `baseline`) and `node:converge` (phase `agent`) apply one update policy that the Gateway renders, the same way as the kernel policy of PR #3269:

| File | Content |
| --- | --- |
| `/etc/apt/apt.conf.d/20auto-upgrades` | Daily package lists and daily unattended upgrade |
| `/etc/apt/apt.conf.d/52orbit-unattended-upgrades` | Origin `${distro_id}:${distro_codename}-security` only, `Automatic-Reboot "false"`, `Remove-Unused-Kernel-Packages "true"` |
| `/etc/needrestart/conf.d/50-orbit.conf` | Automatic restarts, except the [protected units](#protected-units) |
| `/etc/apt/apt.conf.d/53orbit-reboot-due` | A `DPkg::Post-Invoke` hook: when `needrestart -b` lists a protected unit, it adds that unit to `/var/run/reboot-required.pkgs` and writes `/var/run/reboot-required` |

- The phase installs `unattended-upgrades` and enables `apt-daily.timer` and `apt-daily-upgrade.timer`.
- Held recipe packages stay held; `unattended-upgrades` skips held packages.
- Task VMs keep updates off, as today (`task-vm-boot`). They live for one task group.
- macOS Nodes are out of scope.

#### Protected units

These units are not restarted by `needrestart`, because a restart drains for a long time or cuts the fleet's work: Swamp serve and workers, Horizon and the Gateway scheduler, the Gateway's FrankenPHP, `docker`, `incus`, `wg-quick@*` and `orbit-agent`. A protected unit that runs an old library makes the Node due, so the next reboot replaces the library. Every other service restarts as Ubuntu does, and its fix applies the same day.

### When a reboot is due

A Node is due when `/var/run/reboot-required` exists. With the hook above, that one file covers kernels, `libc6` and the protected units. Orbit keeps no package list of its own.

The agent reports two more fields in its `host` fact: `reboot_required` (whether the file exists) and `boot_id` (`/proc/sys/kernel/random/boot_id`). The Gateway plans a pass from these facts and visits no Node that is not due.

### The pass

A pass is one `fleet.reboot` Operation and its Swamp run, `operation-fleet-reboot`. It belongs to Node 209, as a rollout does.

- **When.** The Gateway admits it every day at 03:05 Europe/Amsterdam, after the rollout admission, and when an operator runs `orbit fleet:reboot [NODE...]`. It admits nothing when no Node is due.
- **Exclusion.** A pass waits while a `fleet.rollout` or a Gateway release runs, the same way a rollout admission waits. Rollout admission and release admission wait while a pass runs. So the three never overlap.
- **Shape.** One native DAG with concurrency 1. Each Node runs the child `fleet-reboot-node`: a native `gate` step (window open, every earlier Node `rebooted`, `deferred` or `not_due`), then one Ansible phase per part: `drain`, `reboot`, `check`. No step calls the Gateway.
- **Result.** Each Node ends `rebooted` (old and new kernel, drain and reboot seconds), `not_due`, `deferred` with a reason (`window`, `tasks_busy`, `ci_busy`), or `failed` with the step and error code.
- **Failure.** At the first `failed` Node, the pass visits no Node after it in the order. The Gateway raises `reboot_halted` once per Node and error code, as `rollout_halted` does. The next pass starts again at that Node.
- **Deferral.** A `deferred` Node does not hold back the Nodes after it, and the pass does not visit it again that night.

### Order

The pass uses the rollout's [risk groups](/reference/gateway-recovery#rollout-set-and-order), then two more for the Nodes that every in-flight run depends on:

| Group | Nodes |
| --- | --- |
| 1 | `app-dev`, and managed Linux Nodes without roles |
| 2 | `ci-runner`, `metrics`, `analytics` |
| 3 | `database`, `websocket`, `router` |
| 4 | `app-prod`, `ingress` |
| 5 | Control path: the `vpn` hub, the Gateway Node, and the Node that serves `swamp.orbit` |
| 6 | The `swamp` Node (Node 209) |

The set is every `active` Linux Node with a WireGuard address and a pinned SSH host key, except task VMs.

### Window

03:00 inclusive to 06:00 exclusive Europe/Amsterdam, the window the rollout already uses. The gate starts a Node only when its drain budget fits before 06:00; otherwise the Node ends `deferred` with `window`.

### Shutdown never waits

One rule for every Orbit unit with a long drain: while the system is stopping, an `ExecStop` guard kills the unit's processes at once. Otherwise the unit drains as configured.

- One shared renderer returns only the guard line. Each unit keeps its own kill settings: Horizon its `KillMode=mixed` and `SendSIGKILL=no`, the workers their `KillMode=mixed` and 4,500 seconds, serve its default `KillMode` and 360 seconds.
- `GatewayHorizon::stopDirectives()`, the Swamp worker template and the serve unit use it.
- A test renders each unit and asserts the guard.

So a reboot, planned or not, stops these units in seconds. The work that ran in them is cut. The pass drains first, so the cut is rare and bounded.

### Drain

The `drain` phase stops new work for the Node with the tools on the Node, waits for in-flight work up to the drain budget, and then reboots. A cut is accepted only for work that [survives it](#work-that-is-cut). Default drain budget: 15 minutes.

| Node | Stop new work | Wait for | At the budget |
| --- | --- | --- | --- |
| Task host (task VMs today, task containers after ADR 0250) | Stop each running task guest that runs no agent turn or check | Guests with a running turn or check | Defer the Node, and start nothing it stopped |
| CI runner host | Stop each runner container that runs no job (no `Runner.Worker` process) | Running jobs | Start the stopped runners again and defer the Node |
| `app-prod` | Nothing; one Node at a time is the only rule | Nothing | Not reached |
| Gateway | Stop the scheduler; pause Horizon with the drain a release uses | Horizon jobs | Reboot |
| Control path (group 5) | Nothing | Swamp runs other than the pass itself, read with the Swamp CLI on 209 | Reboot |
| Swamp (209) | See [Node 209](#node-209) | Runs in flight | Reboot |

Today Orbit cannot pause CI dispatch for one host ([compute drivers](/reference/compute-drivers)). A job that a runner picks up between the check and the stop fails, and GitHub shows it. The build adds a pause for one host if the blocking rule asks for one.

The pass result names the task guests that `drain` stopped. The Gateway records them `parked`, so the next task call wakes them, as [`TaskVmAwake`](/reference/tasks) does today. Runner containers have Incus autostart and come back with the boot.

### Work that is cut

| Work | How it survives a cut |
| --- | --- |
| A Swamp step that a control-path or 209 reboot cuts | Its run ends interrupted. A machine phase is an idempotent playbook, so `swamp workflow resume` or a new run repeats it from its start |
| A Laravel job on the Gateway | Horizon retries it after `retry_after` (1,800 seconds) |
| A Pi turn | Not cut: the task host defers instead. A turn whose watching step on 209 is cut keeps running in its guest, and Pi Durable keeps its state |
| An `instance.deploy` on `app-prod` | It fails; the previous release stays live, because the release switch is one link change and PostgreSQL migrations are transactions. The next deploy repeats it |

The build verifies that the Gateway's path for an interrupted Operation either resumes or fails each cut Operation visibly.

Work that is not safe to cut, and how the pass avoids it:

- **A Gateway release.** Release admission and the pass exclude each other.
- **A fleet rollout.** The same exclusion.
- **A running task turn or check.** The task host waits, then defers.
- **A CI job.** The runner host waits, then defers.
- **A base snapshot or a task image refresh.** It runs on a task host as a task guest, so the task host defers. On 209 it is a long Swamp step; see [decision 3](#open-decisions-for-nick).

### Node 209

A Swamp run cannot wait across a reboot of its own serve. The `reboot` phase of 209 is the last step of the pass. It starts a transient systemd unit and returns. The unit waits until the pass run has ended, then:

1. stops serve, which drains in-flight runs for up to 5 minutes (`--shutdown-drain-timeout`);
2. stops the workers with `SIGTERM`, which lets them finish active steps, bounded by the drain budget;
3. reboots.

The Node ends `rebooted` in the pass result with `confirmed: false` and the old `boot_id`. While serve is down, the Gateway's starts of new runs fail as they do for any serve outage. The Gateway raises `reboot_unconfirmed` when the agent of 209 has not reported a new `boot_id` within 30 minutes of the pass's end. The next pass runs the `check` phase on 209 first and records its result.

### Checks

The `check` phase waits up to 10 minutes for SSH and checks:

1. a new `boot_id`;
2. no `/var/run/reboot-required`, and the running kernel is the newest installed one;
3. `systemctl is-system-running --wait`: `running`, or `degraded` with no failed unit that was not failed before the reboot (`drain` records that set);
4. `orbit-agent` is active;
5. on the Gateway Node, the Gateway health URL answers and Horizon runs.

A failed check fails the Node with the check and the units. The Gateway raises `reboot_halted`. A Node that never answers SSH fails the same way; its recovery needs the provider console.

### What is reported

- The `fleet.reboot` Operation result, per Node, as above. `operation:list --kind=fleet.reboot` and `operation:show` read it.
- Doctor reports `node.reboot_due` when a Node's `host` fact has shown `reboot_required` for more than 3 days.
- Alerts: `reboot_halted`, `reboot_unconfirmed`, and `reboot_overdue` once when `node.reboot_due` first appears for a Node.

### Open decisions for Nick

1. **Automatic reboots of production and the control path.** `app-prod` (one Node; its Routes go down for 1 to 3 minutes), the `vpn` hub (every WireGuard path drops for about a minute), the Gateway and Node 209. Recommendation: yes, all of them, in the window. There are no users, and a held-back Node keeps the kernel risk that ADR 0250 accepts only with patching. The alternative is `node.reboot_due` plus a manual `orbit fleet:reboot NODE` for these four.
2. **Window.** Recommendation: reuse the rollout window, 03:00 to 06:00 Europe/Amsterdam.
3. **Drain budget.** Recommendation: 15 minutes, and cut what is left on the control path and on 209. Task turns (budget 3,600 seconds) and task prep (6,300 seconds) can run longer; their guests keep running, but the Swamp step that watches them is cut and must resume. The alternative is to defer 209 while a run with a longer budget is in flight, which defers 209 on most nights.
4. **Busy task and CI hosts.** Recommendation: defer, never cut, and raise `reboot_overdue` after 3 days due. The alternative is to cut after a number of deferred nights, which fails a turn or a CI job but guarantees the patch.

## Rejected alternatives

- **`Automatic-Reboot "true"` in `unattended-upgrades`.** No drain, no order, no check, and every Node reboots at the same minute.
- **One `node.reboot` Operation per Node, sequenced by a Gateway tick.** It would get Gateway target holds, but it puts order and windows in the Gateway, which the rollout gives to Swamp, and target holds do not cover deploys or task work anyway (they hold Instances and task groups, not Nodes).
- **A Gateway hold on Swamp run starts while 209 reboots.** Gateway execution state; it also cannot stop serve's own schedules.
- **Livepatch.** Needs Ubuntu Pro tokens on every Node and patches only selected kernel functions; a reboot is still needed for every other fix.
- **Lower `TimeoutStopSec` for every unit.** Breaks the rolling-restart drains that a release and worker renewal need.
- **`needrestart` in list-only mode.** Library fixes would wait for the next kernel reboot.

## Consequences

- Kernel, `libc6` and library fixes reach every managed Node within a night or two, without a hand reboot.
- A reboot of any Node, planned or not, no longer waits on a unit drain.
- Production and the control path have one short outage per kernel update, at night.
- A busy task or CI host can stay unpatched for days; the alert shows it.

## Affects

- Components: apps/gateway, apps/cli, apps/docs
- ADRs: [0247](/decisions/0247-keep-fleet-software-current-through-tested-recipe-changes) (updates), 0250 (PR #3266, requirement 1), [0243](/decisions/0243-serve-swamp-through-a-swamp-role) (worker units)
- Detail: [Node provisioning](/reference/node-provisioning) (update policy, shutdown guard, worker and serve units), [Update and recover a Gateway](/reference/gateway-recovery) (the pass, window, exclusion, alerts), [Tasks](/reference/tasks) (parked guests), [Node agent](/reference/node-agent) (`reboot_required` and `boot_id` in the `host` fact), a new command page and chart `docs/cli/fleet/reboot`, and its proof cases. The change also touches `swamp/` (the pass, its child and the Ansible phases) and `apps/agent`.
- Verify: a converged Node has the update policy, the `needrestart` exclusions and no automatic reboot; each long-drain unit renders the shutdown guard; a pass on a nested fleet drains, reboots and checks a due Node and skips one that is not due; a busy task host and a busy CI host end `deferred`.
