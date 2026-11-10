---
title: "ADR 0253: Patch Nodes and reboot them drained, one at a time"
sidebarTitle: "0253 Patch and reboot Nodes"
description: "In progress. Every managed Ubuntu Node installs security updates through unattended-upgrades without rebooting itself. A nightly fleet.reboot pass in Swamp drains each due Node, reboots it and checks that it came back, one Node at a time. A system shutdown never waits on a long unit drain."
---

# ADR 0253: Patch Nodes and reboot them drained, one at a time

Every managed Ubuntu Node installs Ubuntu security updates on its own and never reboots on its own. When an update needs a reboot, a nightly Swamp pass reboots the due Nodes one at a time: it drains each Node's work, reboots it and checks that it came back. A system shutdown itself never waits on a long unit drain; the draining happens before it, while the network is up.

## Status

In progress.

Nick decided the four open points on 2026-11-09, each as recommended:

1. `app-prod`, the `vpn` hub, the Gateway and Node 209 reboot automatically in the window.
2. The window is 03:00 to 06:00 Europe/Amsterdam, shared with the fleet rollout.
3. The drain budget is 15 minutes. Work on the control path that is still running then is cut, and its caller starts it again.
4. A busy task or CI host is deferred, never cut. An overdue alert follows after 3 days.

An independent review of the first draft changed the shape: one Swamp pass like the fleet rollout, draining with the tools on each Node, and no Gateway hold on Swamp. The detailed behaviour is in [Fleet reboot](/reference/gateway-recovery#fleet-reboot), [Update policy](/reference/node-provisioning#update-policy) and [Shutdown never waits](/reference/node-provisioning#shutdown-never-waits).

This is requirement 1, "Patch", of ADR 0250 (PR #3266). It builds on the reboot fixes of PR #3269: the kernel policy, `/run/orbit` at boot and Horizon's shutdown-only stop.

Principle: [Deterministic first](/mission#principles) and [One way, one name](/mission#principles).

## Context

On 2026-11-09 every managed Node was rebooted by hand into kernel 7.0.0-43. Three faults showed up; PR #3269 fixes them. One more fault class stays: a systemd unit with a long stop timeout holds the whole shutdown.

- Horizon held the Gateway for 16 minutes. PR #3269 adds an `ExecStop` that kills Horizon at once, but only while `systemctl is-system-running` reports `stopping`. systemd runs `ExecStop` before it sends the kill signal, so a shutdown no longer waits, and a normal restart, such as a release, still drains.
- The Swamp workers on Node 209 have `TimeoutStopSec=4500` ([ADR 0243](/decisions/0243-serve-swamp-through-a-swamp-role)). A reboot of Node 209 waited up to 75 minutes for them. Serve adds up to 6 minutes.

The long drains are right for rolling restarts: a Gateway release drains Horizon while the old release finishes its jobs, and a worker renewal drains one worker while two others accept work. They are wrong for a shutdown: the network goes down in the middle, so the work they wait for cannot finish anyway.

Ubuntu marks a needed reboot in `/var/run/reboot-required`. Package scripts write it for a new kernel, `libc6`, `systemd`, `dbus`, firmware and microcode. [ADR 0247](/decisions/0247-keep-fleet-software-current-through-tested-recipe-changes) already decided that Ubuntu `-security` updates install through `unattended-upgrades`, while recipe packages stay held at their pins.

After an unattended library update, Ubuntu's `needrestart` restarts the services that use the old library. A `libssl` update in the afternoon would restart Swamp serve and the workers, Horizon, Docker and Incus.

## Decision

Updates install on their own. Reboots run in one nightly Swamp pass that the Gateway admits, as the [fleet rollout](/reference/gateway-recovery#fleet-rollout) does.

### Updates

`node:add` and `node:converge` apply one update policy that the Gateway renders, as they apply the kernel policy:

- `unattended-upgrades` installs from the Ubuntu `-security` pocket every day, with `Automatic-Reboot "false"`.
- `needrestart` restarts services automatically, except the protected units: Swamp, the Gateway's scheduler and Horizon, Orbit Processes, `orbit-agent`, Docker, containerd, Incus and WireGuard. Their restart drains for a long time or cuts the fleet's work.
- After each `dpkg` run, a hook writes `/var/run/reboot-required` when a protected unit runs an old library. So that one file is the only signal that a Node is due.
- A Node of a task VM's nested fleet keeps the daily timers off, so a proof cannot change under an upgrade.

### The pass

- **Admission.** The Gateway admits one `fleet.reboot` Operation every day at 03:06 Europe/Amsterdam, and when an operator calls `POST /api/v1/fleet/reboot`. The pass, a fleet rollout and a Gateway release refuse each other, so they never overlap.
- **Nodes.** Every managed Linux Node except task VMs, one at a time, in the rollout's risk order, then the control path (the `vpn` hub, the Gateway, the Node that serves `swamp.orbit`), then Node 209.
- **Due.** Swamp reads `/var/run/reboot-required` on each Node. A Node that is not due ends `not_due` at once. The agent is not changed.
- **Phases.** Each Node runs `drain`, `reboot` and `check` as Ansible phases from Node 209. No step calls the Gateway.
- **Failure.** A failed Node halts the pass and raises `reboot_halted`; the Nodes after it read that from the run folder. A failed drain first starts what it stopped. A deferred Node does not hold back the others.

### Drain

The drain stops new work for the Node, waits for work in flight for at most 15 minutes, and then reboots. Work that is not safe to cut never reaches the cut:

- **Task work.** A task host with an open task Operation is only read and deferred. On every other task host in the pass, the Gateway refuses task calls and proofs with `409 tasks.host_rebooting`, places no new task VM and starts no image refresh while the pass runs. The drain stops the remaining task guests, and the Gateway records them parked.
- **CI jobs.** The drain freezes each runner container while it reads its processes, stops it when it runs no job, and lets it run on otherwise. When a job still runs at the end of the budget, the stopped runners start again and the Node is deferred.
- **A CI job taken at the freeze.** A runner can accept a job in the instant before the drain freezes it; that job is lost when the runner stops. The drain records when it froze each runner it stopped. After the Node's reboot is confirmed, the pass finds the jobs that such a runner was still running at its freeze and that then failed. It re-runs the failed jobs of each such run once, with GitHub's re-run-failed-jobs API, and only while the run is still at the attempt that lost the job. A cancelled run, such as one a newer push superseded, is never re-run. It uses an `actions: write` token from the App's existing `Actions: write` permission. Runner administration stays out of the App, as [ADR 0223](/decisions/0223-run-one-gate-from-handoff-to-merge) decided. The Node's result names the runs it re-ran.
- **Gateway release and fleet rollout.** They and the pass refuse each other.
- **The Gateway's own jobs.** The drain stops the scheduler and drains Horizon within the budget. A job still running then is cut, and Laravel runs it again after `retry_after`.
- **Node 209.** It reboots itself after the pass ends. A transient path unit waits for the pass's cleanup step, then stops serve and the workers with their own drains, within the budget, and reboots. The Gateway confirms the new boot from the agent's uptime and raises `reboot_unconfirmed` after 30 minutes without one.

When a reboot of the control path cuts a Swamp run, the run ends `interrupted`. The Gateway's reconciler records that Operation `interrupted` with its failed step, as for any lost run. Terminal Operations never reopen. The caller starts a new Operation: the next rollout pass, the next automatic deploy or the task orchestrator. Every machine phase is an idempotent playbook, so the new Operation repeats the cut phase safely.

### Shutdown never waits

One rule for every Orbit unit with a long drain: while the system is stopping, an `ExecStop` guard kills the unit's processes at once. Otherwise the unit drains as configured. `ShutdownFastStop` holds the guard; Horizon, the Swamp workers and serve use it, and each keeps its own kill settings.

### What is reported

The `fleet.reboot` Operation result holds each Node's outcome: `rebooted`, `scheduled` (Node 209), `not_due`, `deferred` with its reason, `skipped`, or `failed` with its step and error code. Doctor reports `node.reboot_due` for a Node due for more than 3 days, and the Gateway raises `reboot_overdue` once.

### Open points

- The proof runner that runs on a host itself, outside a container, can still take a job during the drain. Its job defers the host, because the drain cannot stop that runner first.
- No proof case covers the pass. Proof Nodes are containers that share their task VM's kernel and have no `/boot`, so a reboot proof needs a VM Node.

## Rejected alternatives

- **`Automatic-Reboot "true"` in `unattended-upgrades`.** No drain, no order, no check, and every Node reboots at the same minute.
- **One `node.reboot` Operation per Node, sequenced by a Gateway tick.** It puts order and windows in the Gateway, which the rollout gives to Swamp, and Node target holds do not cover deploys or task work anyway.
- **A Gateway hold on Swamp run starts while 209 reboots.** Gateway execution state; it also cannot stop serve's own schedules.
- **New `host` fact fields in the agent.** The agent stays frozen; the pass reads the Node itself, and the Gateway reads uptime from the existing `metrics` fact.
- **Livepatch.** Needs Ubuntu Pro tokens on every Node and patches only selected kernel functions; a reboot is still needed for every other fix.
- **Lower `TimeoutStopSec` for every unit.** Breaks the rolling-restart drains that a release and worker renewal need.
- **`needrestart` in list-only mode.** Library fixes would wait for the next kernel reboot.

## Consequences

- Kernel, `libc6` and library fixes reach every managed Node within a night or two, without a hand reboot.
- A reboot of any Node, planned or not, no longer waits on a unit drain.
- Production and the control path have one short outage per kernel update, at night.
- A busy task or CI host can stay unpatched for days; Doctor and the alert show it.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: [0247](/decisions/0247-keep-fleet-software-current-through-tested-recipe-changes) (updates), 0250 (PR #3266, requirement 1), [0243](/decisions/0243-serve-swamp-through-a-swamp-role) (worker units)
- Detail: [Node provisioning](/reference/node-provisioning) (update policy, shutdown guard), [Update and recover a Gateway](/reference/gateway-recovery#fleet-reboot) (the pass, window, exclusion, alerts), [Tasks](/reference/tasks) (`tasks.host_rebooting`), and the chart `docs/cli/fleet/reboot.mmd`. The change also touches `swamp/` (the pass, its child and the Ansible phases).
- Verify: the Gateway and Swamp tests of this PR; a converged Node has the update policy and no automatic reboot; the first nightly passes on the real fleet.
