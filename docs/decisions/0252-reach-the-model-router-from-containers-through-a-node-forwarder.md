---
title: "ADR 0252: Reach the model router from containers through a Node forwarder"
sidebarTitle: "0252 Container model forwarder"
description: "In progress. Each agent Node runs a forwarder with no key that its containers reach on the bridge. The model router guard admits that Node's WireGuard address and accepts Claude Code's `?beta=true`."
---

# ADR 0252: Reach the model router from containers through a Node forwarder

A container on an agent Node holds only the placeholder model key. It calls a forwarder on its bridge. The forwarder runs on the Node, holds no key, and passes each request unchanged to the model router guard over WireGuard. The guard admits the Node's WireGuard address next to the task VM range, and still demands the placeholder and swaps in the real key.

## Status

In progress.

Nick decided on 2026-11-09 that a T3 (Conn) server runs inside each development and task container as user `agent`, with Claude Code and Codex, and that no secret lives in the container. The app user `orbit` must not reach the model route. The forwarder, the source rule and the query rule below follow from that and from ADR 0250 (Run Instances and task groups in Incus containers, in review) rules 3 and 5: no secrets on agent hosts, and placeholder keys only.

Principle: [Lean](/mission#principles). The guard stays the one rule; the forwarder adds no key and no rule.

Per-container usage attribution is open for Nick. This ADR takes the recommendation below.

## Context

The [task VM guard](/reference/ai-router#task-vm-guard) is a Caddy on the router's Node, `services`, at port 8318. It passes a request only when it comes from the task VM WireGuard range, carries the one task key, and is a `POST` with no query to one of the inference routes. Then it swaps in the anchor key.

Two spikes ran T3 in an Incus container on Beast on 2026-11-09. They found two blockers:

1. **The source.** A container's traffic leaves its Node through NAT, from the Node's own WireGuard address. That address is not in the task VM range, so the guard refuses it. The spike ran its own guard on Beast and gave Beast the real CLIProxyAPI key for it. That breaks rule 3 of ADR 0250.
2. **The query.** Claude Code always sends `POST /v1/messages?beta=true`, and `POST /v1/messages/count_tokens?beta=true` to count tokens. The guard refuses every query.

## Decision

Containers reach the router through a forwarder with no key on their Node, and the guard admits that Node's WireGuard address. The guard also accepts Claude Code's fixed query. Nothing changes for task VMs until an operator runs the guard again.

### A forwarder with no key on each agent Node

Each agent Node runs its own Caddy, `orbit-ai-router-forwarder.service`. It listens on the Node's container bridge address at port 8319 and proxies every request to the guard origin. It adds only Caddy's `X-Forwarded-*` headers, holds no key and has no rule of its own. UFW admits port 8319 only on the bridge.

A container's traffic leaves the Node from the Node's WireGuard address, which the guard admits. So UFW on the Node drops forwarded traffic to the guard's address and port, from any bridge, with a route deny at the top. A container reaches the guard only through the forwarder. The Node's own processes are not forwarded and reach the guard directly; on an agent Node these are Orbit's own: the forwarder, the Node agent, Incus and root.

The guard stays the one place that decides. A request through the forwarder meets the same key, method, path and query checks as a request from a task VM.

### The guard admits forwarder Nodes

The guard admits two kinds of source: the task VM range, and the WireGuard address of every Node whose last forwarder run left a forwarder in place. Each such address gets its own UFW allow above the guard's deny.

One run does both halves. `ai_router.model_forwarder` first applies the guard with the Node added (or removed), then applies (or removes) the forwarder on the Node. A run that applies ends with a request through the forwarder with no key. The guard answers 401 to a refused request from a source it admits, and 403 to any other source. So the 401 proves both that the guard admits the Node and that it still demands the key. A run that removes ends by showing that the router's Node answers SSH from the Node while the guard port does not.

The router's Node and Nodes with the `swamp` role cannot run a forwarder. The router's Node holds the real key, so it is no agent host. The swamp Node is the outside source the guard run uses to prove its refusal.

### Only `orbit` is kept out, inside the container

The forwarder cannot tell the users in a container apart: both reach it from the container's address. So the container runtime keeps `orbit` out in the container's own network namespace, with an nftables rule that admits only the `agent` uid to the forwarder's address and port. The second spike proved this rule. The container runtime owns it, not this ADR.

### Claude Code's query

The guard accepts exactly one query, `beta=true`, and only on `/v1/messages` and `/v1/messages/count_tokens`. Every other query on every route still gets 403. Claude Code sends this query on every call and has no setting to drop it. Allowing one fixed value keeps the guard's reason for the rule: no client can pass router options, such as a key, in the query.

`/v1/messages/count_tokens` joins the inference routes. It counts tokens and changes nothing on the router.

### Per-container attribution: none for now

The guard sees the Node's address, not the container's. These are the options:

| Option | What it costs | What it gives |
| --- | --- | --- |
| A. None. Usage is per Node, by source address | Nothing | The same as task VMs today: one key, one anchor, no per-VM usage |
| B. The forwarder stamps a header with the container's bridge address, and the guard logs it | A log on the guard and a map from bridge address to container | Per-container usage in the guard's log |
| C. One placeholder per container | A key per container in the guard and in every start | Per-container usage in CLIProxyAPI |

**Recommended and taken: A.** Task VMs have no attribution either, and no consumer reads it. B adds a header that the forwarder must set, and the guard must trust only from forwarder Nodes, for a log nobody reads yet. If attribution becomes needed, B is the next step, because it changes neither the container nor the key.

## Rejected alternatives

- **Admit agent Node addresses at the guard, with no forwarder.** Containers would call the guard directly, through the Node's NAT. Every bridge on the Node, such as a CI runner bridge, would reach the guard too, and the container runtime's rule for the `agent` uid would need the guard's address, which can move. The forwarder gives one local listener per Node that the runtime's rule names, and the route deny closes the direct path.
- **A guard on each agent Node.** It needs the real key on the Node. ADR 0250 rule 3 forbids it.
- **Give containers WireGuard identities.** Every container becomes a WireGuard peer with its own address, hub filter and enrolment. Too much for one route.
- **Allow any query on the messages routes.** The rule against queries keeps clients from passing router options. One fixed value is enough for Claude Code.

## Consequences

- A container needs only the placeholder and the forwarder origin, `http://<bridge address>:8319`.
- Any process of the Node itself can use the guard with the placeholder; containers cannot, except through the forwarder. The placeholder is no secret: it works only from an admitted source. Root on the Node already reaches every container.
- The guard answers 401 instead of 403 to a refused request from an admitted source, task VMs included. Nothing reads the difference except the forwarder run's end check.
- Removing the guard with `ai-router:task-guard --remove` also removes every forwarder Node's allow. The forwarders stay; the next guard run admits them again.
- Task VMs see no change until an operator runs the guard again. Then they also gain `?beta=true` and `count_tokens`.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: applies rules 3 and 5 of ADR 0250 (in review) to containers
- Detail: [Task VM guard](/reference/ai-router#task-vm-guard), [Container model forwarder](/reference/ai-router#container-model-forwarder), [Credentials in task VMs](/reference/tasks#credentials-in-task-vms), and the charts `docs/cli/ai-router/task-guard.mmd` and `docs/cli/ai-router/model-forwarder.mmd`. The change also touches `swamp/`: the `ai_router.model_forwarder` workflow and its Ansible phase
- Verify: the Gateway tests run the rendered guard and forwarder on real Caddy, chained; a first run on an agent Node ends with the guard's 401 through the forwarder
