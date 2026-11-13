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

`swamp` is a singleton with no conflicts. Generic role add accepts it; `node:add --role` does not. It is a Caddy role: its convergence installs the pinned Caddy package and publishes the Node's Caddyfile, even before a site exists. Its recipe profile and bootstrap packages are `caddy`. It adds no firewall rule, because WireGuard member trust already admits HTTPS to the Node. It joins fleet rollout group 4. It takes no part in Metrics yet: its role definition sets `metrics: false`, so its transitions capture no Metrics fleet and it selects no role-default exporter. Metrics for the swamp Node comes with the Metrics port (wave 7).

`swamp.orbit` is a private custom proxy Route on the `swamp` Node. Custom proxy sites already use HTTP/1.1 to a loopback upstream, keep the client `Host`, set the forwarded headers, flush immediately and allow one-hour reads, which Swamp's WebSockets need. Route convergence issues the certificate. The private DNS generator answers with the Route Node's WireGuard address.

The Gateway refuses to relocate or remove the role with a run. Serve on its Node runs every Swamp run, so such a run would stop the serve that runs it. Live relocation stays open for Nick. The `swamp.orbit` Route stays a separate record on its Node and moves with the Route commands. A production move also moves the vault and needs Nick's approval.

### Workers and placement

The role also runs Swamp's workers on its Node. Official `@swamp/cfgmgmt` steps apply its runtime over SSH from 209 (flow-tighten rule 22), with no shipped script; the Gateway renders the units at admission. The runtime installs one systemd template, `orbit-swamp-worker@.service`, with permanent instances `a`, `b` and `c`. Each connects to serve on loopback, `ws://127.0.0.1:9418`, never through Caddy or TLS, with `--concurrency auto`, one dispatch slot per CPU core, and the labels `role=swamp` and `node=<id>`. Three instances keep two workers serving while one drains and restarts. One serialized renewal service renews the oldest due connection after three hours, with five-minute checks and a 90-minute round budget; see the role reference for admission guards. Nick decided on 2026-11-06 to use Swamp's `auto` for the slots instead of 8 per worker, which was 16 slots in all, four times the 4 cores of Node 209. On that Node the median dispatched step took about 1.2 s with 1 or 2 dispatches in flight and between 2.3 s and 2.7 s with 7 to 9. The 4 cores give 12 configured slots, with the original 8 available while one worker drains. Each instance reads its enrollment and server tokens from `/etc/orbit/swamp-role-worker/<instance>/` as the worker account (folders `root:<account>` `0750`, files `0640`), because `LoadCredential` does nothing in a system container. A stop uses native SIGTERM drain for up to 75 minutes (`TimeoutStopSec=4500`, `KillMode=mixed`), giving headroom over the observed 50-minute cold refresh dispatch. Work beyond that allowance can still be killed. The operator mints those tokens on the swamp Node; the role never mints, reads or moves them. The runtime also places the Node's SSH identity at `/etc/orbit/swamp-role-worker/ssh-identity`, readable by the worker account, so all three instances reach other Nodes with the same key.

Steps that run on the swamp Node select its workers by label, `labels: {role: swamp}`, not by the token name `orbit-node-209`. Steps for one effect Node select `labels: {node: "<id>"}`; every Node worker carries its `node` label. Any number of workers can carry a label, so a worker drains and restarts while another takes its steps, and no step names a token.

Serve and each worker run with their own `TMPDIR` in their state directory, `/var/lib/orbit-swamp-serve/tmp` and `/var/lib/orbit-swamp-worker/<instance>/tmp`. Deno places the compiled Swamp start cache there, so a root run using `/tmp` does not replace the units' caches. Each worker also sees its own `/tmp/swamp-ssh`: a bind mount of `/run/orbit-swamp-ssh/<instance>`, with `XDG_RUNTIME_DIR=/tmp`. `@swamp/ssh` keeps its SSH control sockets there, so a worker never uses an SSH master that another worker started, and a renewal restart of one worker cannot cut a step on another. The path keeps its short length on purpose: OpenSSH binds the socket path plus 17 characters first, which must fit in 107 bytes, and a socket under the worker `TMPDIR` would not fit for long behavior names. Run folders such as `/tmp/orbit-ansible-<run>` stay in the shared `/tmp`, because the steps of one run can go to different workers.

### Serve and grants

The role also owns serve. Its runtime installs `orbit-swamp-serve.service`, which runs serve on loopback port 9418 with at most 4 scheduled runs in flight; with 1, overlapping Schedules starve. It cuts a run at the longest fixed budget in `OperationBudgetTable` (`--max-run-duration`). The flag is in the unit, because a trigger publish rewrites `.swamp/serve.yaml` and a value kept there is hidden from git. Serve polls its datastore at Swamp's default interval. Grants reload only on `swamp access reload`, which the release runs. The runtime never restarts serve or a running worker. It starts one only when it is stopped, so changed worker units take effect at their next scheduled renewal, and serve still waits for an operator restart in a quiet window. Serve runs every lane's work and the release, so an unplanned restart breaks both. The release once restarted serve when a commit listed a new manifest `additionalFiles` entry, which serve's reload did not pick up ([Lab #4282](https://swamp-club.com/lab/4282)). Swamp `20261103.000636.0-sha.db3f6373` fixed that, so the release no longer restarts serve.

The Gateway's grants come from the repository. `swamp/config/gateway-workflow-grants.json` lists them for `user:orbit-gateway`. The release Swamp load renders them into serve's native grants directory, `swamp/grants/orbit-gateway.yaml` in the serve checkout, and runs `swamp access reload`. Serve then creates new grants, revokes removed ones and keeps the rest, with no restart. Serve's other grants, for the read-only users, the scheduler and webhook services, operators and Node groups, live in `swamp/config/serve-grants.json` in serve's own grants file format; the load renders them into `swamp/grants/orbit-serve.yaml`. Grants follow the checkout, so the load renders and reloads both after each checkout, and the role writes none, so a commit that changes a grant changes the live grants in the same release. Nobody creates a grant by hand, and no code creates one per workflow. The role also watches serve: the native schedule `swamp-datastore-watch` counts the datastore's files and samples serve's CPU every 15 minutes, and the Gateway raises a problem past a bound ([Swamp datastore watch](/reference/operations#swamp-datastore-watch)).

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
