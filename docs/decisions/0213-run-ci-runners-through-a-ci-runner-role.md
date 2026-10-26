---
title: "ADR 0213: Run CI runners through a ci-runner role"
sidebarTitle: "0213 CI runner role"
description: "In progress. The host role adopts the Incus CI boundary. Gateway-owned runner records and their organization registration credential flow remain pending."
---

# ADR 0213: Run CI runners through a ci-runner role

The `ci-runner` role prepares a Node for GitHub Actions runners in unprivileged Incus containers. The host role is implemented. Gateway-owned runner records, container lifecycle, and registration remain unbuilt and need an agreed credential boundary. This decision stays in progress across those parts.

## Status

In progress.

Amended on 2026-10-21 to separate the built host role from the pending runner lifecycle. The original proposal to register repository runners through the GitHub App does not fit the deployed organization runners or [ADR 0223](/decisions/0223-run-one-gate-from-handoff-to-merge), which keeps Administration write out of the App.

Principle: [Agents operate, humans steer](/mission#principles). The host role follows normal role commands, inspection, and fleet rules. It also serves [Security fits the real threat model](/mission#principles): jobs run without sudo inside unprivileged containers whose egress cannot reach the Gateway, WireGuard, or LAN. No principle exception remains.

## Context

The operator script builds persistent organization runners in the `orbit-ci` Incus project. The Gateway holds no organization registration token. Hosts may serve CI alone or share app and database roles. A recorded recipe profile alone does not make a host a workload Node, so it cannot replace a role.

## Decision

Implement the host role through the existing role lifecycle. Keep the runner-record lifecycle pending until its organization credential flow is agreed.

### Host role: implemented

[Node provisioning](/reference/node-provisioning#ci-runner-role) owns the implemented contract. The role follows normal role add, baseline, managed firewall, Doctor, metrics, and fleet participation. It shares all roles except `gateway` and `vpn`. It is Linux-only, not a singleton or relocatable, and cannot be assigned during enrollment.

The baseline uses the native `ci-runner.host` command through the [agent runner](/reference/node-agent#commands), with typed input and declared outcomes. It adopts the reserved project, bridge, ACL, and default profile in place. It preserves an existing pool, bridge subnet, every container, and each container's running state. Runner counts and names are not role input. Both default ACL directions reject, private IPv4 and all IPv6 are rejected, and only public IPv4 egress is allowed.

Removal refuses while any container remains, including stopped containers. It never deregisters or deletes runners. Normal removal retains Incus resources; explicit purge removes only the empty project, bridge, and ACL. The shared pool stays.

Shark can serve CI alone without an app role. Beast can keep its app and database roles. Adding the role does not remove another role. The operator adds it through the normal role-add path after merge; no deployment migration changes live Nodes.

### Runner records and registration: pending

The original proposal included Gateway-owned runner records with a Node, unique name, target, labels, status, and create, inspect, converge, and destroy operations. That lifecycle has not been implemented or withdrawn. Its target and credential model must be settled before it is built.

The deployed runners serve the organization. Repository registration through the GitHub App would require Administration write and would not implement organization registration. This host-role change adds no GitHub permission or credential storage.

For the current implementation, container creation and organization registration remain together in [`tools/ci-runners/setup.sh`](https://github.com/hardimpactdev/orbit/blob/main/tools/ci-runners/setup.sh). Registration tokens stay with the operator. Role add does not create an unregistered container or recreate an existing runner. The script remains until a managed lifecycle replaces both parts together.

### Verification still needed

Host tests cover fresh convergence, adoption with varying container counts and stopped containers, both ACL defaults, drift, removal refusal, and empty purge. Gateway tests cover normal role dispatch, Doctor, role compatibility, recipe selection, fleet participation, and exporters. A disposable Incus convergence check remains required before live rollout. The runner lifecycle implementation needs its own credential, record, registration, and removal tests before this ADR can retire.

## Rejected alternatives

Host runners would give a compromised job the Node's trust. Container runners keep the existing isolation boundary. Selecting only a recipe profile would leave fleet and workload visibility dependent on special cases. Splitting container creation into role add while keeping registration in the operator script would add a partial lifecycle. Recreating existing runners on role add would discard healthy registrations and caches.

## Consequences

The host is visible as a workload Node with meters and normal fleet participation. Container state stays outside role intent. Doctor checks the host projection, not individual runners or GitHub registration. The open runner lifecycle must be resolved before this decision is absorbed and retired.

## Affects

- Components: apps/gateway, apps/cli, apps/web, apps/docs, apps/e2e
- ADRs: 0223
- Detail: [Node provisioning](/reference/node-provisioning#ci-runner-role); [Implementation loop](/reference/implementation-loop#self-hosted-shard-runners)
- Verify: focused role, Doctor, recipe, fleet, exporter, remote-program, and browser tests; disposable Incus adoption and removal before live rollout; runner lifecycle verification when built
