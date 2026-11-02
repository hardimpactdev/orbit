---
title: "ADR 0243: Serve Swamp through a swamp role"
description: "The swamp Node role owns the Node's Orbit Caddy, and swamp.orbit is an ordinary private Route on that Node."
---

# ADR 0243: Serve Swamp through a swamp role

The `swamp` Node role marks the Node that runs Swamp serve and its workers. The role converges an Orbit-managed Caddy on that Node. `swamp.orbit` is a private custom proxy Route on the same Node, with upstream `http://127.0.0.1:9418`.

## Status

In progress.

Principle: [One way, one name](/mission#principles). The role and the Route reuse the role, Caddy, certificate and private DNS paths that every other Caddy role uses.

## Context

Swamp serve listens on loopback port 9418 on Node 209. A hand-written Caddy Process on that Node terminated TLS, and a custom proxy Route on Services forwarded `swamp.orbit` to it. Private DNS answered with Services, so every Swamp connection crossed Services, and a regeneration undid any hand edit.

## Decision

`swamp` is a relocatable singleton with no conflicts. Generic role add accepts it; `node:add --role` does not. It is a Caddy role: its convergence installs the pinned Caddy package and publishes the Node's Caddyfile, even before a site exists. Its recipe profile and bootstrap packages are `caddy`. It adds no firewall rule, because WireGuard member trust already admits HTTPS to the Node. It joins fleet rollout group 4. It takes no part in Metrics yet: its role definition sets `metrics: false`, so its transitions capture no Metrics fleet and it selects no role-default exporter. Metrics for the swamp Node comes with the Metrics port (wave 7).

`swamp.orbit` is a private custom proxy Route on the `swamp` Node. Custom proxy sites already use HTTP/1.1 to a loopback upstream, keep the client `Host`, set the forwarded headers, flush immediately and allow one-hour reads, which Swamp's WebSockets need. Route convergence issues the certificate. The private DNS generator answers with the Route Node's WireGuard address.

Relocating the role moves its Caddy build. The `swamp.orbit` Route stays a separate record on its Node and moves with the Route commands. A production relocation moves the vault and needs Nick's approval.

### Workers and placement

The role also runs Swamp's workers on its Node. Its runtime installs one systemd template, `orbit-swamp-worker@.service`, with instances `a` and `b`. Each connects to serve on loopback, `ws://127.0.0.1:9418`, never through Caddy or TLS, with 8 dispatch slots and the labels `role=swamp` and `node=<id>`. Two instances let one drain and restart while the other runs. Each instance loads its enrollment and server tokens from `/etc/orbit/swamp-role-worker/<instance>/` with `LoadCredential`. The operator mints those tokens on the swamp Node; the role never mints, reads or moves them. The runtime also places the Node's SSH identity at `/etc/orbit/swamp-role-worker/ssh-identity`, readable by the worker account, so both instances reach other Nodes with the same key.

Steps that run on the swamp Node select its workers by label, `labels: {role: swamp}`, not by the token name `orbit-node-209`. Steps for one effect Node select `labels: {node: "<id>"}`; every Node worker carries its `node` label. Any number of workers can carry a label, so a worker drains and restarts while another takes its steps, and no step names a token.

### Serve and grants

The role also owns serve. Its runtime installs `orbit-swamp-serve.service`, which runs serve on loopback port 9418 with at most 4 scheduled runs in flight; with 1, overlapping Schedules starve. The runtime never restarts a running serve. It starts serve only when nothing serves the port, so a changed unit waits for an operator restart in a quiet window. Serve runs every lane's work and the release, so an unplanned restart breaks both.

The Gateway's grants come from the repository. `swamp/config/gateway-workflow-grants.json` lists them for `user:orbit-gateway`. The runtime renders them into serve's native grants directory, `swamp/grants/orbit-gateway.yaml` in the serve checkout, and runs `swamp access reload`. Serve then creates new grants, revokes removed ones and keeps the rest, with no restart. The release Swamp load step renders and reloads them after each checkout, so a commit that changes the manifest changes the live grants in the same release. Nobody applies a Gateway grant by hand.

## Rejected alternatives

- A Caddy site source or proxy Process only for Swamp: it duplicates the Route path and its certificate and DNS handling.
- Keeping the Route on Services: Swamp traffic would depend on an unrelated Node.

## Consequences

The hand-written proxy Process 417, its Caddyfile and its `CAP_NET_BIND_SERVICE` drop-in retire after the Route serves `swamp.orbit` on Node 209. Adding the role to Node 209 changes the recipe identity once, so task VM images rebuild as for any recipe change.

## Affects

- Components: apps/gateway
- ADRs: ADR 0238
- Detail: [Node provisioning](/reference/node-provisioning#role-compatibility), [Caddy configuration](/reference/caddy-configuration)
- Verify: Gateway role registry, recipe and native role admission tests, and the Swamp role contract test.
