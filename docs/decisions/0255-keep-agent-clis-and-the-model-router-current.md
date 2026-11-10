---
title: "ADR 0255: Keep the agent CLIs and the model router current"
sidebarTitle: "0255 Agent tool updates"
description: "In progress. A daily fleet.agent_tools run in Swamp updates the agent CLIs on every T3 host with each host's own install method, and brings the model router to the latest CLIProxyAPI release with a checked restart that flips back on failure. It reverses ADR 0214's rule that Orbit never upgrades the router by itself."
---

# ADR 0255: Keep the agent CLIs and the model router current

Once a day, Swamp brings the agent CLIs on each T3 host and the model router to their latest release. Each host keeps the install method it has. The router installs the new release beside the old one, restarts, and must answer one real request; otherwise it goes back to the release before.

## Status

In progress.

Nick approved the daily update on 2026-11-10. The detailed behaviour is in [Agent tool updates](/reference/gateway-recovery#agent-tool-updates) and [Upgrade the router](/reference/ai-router#upgrade-the-router).

Principle: [Agents operate, humans steer](/mission#principles).

## Context

CLIProxyAPI stamps its own `claude-cli/<version>` User-Agent on Claude requests. An old router makes every new Claude model fail with "Claude Code 2.1.x does not support this model". On 2026-11-10 the router on `services` was upgraded by hand from 8.0.17 to 8.0.63 to fix that. The agent CLIs that T3 starts on beast and shark (Claude Code, Codex, Grok, OpenCode, the Cursor agent, Antigravity) fall behind the same way, and were also updated by hand.

[ADR 0214](/decisions/0214-pin-the-ai-router-version-and-flag-upgrades) chose a pinned router version that only the operator changes. It is not built. The hand upgrades show the cost of that choice: a stale router breaks every agent at once, and nobody chooses the time.

Hosts install the CLIs in different ways. beast and shark have them as Vite+ global packages in different Vite+ homes; beast also has Grok's own install, the Cursor installer and an Antigravity Homebrew cask. Changing a method would move binaries that T3 already finds.

## Decision

A daily `fleet.agent_tools` Operation, admitted by the Gateway at 02:41 Europe/Amsterdam and run by Swamp from Node 209. It follows the [fleet reboot](/decisions/0253-patch-nodes-and-reboot-them-drained): the Gateway records the intent, Swamp runs Ansible phases over SSH, and the Gateway projects the report.

### What it updates

- **T3 hosts.** Every active managed Linux Node whose own T3 server Conn recorded. macOS Nodes stay out: the operator Macs get no automatic CLI updates (decided 2026-11-07). Containers stay out: they run the pinned [shared agent folder](/reference/development-containers#shared-folders). Task VMs stay out.
- **Tools on a host.** What the host has, the way it has it: the configured Vite+ globals in one `vp i -g <package>@latest`, Grok's own `grok update`, the Cursor installer, and the Antigravity Homebrew cask. A running agent session keeps the binary it started; the next one starts the new one.
- **The model router.** The one CLIProxyAPI Process on the AI router's Node, while the AI router is on and set up.

### The router's checked restart

The run installs the latest upstream release beside the others, after it matched `checksums.txt` and reported its version, and flips the `current` link in one rename. It restarts the router through the Process restart workflow, then sends one request for one token. A failed restart or request flips the link back and restarts again. After a pass it keeps the current release and the two newest others.

### One plug-in point

Core owns the kind, the workflow, the schedule and the report. Two extensions fill one plug-in point, `AgentToolsContribution`: Conn names the hosts, and the AI router names the router. A disabled extension contributes nothing.

### Failures

Hosts update at once and beside the router. A failed tool, an unreachable host or a failed router check is recorded in the report and never stops the others. The run and its Operation fail when any part failed; the next day's run tries again.

## Rejected alternatives

- **A native Swamp schedule with fixed inputs.** The hosts, host keys and router come from Gateway state. A recurring job is an Orbit kind, so it shows in Orbit ([Swamp is part of Orbit](/mission#principles)).
- **One Swamp step per tool or per host.** Repeated per-Node work belongs in one Ansible phase.
- **Restarting the router with `systemctl` in the phase.** The Process restart workflow is the one restart path of a Process.
- **One install method everywhere.** It would move binaries T3 already resolves, for no gain.
- **Updating the shared agent folder too.** Its versions are recipe pins, and recipe changes reach Nodes only after the tests of [ADR 0247](/decisions/0247-keep-fleet-software-current-through-tested-recipe-changes).
- **An operator-only router update, as in ADR 0214.** A stale router breaks new models for every agent.

## Consequences

- ADR 0214's pin, release check and `ai-router:update` are not built. Its rule that Orbit never upgrades the router is replaced by this record.
- A router restart drops the model calls in flight once a day at most; Tasks retries them as a [transient provider error](/reference/tasks#execution-ownership).
- These tools are not recipe software, so no command test of [ADR 0247](/decisions/0247-keep-fleet-software-current-through-tested-recipe-changes) gates them. A bad upstream CLI release reaches the hosts within a day; the report shows each tool's version before and after. The router's one-request check is its gate.
- A fleet reboot pass waits for a running update, and an update does not start while a pass runs.

## Affects

The Gateway part spans core Fleet and the Conn and AI router extensions; the Swamp part is one workflow and one playbook under `swamp/`.

- Components: apps/gateway
- ADRs: replaces the "Orbit never upgrades the router" rule of [ADR 0214](/decisions/0214-pin-the-ai-router-version-and-flag-upgrades); follows the shape of [ADR 0253](/decisions/0253-patch-nodes-and-reboot-them-drained).
- Detail: [Agent tool updates](/reference/gateway-recovery#agent-tool-updates), [Upgrade the router](/reference/ai-router#upgrade-the-router), the [`fleet` chart](/cli/fleet), [Plug-in points](/reference/extensions#plug-in-points).
- Verify: the Gateway tests in `tests/Feature/Domain/Fleet/AgentToolsTest.php` and the playbook tests in `swamp/tests/fleet_agent_tools_test.ts`; the first live run's report.
