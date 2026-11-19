---
title: "Executor"
description: "How orbit-executor checks callers and signed orders, keeps its journal, serves the relay command, how the Gateway hands its results to a waiting request, and how the role converge installs it."
covers:
  - apps/agent/{crates/executor-core/**,crates/executor/**,crates/power/**,crates/firewall/**,crates/route/**,clippy.toml,contract/fixtures/executor-order.json}
  - apps/gateway/app/{Domain/Nodes/NodeExecutorInput.php,Domain/Executor/**,Infrastructure/Executor/**,Console/Commands/ExecutorParityCommand.php}
  - tools/swamp-proof-guest/executor-order.php
  - swamp/ansible/tasks/node-executor.yml
---

# Executor

`orbit-executor` runs fast Node actions on orders that the Gateway signs ([ADR 0266](/decisions/0266-sleep-and-wake-containers-through-a-signed-order-executor), [ADR 0267](/decisions/0267-run-fast-node-actions-in-a-signed-order-executor)). It is one Rust binary from the `apps/agent` Cargo workspace: the `orbit-executor-core` crate holds the shared parts, and each action family gets its own crate.

> **Signed power stays off by default.** The [sleep](#rust-sleep-and-discard) and [wake](#rust-wake) sections record the earlier swap-backed clone measurements. Slice 6 added [the wake inside the request](#the-wake-inside-the-request); since 2026-11-19 the visit is held until the wake ends, with no progress page. Slice 7 adds [signed power delivery](#signed-power-delivery) through the observer, with the relay as the local caller of Swamp start, deploy and boot phases.

## Where it runs

The role converge installs the executor only on a Node whose roles enable an action family. Today that is `app-dev`, which enables the `firewall`, `power`, `proof` and `route` families. A Node with several roles enables the union of their families.

The converge writes:

| Path | Content |
| --- | --- |
| `/usr/local/libexec/orbit-executor` | The binary, pinned by its SHA-256 from the agent delivery (artifact `executor-linux-x86_64`) |
| `/etc/orbit/executor/gateway.pub` | One or two Gateway Ed25519 public keys, base64, one per line. The Gateway setting `ORBIT_EXECUTOR_PUBLIC_KEYS` names them |
| `/etc/orbit/executor/node` | The Node id |
| `/etc/orbit/executor/enabled` | The enabled families, one per line |
| `/etc/orbit/executor/nats.json` | The [machine bus](#the-machine-bus) URL. Only when the Gateway runs the bus |
| `/etc/orbit/executor/bus-guard.nft` | The nftables guard that lets only root reach the machine bus. The unit loads it before each start. Only when the Gateway runs the bus |
| `/etc/orbit/executor/restore-threads` | The [restore pool](#rust-wake)'s size, 32; the executor refuses fewer than 16 |
| `/etc/systemd/system/orbit-executor.service` | A plain service that runs `orbit-executor serve`, with `Restart=always` |
| `/etc/sudoers.d/orbit-executor-relay` | The Node user may run exactly `orbit-executor relay` as `orbit-executor-relay` |

The converge also makes the system user `orbit-executor-relay`. A changed binary or file restarts the service.

### One agent binary

[ADR 0271](/decisions/0271-run-operations-on-a-gateway-state-machine-over-nats) N7 makes the executor the second process of the one `orbit-agent` binary. On Linux, `orbit-agent execute serve` and `orbit-agent execute relay` run the same code as `orbit-executor serve` and `orbit-executor relay`: the executor crate is a library, and both binaries call its one entry point. The separate `orbit-executor` binary and its artifact stay until the Gateway pins an agent delivery whose `orbit-agent` has the `execute` mode. The pull request that moves the install to `orbit-agent execute serve` removes them. From then on a new agent binary restarts `orbit-executor.service` too: the relay check compares the caller with the executable the executor started from.

### Rollout

A converge skips the executor install until the Gateway has both parts it needs. The intent names the reason as `skip`, and the converge records `executor install skipped: <reason>` in its run:

- `executor.release_missing`: the Gateway's pinned agent delivery has no `executor-linux-x86_64` artifact.
- `executor.keys_missing`: `ORBIT_EXECUTOR_PUBLIC_KEYS` is empty.

A family newer than the pinned delivery stays off: `NodeExecutorInput::FamilySince` names the first agent version that knows it, such as `firewall` from 0.16.0 and `route` from 0.17.0. An older executor refuses to start on an unknown family.

Roll out in this order:

1. Publish a delivery with the `orbit-executor-linux-x86_64` artifact. The release check verifies its digest alongside the agents.
2. Set `ORBIT_EXECUTOR_SIGNING_KEY` and `ORBIT_EXECUTOR_PUBLIC_KEYS` on the Gateway.
3. Move the agent pin to that delivery and release the Gateway. The [fleet rollout](/reference/gateway-recovery#fleet-rollout) converges each `app-dev` Node whose executor input changed.

The Gateway holds its executor settings in `config/orbit.php` under `orbit.executor`:

| Setting | Environment | Content |
| --- | --- | --- |
| `public_keys` | `ORBIT_EXECUTOR_PUBLIC_KEYS` | One or two base64 Ed25519 public keys, comma-separated. Each executor Node pins them |
| `signing_key` | `ORBIT_EXECUTOR_SIGNING_KEY` | The base64 64-byte libsodium secret key that signs every order. Its public half must be one of `public_keys` |
| `power_enabled` | `ORBIT_EXECUTOR_POWER_ENABLED` | Admit signed power and lifecycle orders. Defaults to `false` |

The signing key lives in the Gateway's own environment, readable by `orbit` and by no one else ([ADR 0266: Key custody](/decisions/0266-sleep-and-wake-containers-through-a-signed-order-executor#the-order)). Never print, log or commit it. Make a pair with libsodium's `sodium_crypto_sign_keypair()` as `orbit`, and write both halves, base64, straight into the Gateway's environment file, so neither reaches a terminal or a log. For a rotation, pin the new public key beside the old one, converge every executor Node, then switch the signing key and drop the old public key.

From then on the install is required, and a wrong pin fails the converge:

- A pin with no release URL fails with `executor.release_invalid`.
- More than two keys fail with `executor.keys_invalid`.
- A binary whose SHA-256 differs from the pin is never installed.

A new executor build reaches the Nodes only this way: a reviewed commit copies the newer release's `manifest.json` into `apps/gateway/resources/agent/frozen-manifest.json` ([Node agent: Delivery](/reference/node-agent#delivery)). The executor's pin is part of each `app-dev` Node's role input, so the next Gateway release's fleet rollout converges every such Node again and installs the new binary. A release that moves only the executor leaves the agent's own digest unchanged.

### Build parity

Every executor Node must run the build that the Gateway pins before `ORBIT_EXECUTOR_WAKE_ENABLED` or `ORBIT_EXECUTOR_POWER_ENABLED` goes on ([ADR 0266](/decisions/0266-sleep-and-wake-containers-through-a-signed-order-executor#must-clear-before-the-signed-power-flags-go-on)). Check it on the Gateway, as `orbit`:

```bash
php artisan orbit:executor-parity [--json]
```

For each active Linux Node whose roles enable an executor family, the check compares the SHA-256 that the converge pins with what the Node reports. It reads the Node over its pinned SSH account with one fixed, read-only command: the SHA-256 of `/usr/local/libexec/orbit-executor`, the SHA-256 of the service's running executable (`/proc/<MainPID>/exe`, read with `sudo -n`) and the state of `orbit-executor.service`. The same command lists the containers that Incus reports `FROZEN` (`sudo -n incus list --all-projects`). A Node where root finds no `incus` command, such as the `services` Node, has no containers, so none is frozen. It also reads the Node's [activation proxies](#activation-proxy) from Caddy's loaded config.

A frozen container is fine when the Gateway records its Instance asleep, with a sleep that owns the freeze. A frozen container without such a record fails the Node with `instance.frozen_unrecorded`, and the check names each such Instance. A failed Python sleep can leave one: Incus holds the container frozen while the Gateway still records it running. No engine owns that freeze, so the settings alone would pick the engine for its wake ([Switching engines](#switching-engines)).

It exits 0 when it checked at least one such Node, and every one runs the pinned build, both on disk and in memory, with its service active, no unrecorded freeze and only right activation proxies. A Node whose installed Incus fails its list, or whose `sudo -n` fails, counts as `executor.node_unreachable`. Otherwise it exits 1. With no executor Node it says so. Otherwise it names each Node with its problem. With `--json`, stdout holds only the JSON: `ok` and one row per Node.

| Problem | Meaning | Fix |
| --- | --- | --- |
| `executor.build_differs` | The Node's binary is another build | Finish the fleet rollout, or `orbit node:converge <node> --full` |
| `executor.missing` | No binary at the path | `orbit node:converge <node> --full` |
| `executor.inactive` | The service does not run | Read its journal, then converge the Node |
| `executor.running_build_differs` | The file is the pinned build, but the service still runs another one: the binary changed without a restart | `systemctl restart orbit-executor` on the Node |
| `executor.node_unreachable` | The SSH read failed, or Incus is installed but its list failed | Repair the Node's access or its Incus |
| `executor.node_unmanaged` | The Node has no verified SSH management | Finish its enrollment |
| `instance.frozen_unrecorded` | A container is frozen, but the Gateway does not record its Instance asleep | With the settings still off, `orbit instance:wake <id>` for each listed Instance, then check again |
| `caddy.config_unreadable` | Caddy's admin API did not answer with its config, or the Node could not read it | Check `caddy.service` on the Node, then `orbit node:converge <node>` |
| `caddy.activation_proxy_wrong` | An activation proxy does not reach this Gateway as the route renderer draws it. The check names each Instance and the wrong field | `orbit node:converge <node>`, then check again |
| `executor.release_missing`, `executor.keys_missing`, `executor.release_invalid`, `executor.keys_invalid` | The converge would skip or refuse the install ([Rollout](#rollout)) | Fix the pin or the keys |

The flag switch runs this check first and changes no flag while it fails ([Switching engines](#switching-engines)). A plain converge whose input did not change skips the executor phase, so it does not repair a binary changed by hand; `--full` does.

#### Activation proxy

A visit to a sleeping Instance reaches the Gateway through its site's activation proxy: Caddy's `forward_auth`, a `reverse_proxy` that rewrites the visit to a GET of `/api/v1/runtime-activations/app-instance/<id>` ([The wake inside the request](#the-wake-inside-the-request)). The executor's own Caddy guard follows a marked visit as Caddy runs it. It takes every route with the asleep marker's matcher, in order, and skips `vars`. It enters each `subroute` through its routes that have no matcher or the same marker matcher. The first other handler must be that proxy, with that URI and the method GET. Otherwise the sleep refuses with `instance.sleep_route_unready`.

Production's compiled routes nest the proxy this way, in a `subroute` on a second marker route after the `vars` route. The guard's tests hold three such routes from server `srv2`.

Two renderings are live. Before #4020 the renderer drew `handle @orbit_asleep { forward_auth … }`: Caddy compiles it into a `vars` marker route and a sibling marker route whose `subroute` holds the proxy (production routes 50 and 53). Since #4020 it draws `forward_auth @orbit_asleep` inside `route { }`, and the marker matcher sits on the inner route (production route 25). A site keeps its loaded shape until the Node's Caddy sites are next published.

The first executor guard took the first non-`vars` handler. So it accepted only the newer shape and refused signed sleep Operation 29546 on production. Subtask 5980 loaded both renderers' output for a Vite and a plain site into the nested `app-dev` Node's Caddy 2.11.7. It read the admin API's config back: the first guard refused both older sites and the fixed guard accepted all four.

Then it ran signed orders on a real Instance in that layout. Container default Instance 2 (`proofapp.nested-dev`) got its site rendered by the pre-#4020 renderer with Vite on and compiled by Caddy. Both settings were on. With the pinned executor, sleep Operation 211 failed with `instance.sleep_route_unready` over the WebSocket, as on production. With the branch executor, sleep 212 froze the container in 475 ms (`done`, `websocket`).

A visit then woke it through Operation 213 (`done`, `websocket`, 56,065 pages read, none failed). In this older layout an activation `200` reached the visitor as an empty page, because nothing follows the activation inside `handle @orbit_asleep`. Production's routes still have this layout, so a woken visit now answers `307` to the same URI in both layouts ([the wake inside the request](#the-wake-inside-the-request)).

The guard does not check where the proxy goes, so the parity read also takes Caddy's loaded config from its admin API (`http://127.0.0.1:2019/config/`, read-only). On the Node, Python finds each Instance's activation proxy as the executor's guard does: by the asleep-marker matcher that the route renderer draws (`try_files /app-instance-<id>.asleep`), not by the rewrite.

The check reads every site on its own. A site is one top-level route of one Caddy server, named by its host, server and index, so a private clone's host on the bridge and on the WireGuard listener is two sites. For each site and Instance it takes the first handler other than `vars` that a marked visit reaches. Within the site, every route that carries the marker counts, at any depth and in config order. Caddy's compiled config nests the proxy:

- The site's handler is a `subroute`. Several sibling routes inside it carry the same marker matcher. One holds only `vars`, and another holds the proxy.
- `forward_auth` compiles to a `subroute` that wraps the `reverse_proxy`. The check steps into each `subroute` it reaches.
- A site without Vite can sit one level deeper again: a route with no matcher, then a `subroute` whose inner route carries the marker and the proxy.

The tests use production's own routes, read from beast's admin API, as fixtures. A marker route that reaches no handler but `vars` gives an empty proxy, so the handler fails. It resolves that proxy's upstream host there to every address, with `getaddrinfo`. For each one, the Gateway compares these fields with `DevelopmentCaddyConfigRenderer::activationTarget()`, the target that the route renderer draws for that Instance:

| Field | Expected today |
| --- | --- |
| Handler and rewrite URI | `reverse_proxy` to `/api/v1/runtime-activations/app-instance/<id>`. A removed rewrite fails |
| Upstream | `gateway.orbit:443`, the host and port of the Gateway origin |
| Method | `GET`: `forward_auth` sends every activation as a GET |
| `Host` header and TLS server name | `gateway.orbit` |
| Addresses the upstream resolves to on the Node | Only this Gateway's WireGuard address: the active Node with the active `gateway` role. Any other address fails, even beside it |

Each difference fails the Node with `caddy.activation_proxy_wrong`, for example `Instance 2 at proofapp.nested-dev on srv0 route 0: method "POST"` or `Instance 2 at proofapp.nested-dev on srv0 route 0: resolves to ["10.45.0.9"]`. A Node with no sleep-capable site has no activation proxy and passes this part.

Subtask 5912 proved it on the nested fleet, against the loaded config of container default Instance 2's route on `nested-app-dev`. The check passed. With the route's activation method changed to POST through Caddy's admin API, it failed with `caddy.activation_proxy_wrong (Instance 2: method "POST")`. With the upstream changed to the app-prod Node's address, it failed with `Instance 2: resolves to ["10.45.0.3"]; Instance 2: upstream ["10.45.0.3:443"]`. With the rewrite removed from the proxy, it failed with `Instance 2: URI null; Instance 2: method null`. With the original config loaded again, byte for byte, it passed each time.

With a second site for Instance 2 loaded, the check passed; with only that site's rewrite removed, it failed with `Instance 2 at second.nested-dev: URI null; Instance 2 at second.nested-dev: method null`; restored, it passed. With the same host also on a second server, `bridge` on the Instance bridge, only the WireGuard server's rewrite removed failed with `Instance 2 at proofapp.nested-dev on srv0 route 0: URI null`; restored, it passed.

systemd only starts the executor at boot and restarts it after a crash. No systemd call, script, path unit or socket unit sits on an action's path.

## The socket and the caller check

The executor binds `/run/orbit-executor.sock` itself, with mode `0660`, owner root and group `orbit-executor-relay`. It binds the socket inside a new folder of mode `0700`, sets its mode and group there, and then renames it into place. So the socket is never reachable with wider rights. It accepts a connection only from:

- root whose cgroup is exactly `0::/system.slice/orbit-agent.service`: the observer, or
- the user `orbit-executor-relay` whose executable is exactly the executor binary: the relay.

It reads the peer with `SO_PEERCRED` and `SO_PEERPIDFD`, reads the peer's cgroup and executable in `/proc`, and then sends signal 0 to the pidfd. A live pidfd proves that the PID was not reused during the check. It refuses every other caller without a reply and logs the reason: `caller_other_user`, `caller_outside_observer_unit`, `caller_relay_other_program`, `caller_unknown`, `caller_unreadable` or `caller_gone`.

An allowed caller sends one order and gets two JSON lines: `{"status":"accepted"}` at once, then the result. The executor serves at most 64 connections at once. It waits at most 10 s for the whole order, however slowly the caller sends it.

## The order

The wire form is `base64(payload).base64(signature)`, at most 4 KB. The payload holds `action`, `node`, `target`, `operation`, `nonce` (32 lowercase hex digits) and `expires_ms`, an optional `power_check` for a `sleep` or `wake`, and an optional `input`: the primitive's public typed input, any JSON, `null` when absent ([ADR 0271](/decisions/0271-run-operations-on-a-gateway-state-machine-over-nats)). The executor keeps a non-null `input` in the order's open journal record, so crash recovery runs the order with it; the primitive checks it against its own schema. An unknown field refuses. The executor checks, in this order, and refuses with the code in brackets:

1. The size and the form (`order_too_large`, `order_malformed`).
2. The signature, with `verify_strict` against each pinned key (`bad_signature`), then the payload's shape (`bad_payload`).
3. The action is on the allowlist (`unknown_action`) and its family is enabled on this Node (`action_not_enabled`). A valid signature does not enable a family.
4. The Node (`wrong_node`) and the target (`unknown_target`). See [Targets](#targets).
5. The expiry: past (`expired`), or more than 10 s ahead of the Node's clock (`expiry_too_far`).
6. The nonce has never been seen, across restarts too (`replayed_nonce`). The refusal carries the first order's result once it exists.
7. The Operation: a done record with the same Operation, action and target returns the first result, marked `"repeat": true`, and does not act.

### Targets

The Node itself is the target of `proof.noop`, `ufw.ensure_rule`, `tls.ensure_csr` and `route.publish_site`. Sleep targets must exist in the local Incus. Discard accepts a numeric Instance id after its container is gone.

The log line of a `replayed_nonce` refusal ends in `first=<status>`, the status of the first result it carried, or `first=none` before that result exists.

A refusal line holds `"status":"refused"`, the `reason` and the Node's clock as `node_clock_ms`.

Orders for one target run one at a time under that target's lock; different targets run in parallel. The nonce and done maps are held only for one lookup or insert, with no I/O under them.

## The machine bus

With `/etc/orbit/executor/nats.json` present, `serve` also joins the machine bus of the [Gateway engine](/reference/gateway-engine) ([ADR 0271](/decisions/0271-run-operations-on-a-gateway-state-machine-over-nats)). Without it the executor serves its socket alone. The bus runs in its own thread and reconnects a second after any failure.

When it joins, the executor:

1. connects with no credential, with its own inbox prefix `_INBOX.node-<id>`; the Gateway admits it as `node-<id>` by its WireGuard address;
2. reports its primitives and version to key `n<id>` of the `NODES` bucket, every minute; the Gateway routes a kind by this report;
3. opens its durable pull consumer `node-<id>` on the `ORDERS` stream. The Gateway makes it before the Node's first order; until then the executor checks every 5 s and logs once.

It runs orders in slots: two per CPU, between 2 and 8. It pulls one order at a time and only when a slot is free, so a pulled order never waits past its acknowledgement wait. In an Incus container the CPU count comes from the smaller of the affinity mask and `/proc/cpuinfo`, which lxcfs limits to the container's quota.

For each order it:

1. checks it exactly as the socket does (`server::verify`), on a blocking thread, with the same allowlist, journal, nonce and expiry rules;
2. reads any content the order names by SHA-256 from the `CONTENT` bucket into `/var/lib/orbit/executor/blobs`, and checks the hash;
3. runs it, and sends a progress acknowledgement every 2 s so a running order is not redelivered;
4. publishes the result line as a receipt on `orbit.receipts.<id>`, with the nonce as `Nats-Msg-Id` (a refusal uses `refused-<nonce>`);
5. acknowledges the order only after JetStream stored the receipt.

An order that is never acknowledged comes back, and the journal answers it as a repeat. Its refusal's own message id keeps it from shadowing the real result. A refusal is a receipt too, so the Gateway learns of an expired order and sends a new one.

### ufw.ensure_rule

`ufw.ensure_rule` (family `firewall`, crate `orbit-executor-firewall`) makes one ufw rule present or absent. Its input is `action`, `port`, `protocol`, `source`, `comment`, `ensure` (`present` or `absent`) and `retain`; an unknown field refuses. It reads `ufw status numbered`, plans the changes, runs one `ufw` argv per change, reads the table again and checks every expected line and its position. It answers `applied` with the number of changes, or fails with the firewall code, such as `firewall.backend_inactive` or `firewall.verify_failed`. The planner is a port of the Swamp flow's planner, with its tests. The [firewall commands](/cli/firewall) describe the rules it keeps.

### tls.ensure_csr

`tls.ensure_csr` (family `route`, crate `orbit-executor-route`) takes `directory`, a version folder `/etc/caddy/orbit-certificates/<scope>/versions/<16 hex>`, and `domain`. It makes the folder (root:caddy, 0750) and an RSA 2048 key in it once (root:caddy, 0640), written beside its final name and moved in place. It answers `output` with a certificate request for that key: CN `Orbit` and the one subject name `DNS:<domain>`. A repeat reuses the key. A signed certificate may already match that key, so a second order must not replace it. Codes: `route.input_invalid`, `app-dev.certificate_request_failed`.

### route.publish_site

`route.publish_site` (family `route`) places one Node's part of a Route. Its steps run in this order:

| Step | What it does |
| --- | --- |
| Caddy access | One `find` or `setfacl` command per row, as `RouteWebRootCommands` renders it. See below. |
| `APP_URL` | Replaces the last `APP_URL=` line of an existing `.env`, or adds one. The file must belong to the Route's account and keeps its owner and mode. |
| Links | Replaced atomically. |
| PHP-FPM pools | Named by `version` and an optional production `user`, from which the executor derives the path, the `php-fpm -t` test and the unit. See below. |
| Leaves | Each leaf's `cert.pem` beside its key, the version's `current` link, and the account's own copy. |
| Trust | The Orbit root in the system trust. `update-ca-certificates` runs only when it changed. |
| Caddyfile | A candidate, validated as the `caddy` user, replaces the live file only when it differs. See below. |

Each access row names a kind (`deny_defaults`, `deny`, `passage`, `grant_defaults` or `grant`), a user and paths. The executor builds the command from those fields. It leaves out a missing optional path and never makes one.

Every publication reloads a running pool unit and starts a stopped one. Caddy also reloads on every publication that carries a Caddyfile. A repeat after a crash between a write and its reload still makes the files live. A failed validation leaves the live Caddyfile as it was.

Content comes as blobs. It answers `applied` with the number of changed files and units; ACL commands do not count. Codes: `route.input_invalid`, `route.content_missing`, `route.publish_failed`.

## The Gateway signer and the event

`App\Domain\Executor\ExecutorOrderSigner` builds and signs one order for an Operation. The order's `node` is the Operation's `node_id` and its `operation` the Operation's id as a numeric string; the signer refuses an Operation without an id or a `node_id`. The payload holds the six fields above, in that order, as compact JSON, then `power_check` when the order carries one ([Signed power delivery](#signed-power-delivery) explains the values), then `input` when the order carries one. `signStep` signs one step of an engine Operation: its `operation` is `<id>:<step>`, it names the step's Node and target, and it carries the primitive's input ([Gateway engine](/reference/gateway-engine#orders-and-receipts)).

The nonce is 16 random bytes in lowercase hex, and `expires_ms` is the Gateway's clock plus 5 s. An engine step's order expires after 8 s instead, so a late order is refused and re-sent. The signer also refuses an action outside the allowlist (`proof.noop`, `sleep`, `wake`, `discard`, `ufw.ensure_rule`, `tls.ensure_csr`, `route.publish_site`), a target that is not 1 to 128 characters of `[A-Za-z0-9._:-]`, and a wire form over 4 KB. It reads its key with `ExecutorOrderSigner::fromConfig()` and refuses a missing or malformed one.

`App\Domain\Executor\ExecutorOrderPublisher` takes the Operation and its order. It publishes the order as the server event `executor-order` on `presence-node.{node_id}` of the Operation's Node, and refuses an Operation without a `node_id` or the order of another Operation. It publishes through the Gateway's one Reverb publishing path (`RealtimeConnection`), as the log stream and record events do. Only the Reverb app secret can publish a server event. The event's data is:

```json
{ "operation": "<the Operation id, a numeric string>", "order": "<base64(payload).base64(signature)>" }
```

The observer copies `operation` unread into every reply line, so a reply without an Operation id still finds its Operation. The [listener](#the-listener-and-the-hand-off) records a result only when that Operation's `node_id` is the Node that sent it.

During a `websocket` move the event also goes to the old Node's Reverb, where an agent that connected before DNS moved still listens. The agent holds one connection, so at most one copy reaches the executor. A failure on the serving Reverb throws, so the caller can take the fallback of slice 7 at once. Skipping the old server when the local budget ends does not mark it down for other broadcasts.

## The observer pass-through

The observer (`orbit-agent.service`) handles `executor-order` only on its own `presence-node.{id}`. It reads the event's `operation` and `order` and nothing inside either: it does not parse, verify or sign it. Then it:

1. drops an order over 4 KB, an event without an `operation` of 1 to 64 bytes and an `order`, and an order past 64 in flight, and logs why;
2. connects to `/run/orbit-executor.sock`, writes the order's bytes unchanged and closes its write side;
3. reads the reply for at most 10 s and 64 KB;
4. sends each complete line as the client event `client-executor-result` on the same channel.

The data of `client-executor-result` is exactly:

```json
{ "operation": "<the event's operation, copied unread>", "line": "<the reply line, unchanged, without the newline>" }
```

A line that does not fit one Pusher client frame (9,900 bytes) is dropped and logged; the session goes on. Lines sent before a bound passes stay sent. A missing socket sends nothing, and the Gateway's fallback covers it. Orders that arrive while the observer reconnects are lost; replies that finish then go out on the next session.

The observer's connection to Reverb sets `TCP_NODELAY`. A reply is two small frames, `accepted` and then the result. Without it, Nagle's algorithm held the result until Reverb's delayed ACK of the first frame arrived. That added about 40 ms to every round trip on the nested fleet: 46 ms median before the change, 4.0–6.2 ms after it.

Each order runs in its own task outside the observer's supervised tasks, because it holds no shared lock: a panic there loses that one order, and the observer goes on. The executor admits the observer by its cgroup, so the order needs no other proof of where it came from: the signature is the boundary.

## Journal and recovery

The journal is `/var/lib/orbit/executor/journal`, one JSON record per line. When the executor accepts an order, before the order waits for its target lock, it writes a `seen` record for its nonce. Before the action it writes an `open` record, and after it a `done` record. An order that expired while it waited for the lock gets a `done` record with `executor.order_expired` and does not act. At start the executor creates the marker `journal.accepts` beside the journal; the Gateway reads it to know that a missing record proves the order never arrived.

One writer thread appends. Each connection thread hands it a record and waits until the record is written, so the `open` record is on file before the action runs. No record waits for a disk sync: a separate syncer thread syncs the file once a second. The page cache survives an executor crash, and a Node reboot expires every order.

The journal keeps only its live records: the nonces that have not expired, every order still open without a result, and the done records of the last 24 hours. The writer compacts the journal to these records every 10 minutes, or after 10,000 new records, while the executor runs. The syncer writes and syncs the compacted file. The writer then adds the records appended meanwhile and renames the file into place. Until that rename every record goes to the old journal, so a crash at any point leaves a complete journal.

At start-up the executor reads the journal and ends every order accepted without an `open` record with `executor.order_expired`: a crash ended its wait for the lock, so it never acted. It keeps the same live records and rewrites the journal with them. Then it runs every `open` order without a `done` record again and records its result. It pushes nothing by itself: the Gateway learns the result by sending the same Operation again.

## The relay command

`sudo -n -u orbit-executor-relay /usr/local/libexec/orbit-executor relay` reads one signed order from stdin, at most 4 KB, hands it to the socket, and copies the reply to stdout, at most 64 KB within 10 s. Swamp phases on the Node use it: a start or deploy that resumes or discards a signed freeze, and the boot of a container that a signed wake found stopped. The Gateway never calls it.

## The listener and the hand-off

The observer sends each reply line back as the client event `client-executor-result` on `presence-node.{id}` ([ADR 0266](/decisions/0266-sleep-and-wake-containers-through-a-signed-order-executor), "The path", steps 4 and 5). Its data holds exactly `operation` and `line`, as [the pass-through](#the-observer-pass-through) sends them: the Operation id that the [publisher](#the-gateway-signer-and-the-event) put beside the order, and the reply line, unchanged, at most 64 KB. The `accepted` line and the refusals of the first five order checks do not name the Operation, so `operation` is how their reply finds it.

The [agent view subscriber](/reference/node-agent#gateway-view) is the listener. No other service and no second connection per Node takes part. For each event it:

1. It checks the sender. Reverb must stamp the event with the member `agent.{id}` of that Node's channel, as for `client-log`. It drops and logs an event from any other sender.
2. It checks the form. `operation` must be an Operation id, and `line` one JSON object with a known `status`. A line that names another Operation fails too. It drops and logs a malformed event.
3. It checks that the sender owns the Operation before it queues an `accepted` line. The line goes to `executor:op:<operation>` with no database write.
4. It checks that an action result names the expected Operation, action and target. It records the first valid outcome with the Gateway receipt time in microseconds.
5. It logs a deterministic refusal with its reason and the Node's clock. It leaves a failed Activity with the code `executor.order_check_failed` on the Node. It logs a `failed` line with its code.
6. It pushes the checked wire line to `executor:op:<operation>`, after the record write. A Valkey failure cannot hide a recorded refusal.

One conditional `UPDATE` records the first result and its receipt. A repeat changes neither. A `replayed_nonce` envelope is transport evidence, not a terminal refusal. If its `first` holds a matching action result, the listener can record that inner result. A bare replay cannot hide a later success, and a result for another Operation, action, target or Node cannot settle this one. Checked wire lines remain in arrival order, including replay evidence, so another waiter can still recover a lost push.

The list lives in the Gateway queue's Valkey, under its `orbit-gateway:` prefix. Each push is one Lua call that runs `RPUSH` and `EXPIRE` of 60 s together, so a key never exists without its expiry. If the database or Valkey fails, the subscriber logs it and goes on. The waiting request then falls back as for a lost result.

**The alert.** The [Problem collector](/reference/tasks#fingerprints) selects an Activity whose code ends in `_failed`. A deterministic refusal counts toward the Problem `orbit:agent-view|executor.order_check_failed`, and the Activity names the reason. Replay evidence does not count. The subscriber saves the Activity without its model observers, so no Reverb HTTP request blocks the socket loop. `activity:list` and the collector still see the row.

**Cost in the socket loop.** Before adding reply matching and receipt timestamps, the hand-off took 0.19 ms median (0.27 ms p95, 2.1 ms max) over 300 results on file SQLite in WAL mode with 20,000 Operations and a local Valkey. In a second run, a `done` result took 0.21 ms median (0.35 ms p95), and a refusal with its Activity insert took 0.54 ms median (0.84 ms p95, 8.8 ms max). No queue job or HTTP call sits between the listener and the waiting request.

**The waiting request.** `ExecutorResultWait::wait(operation, bound)` runs `BLPOP`, through `ExecutorResultQueue::pop`, on the Operation list until a result arrives or the bound passes. It reads lines in arrival order, including lines already queued when the wait starts. It returns `done`, `partial`, `failed`, `refused` or `timeout`, with the elapsed time, the result line and the time to an observed `accepted` line if one arrived first. A result released after the deadline returns a timeout; the result remains recorded on the Operation. The activation endpoint uses the `ORBIT_EXECUTOR_WAIT_MS` bound when `ORBIT_EXECUTOR_WAKE_ENABLED=true`.

Activation uses a separate Valkey connection with 25 ms connect and 50 ms socket I/O timeouts. It uses short `BLPOP` calls only while at least 250 ms remain, to leave room for connection, authentication, database selection and the reply. With less time left, or if that connection fails, it checks the durable SQLite receipt at intervals of up to 10 ms. It does not wait on a queue push or return 200 for a receipt observed after the deadline.

Valkey checks a blocked command's timeout on its server tick. With the default `hz 10`, a 200 ms bound ended after 297 ms. With `hz 100` it ended after 212 ms. A result that arrives is handed over at once, so only a timeout runs late. The Gateway's Valkey needs `hz 100` before slice 6 holds visits on this bound.

Two Pest files in `apps/gateway/tests/Feature/Infrastructure/Executor` test this. `ExecutorResultListenerTest.php` needs no server and runs in CI. It feeds the subscriber a fake Reverb stream and an in-memory queue, and covers:

- the sender and form checks, and the Operation of another Node
- the refusal's log, its Activity, and the Problem the collector records from it, with no broadcast
- a refusal that is still reported when the push fails
- the record before the push, and a repeat

`ExecutorResultWaitTest.php` tests the waiter. Its status and reason mapping, the `accepted` line and the bound run in CI, against an in-memory queue on a fake clock. Three of its tests start a real `valkey-server` with `hz 100` on a free port. They are in the `privileged` group, which the Gateway suite and CI exclude; run them with `vendor/bin/pest --group privileged` on a host with `valkey-server`. They measure what a fake cannot:

- the 60 s expiry
- a result 100 ms into the wait
- the timeout bound

## The wake inside the request

`ORBIT_EXECUTOR_WAKE_ENABLED` defaults to `false`. With both this setting and signed power routing off, the activation endpoint and `instance:wake` keep the Python/SSH wake. Either setting selects a signed `wake` order with the Instance id as its target. The Gateway records or joins `instance.wake` under the Instance lock before signing and sends the order through the observer to the Operation's Node.

The Gateway holds the visitor's request while the wake runs. One monotonic budget of `ORBIT_VISIT_WAKE_WAIT_SECONDS`, default 30 s, covers admission, publishing, waiting, and recording the success. The Gateway caps SQLite writer waits and Reverb HTTP calls by the remaining budget and checks the recorded receipt between short Valkey waits. A sibling can consume the Valkey copy without losing the result. `instance:wake` and other callers keep the `ORBIT_EXECUTOR_WAIT_MS` bound, default 500 ms.

Caddy returns any non-2xx `forward_auth` answer to the visitor unchanged. When the wake succeeds, the Gateway answers `307` with `Location` set to the visitor's own path and query, from `X-Forwarded-Uri`. The browser repeats the request with the same method and body, and the awake app answers it.

A `200` would continue the request only in the newer layout, where the app handlers follow the activation in one ordered route. Production's published routes keep the activation and the app in separate `handle` blocks, where a `200` ends the request as an empty page. The redirect works in both layouts without a route republish, at the cost of one round trip. The Gateway sends only a path on the visitor's own host: any other forwarded URI redirects to `/`.

A failed or refused result answers at once with `503` and one plain sentence that names the code. So does an Operation that ended without success. An example is `The wake failed (instance.container_start_failed). Reload the page to try again.`

A wake that has not ended within the budget answers `503` with `The Instance is still waking. Reload the page in a moment.` There is no progress page, script or reload; a reload is a new visit that joins or admits the wake. While other lifecycle work holds the Instance, the visit tries the admission again every 250 ms within the budget.

A container that the Python path froze is held too: the Node's resume runs within the visit, and its workflow if that resume fails. Subtask 6003 proved it on the nested fleet with container default Instance 2 (`webtest.nested-dev`), frozen by Python. The visit got the app's own page, `200`, in 2.07 s. With the app's web unit masked, the wake failed, and the visit got the plain `503` `The wake failed (instance.container_start_failed)` in 23.9 s.

Then, with the route replaced by the shape of production's `route-50.json` (separate `handle` blocks), a GET got the `307` in 1.5 s and the app's page on the repeat, and a POST with a body reached the app as a POST after the redirect. A visit while a sleep held the Instance, with a 0.2 s budget, got the plain `503` through that route.

### Wake handoff recovery

Admission records a queued wake with zero attempts and a pending completion. A publisher takes a non-blocking local lock and claims the committed Operation in a short transaction. That claim starts its one attempt. The publisher signs and sends outside the transaction and records a separate publication receipt. A crashed or uncertain send can be retried with a fresh nonce and the same Operation id; the executor acts once. A recorded result needs no resend.

A durable Conn launch uses a queued publisher after its launch and wake records commit. The launch cancel transaction cancels an unshared executor wake while it is still queued. A visitor or another launch joining it records shared use under the same admission lock, so cleanup cannot cancel or put back to sleep work that another caller is using. A late publisher will not open a cancelled wake.

The visitor does not enqueue on the request path. A newly admitted signed wake queues delivery after the response. A background pass on `operations-prompt` takes up to 50 completion handoffs and up to 50 hook handoffs. Recorded executor results take priority over publishing missing orders. Each set runs oldest first. The scheduler checks for eligible work every five seconds. A terminal Operation keeps its end, including when a late result arrives.

The pass records a lease in a short write, then enqueues outside the writer. A failed enqueue leaves pending work. If the job is lost or exhausts its retries, the handoff becomes eligible again 60 seconds after the job was due. Recovery also handles wakes admitted before the setting was turned off.

Success records a pending lifecycle follow-up with the terminal result. A local kernel lock prevents two hook workers from running it at once, even if the enqueue lease has expired. The hooks run without the SQLite writer; only then does the worker commit the delivery receipt. If it dies before that receipt, the next delivery reconciles any work already recorded by the hooks.

Conn reuses admitted session work. Its vault copy runs at native start. A failed retry of the queue push leaves the existing renewal able to start or finish. The wake worker leaves its hook undelivered and retries the same renewal.

Operation notices are saved with the change and relayed to `operations-prompt` after commit, keeping their recorded sequence. A retried relay may deliver the same sequence again. Vault and notice delivery stay out of the activation request and its termination callbacks.

### Signed power delivery

`ORBIT_EXECUTOR_POWER_ENABLED` is off by default. When on, sleep and wake use the existing Instance admissions and Operation kinds. The Gateway sends the signed order once, on the Node's WebSocket. That is the only delivery: the Gateway has no SSH fallback and starts no Swamp run for a lost result. The Operation then ends in one of these ways:

| Case | End |
| --- | --- |
| A receipt arrives | The receipt decides ([R1](/decisions/0266-sleep-and-wake-containers-through-a-signed-order-executor#four-rules-for-every-signed-outcome)). |
| No result by the six-second completion deadline | The Gateway reads the Node's journal for the order, as below. |

The deadline case covers a publish that threw, an order with no `accepted` line and a lost result. None of them proves what the Node did, so the Gateway reads the Node's executor journal for the order over SSH. The read is read-only and sends no order ([Reconcile by the journal](/decisions/0266-sleep-and-wake-containers-through-a-signed-order-executor#reconcile-by-the-journal)):

- A `done` record decides like a receipt, through the same completion as a result on time, also after the Operation ended `interrupted`.
- No record after the order expired on the Node fails the Operation with `executor.publish_failed` or `executor.not_accepted` and frees its lock.
- A `seen` or `open` record is read again after 1 s, and every 10 s after the first 30 s.
- A Node that cannot be read ends the Operation `interrupted` with `executor.result_unknown`. It keeps its lock, and the Gateway queues a new read every 10 s.
- A journal that cannot prove absence ends the same way. Its executor has no `journal.accepts` marker, or the order is older than 24 hours.
- Each read again is its own job on the `operations` queue, with a 2 s connect timeout. One unreachable Node delays neither another Instance's recovery nor a wake's completion.

A visitor's held request sees a failed Operation and gets the plain `503`.

One receipt continues in Swamp: a wake that fails with `instance.container_stopped`. The Operation starts its Swamp wake run, which boots the container; a nested default still requires deployment. The run's phase requests a fresh signature for the bound run and sends the order on the relay's stdin. The executor journal returns the first result for the same Operation and action, so it does not act again.

Admission binds a lifecycle phase to resume or discard before signing. Resume for a deploy or start does not probe the old app and leaves the asleep marker for the workflow to remove. A visitor wake still requires its readiness check. A result already recorded on the Operation takes precedence over a conflicting later result.

A delivered action cannot be cancelled. If the Operation ends without a terminal executor receipt, the Gateway reports an interruption, keeps the Instance fenced and reads the Node's journal again until it answers. Only a signing failure needs no hold: no order left the Gateway.

The signed `power_check` is `route` for a sleep with a published Route, `no_web` for a wake with no web unit, or `resume` for the resume of a deploy or start, whose workflow owns readiness and the marker. Any other pairing of check and action refuses with `bad_payload`. A wake otherwise requires the app to answer. The Gateway derives this choice from the admission; the executor keeps it in the open journal record for crash recovery.

The Gateway removes neither the container nor its marker on failure. It records the selected engine at admission.

### Switching engines

A frozen Instance belongs to the engine whose sleep froze it ([ADR 0266, R3](/decisions/0266-sleep-and-wake-containers-through-a-signed-order-executor#four-rules-for-every-signed-outcome)). The freezing sleep's `signed_power` input records it, and `ExecutorPowerIntent::forInstance()` and `lifecycle()` read it before the settings. Every wake goes through them: `instance:wake` and its API, the visitor's activation, Conn, and a start or deploy that resumes the freeze. So a recorded freeze needs no drain in either direction.

A freeze with no record has no owner. A failed Python sleep can leave the container frozen while the Gateway records it running. So the switch goes in this order:

1. Run [`orbit:executor-parity`](#build-parity) with the settings off. It is the one check before the switch. It covers build parity, unrecorded freezes and [activation proxies](#activation-proxy).
2. Wake each Instance it lists as `instance.frozen_unrecorded`. With the settings off, Python wakes it. Fix every other problem by its row in the table.
3. Run the check again. Turn on `ORBIT_EXECUTOR_WAKE_ENABLED` and `ORBIT_EXECUTOR_POWER_ENABLED` only when it passes.

When the settings go on, Instances that Python froze still wake through Python as they are used. Every sleep after the switch is signed, including the idle sweep's.

Turning both settings off is the one-step rollback. Instances that the executor froze still wake through it, with the visitor's request bound. Every new sleep goes back to Python.

Only before you retire the executor from a Node, wake the Instances that still have a Rust sleep plan, and check that every failed sleep left a running or recovered container. Until then keep the executor installed and reachable. Python stays installed until slice 8.

Subtask 5911 proved both directions on the nested fleet, with container default Instance 2, a Laravel app, in this order:

| Operations | Settings | What happened | Engine |
| --- | --- | --- | --- |
| Sleep 204 | Off | Froze | Python |
| Wake 205, by `instance:wake` | On | Resumed; the app then answered 200 | Python |
| Sleep 206, then wake 207 by a visit | Off for the sleep, on for the wake | The visit got the progress page; its next request got the app | Python |
| Sleep 208, wake 209 | On | Both `done` over the WebSocket | Executor |
| Sleep 210; wake 211, by `instance:wake` | On for the sleep, off for the wake | Both `done` | Executor |
| Sleep 212; wake 213, by a visit | On for the sleep, off for the wake | The visit got the app's own 200 in 353 ms | Executor |
| Sleep 214, wake 215 | Off | Froze, resumed | Python |

Then the same Instance was frozen in Incus while the Gateway recorded it running, the state a failed Python sleep (Operation 216) had left. `orbit:executor-parity` failed with `instance.frozen_unrecorded (Instance 2)`. Wake 222, with the settings off, resumed it through Python. The app answered 200 and the check passed. After the recorded Python sleep 223 the check passed with the container frozen, and wake 224 resumed it.

The Valkey host prerequisite is `hz 100`, applied by the orchestrator, as [the hand-off](#the-listener-and-the-hand-off) explains. Even then the server tick can make a timeout run a little late; a late successful reply must not authorize the original request.

### Slice 6 proof case

Subtask 5775 ran `executor-wake-visits` on the nested fleet of copy 3602. Clone 3 ran T3 0.1.7 and Vite 7.2.2. Only the nested Gateway enabled executor wakes, with the 500 ms bound and Valkey `hz 100`. Each signed sleep froze the clone and left 404,697,088–418,557,952 bytes in swap. The client timed real HTTPS through the clone's Caddy Route, from request start to the first body byte carrying the app's own response. A progress-first visit includes the wait for the first successful 250 ms poll; it does not count the progress page's bytes. Percentiles use nearest rank.

| Visitor path | Visits | App first | Progress first | Errors | p50 | p95 | Max |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| Signed executor wake | 50 | 21 | 29 | 0 | 953.989 ms | 2,431.347 ms | 3,380.199 ms |
| Existing Python path, same clone | 50 | 0 | 50 | 0 | 781.802 ms | 1,834.957 ms | 2,126.676 ms |

Each visit on both paths produced one successful `instance.wake` Operation. The executor returned 49 `done` results and one `partial` (`instance.restore_stalled`). Every signed wake ended running, removed the marker and closed the swap limit. The 500 ms bound applies to publication and result waiting, not to full client latency.

The earlier ADR 0266 reference was 1.5 s warm and 2.4 s cold. On this clone the executor bypassed the page for 21 visits, but Python had lower overall first-byte latencies. The Python sleeps froze into swap and skipped restore-plan capture as `placement_unproven` in all 50 visits. Both rows measure what visitors received; they do not compare equivalent restore work or reproduce the earlier warm and cold runs.

A separate in-bound POST returned the app's own 202 in 396.736 ms, preserving the method, encoded path, query and body in one exchange (Operation 283). With the executor paused until after the first response, Caddy returned `401 pending` in 686.749 ms; the unmodified poll reached the app at 1,007.345 ms (Operation 285). Dropping the Valkey result after the listener recorded it did not stop Operation 287 from succeeding. A signed repeat returned its first result in 10.272 ms, with one journal completion. An admission left without publication recovered on the first sweep without a new visit; a second sweep did no work, and Operation 289 recorded the successful follow-up receipt.

The case evidence is in `swamp/proof/node/converge.cases.yaml`. Raw timings, Operation records, journal matches and recovery receipts are under `.orbit-artifacts/5775/visitor-wake/` in the copy. The 108 signed Operations across the timed visits and four extra checks each match one Node journal completion. The shared runner did not run: it cannot yet prepare this clone and measure the visitor Route and poll. Both case files keep only that runner prerequisite.

#### Rerun with durable Conn launches

After merging durable Conn launches, the same nested clone ran 50 more visits on each path at merge `5c2d0edc25`. The signed path again used a 500 ms bound and Valkey `hz 100`. Every signed sleep froze into swap, and all 50 wakes returned `done`. Python again skipped plan capture as `placement_unproven`.

| Visitor path | Visits | App first | Progress first | Errors | p50 | p95 | Max |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| Signed executor wake | 50 | 41 | 9 | 0 | 630.099 ms | 1,012.139 ms | 1,427.739 ms |
| Existing Python path, same clone | 50 | 0 | 50 | 0 | 985.991 ms | 1,276.521 ms | 1,306.622 ms |

The original POST returned the app's own 202 (Operation 494), and the forced over-bound visit returned the progress page before the poll got the app (496). Both durable-result replay (498) and missing-publication recovery (500) passed. The real queue published a Conn-owned wake before any visit. A visitor then joined it and reached the app, and cancelling the launch left its shared wake succeeded (503). This checks the Conn wake child, not the first-message handoff. All 110 signed Operations had one journal completion each. The separate raw receipts are in `.orbit-artifacts/5775/conflict-2754/`.

## Checks

The executor crates set `[lints] workspace = true` and follow BeautifulRust ([ADR 0267](/decisions/0267-run-fast-node-actions-in-a-signed-order-executor#rust-rules-p9)): its lints are the workspace's `[workspace.lints]`, and `clippy.toml` sits beside it. From `apps/agent`:

```sh
cargo clippy --locked -p orbit-executor-core -p orbit-executor --all-targets -- -D warnings
cargo test --locked -p orbit-executor-core -p orbit-executor
```

The unit tests cover every order check and refusal, the caller policy, the journal and its compaction, the replay across a restart and the recovery of an open order. `crates/executor/tests/socket.rs` runs the core's relay entry point against a real socket; the shipped binary takes only `relay`, with the fixed socket.

The Gateway and the executor share one fixture, `apps/agent/contract/fixtures/executor-order.json`. A throwaway key signed it and was discarded, so it holds only the public key and the signed order. `crates/executor-core/tests/gateway_order.rs` verifies it with `order::check`, and the Pest test `tests/Feature/Domain/Executor/ExecutorOrderSignerTest.php` checks that the signer builds the same payload bytes. Pest tests also cover expiry, nonce uniqueness, the size cap and the publisher (`tests/Feature/Domain/Executor`). The observer's tests in `apps/agent/src/realtime.rs` run the pass-through against a fake socket: bytes unchanged, the 4 KB cap, 64 in flight, the 10 s and 64 KB reply bounds and a missing socket.

## The proof Operation

The Operation kind `executor.proof` records a no-op diagnostic on a Node. Each one is a real Operation row with `node_id` set to its Node ([ADR 0266](/decisions/0266-sleep-and-wake-containers-through-a-signed-order-executor) D7). Its target is the Node, as for `proof.noop`, and its input is `{"action": "proof.noop"}`. Its orders go out as `executor-order` on `presence-node.{id}`, and the listener records its result on it.

The kind's chart is [`docs/cli/proof/executor-proof.mmd`](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/proof/executor-proof.mmd). No page places it, because no command starts the kind. `ExecutorProofOperation` (`apps/gateway/app/Domain/Executor`) holds the whole kind:

1. `run` holds one proof run. The run records each Operation as `running` through the `start` closure it gets.
2. `send` signs a new order, with a new nonce, and publishes it. It refuses a closed Operation and every other kind.
3. `wait` waits for the result through `ExecutorResultWait` within `ORBIT_EXECUTOR_WAIT_MS`, as a waiting request does.
4. `finish` succeeds the Operation only after an observed `accepted` line followed by a matching `done` line. It stores the result on success.

A `done` line without prior acceptance fails with `executor.proof_not_accepted`. A `done` line with the wrong action, target or Operation id fails with `executor.proof_result_mismatch`. Other results fail with `executor.proof_<status>`.

When a nested proof run throws, `run` fails each open Operation it recorded with `executor.proof_aborted` and passes the exception to the runner. The operator diagnostic returns its recorded failure if signing, publishing or waiting throws.

The Gateway runs and closes the kind itself. It starts no Swamp run, and `proof.noop` changes nothing on the Node.

`tests/Feature/Domain/Executor/ExecutorProofOperationTest.php` covers the kind in CI, with a faked publish and an in-memory Valkey. It checks the row and its `node_id`, a new nonce for each order, a result the listener records reaching the waiter, a lost hand-off recovered by a repeat, a failed result, and that generic Operation starts stay closed. Two more tests make a run throw, once with Valkey down in a wait and once when Reverb refuses the third publish of a burst. Both check that no Operation is left `running`.

**Operator diagnostic.** Send `POST /api/v1/nodes/{node}/executor/proof` without a request body or query parameters. A body or query parameter returns HTTP 422 before the Gateway records an Operation or sends an order. The caller must be an active WireGuard peer with access to the target Node and Gateway authority. Gateway authority comes from the Gateway Node, its bootstrap serving host, or a grant to the active Gateway Node. The request sends one signed `proof.noop` order through Reverb, waits within `ORBIT_EXECUTOR_WAIT_MS`, and returns the terminal Operation as `data` with HTTP 200. Check `data.status` and `data.error_code` for the verdict. The signing key stays on the Gateway.

A peer with access to the target Node can cancel the Operation through `operation:cancel`. The first terminal verdict stands. An order already sent can still return a reply.

The nested proof runner can also use `tools/swamp-proof-guest/executor-order.php` as the nested Gateway's managed user. The generic `direct-operations/` paths refuse the proof kinds. The operator endpoint has no action parameter. The separate power proof is available only to the nested Gateway proof support.

**Recovering a lost result.** A result can reach Valkey and still be lost: the request that took it ended, or the key expired. The Gateway then sends a new order for the same Operation, with a new nonce. The executor finds the done record of that Operation, action and target, and answers `repeat` with the first result without acting. The listener changes no row, because the Operation already holds a result, but it pushes the copy again. The waiting request then gets it.

## Proof key

The Swamp proof of `node:converge` (`swamp/proof/node/converge.cases.yaml`) sends a signed `proof.noop` through the relay on the nested `app-dev` Node. Its runner step `executor_key` runs `tools/swamp-proof-guest/executor-order.php` on the nested Gateway as its managed user. It makes one Ed25519 key that only the proof uses: the secret half stays in that Gateway's own `storage/app/private/executor-proof.key`, mode `0600`, and becomes that Gateway's `ORBIT_EXECUTOR_SIGNING_KEY`; the public half goes into that Gateway's own `.env` as `ORBIT_EXECUTOR_PUBLIC_KEYS`. The step then clears the Gateway's config and restarts Horizon.

`executor_order` signs each order with the same script and hands it to the relay; a relay order names no Operation of the Gateway. `executor_publish`, `executor_round_trip` and `executor_lost_result` send orders over the WebSocket through the Gateway's own signer and publisher, so they reach the executor only through the observer. Each of their orders belongs to its own [`executor.proof` Operation](#the-proof-operation) on the Node, so the listener records its result like any other.

### Orders over the WebSocket

The step `executor_publish` has two options for the order security tests:

- `tamper` makes the order of each order security test: `bad_signature`, `changed_action`, `changed_target`, `expired`, `unknown_action` and `wrong_node`.
  - The `unknown_action` order names `proof.unknown`. No row of the order table holds it, so the order never runs.
- `replay` publishes each order twice, the exact same wire for the same Operation, before the step's one wait.

The copy therefore arrives inside the first one's 5 s expiry. The case expects the copy refused with `replayed_nonce` and the action run exactly once for that Operation. The two copies race through the observer, so the refusal may carry the first result (`first=done`) or not yet (`first=none`); both are correct. The executor's unit tests prove that a replay after the original completed carries the stored first result.

The cases `executor-order-security` and `executor-websocket-burst` (50 orders, with the latency from the Gateway's publish to the executor's result saved as evidence) use it. No key is committed, and the secret half never leaves the nested Gateway.

### Round trips and a lost result

Two cases prove the slice 3 row of ADR 0266: "50 round trips; result in a waiting request; a lost result recovered by a repeat".

**`executor-round-trip`** runs `executor_round_trip` with `count: 50`. The proof support runs 50 `executor.proof` Operations, one after another. Each one publishes its order and waits with `ExecutorResultWait`. Each trip needs three things:

- an observed `accepted` followed by `done` in the waiter;
- the same line recorded on its Operation by the listener;
- one `done` record for its Operation in the executor's journal.

The evidence `executor-round-trip-<operation>.json` holds every trip. It gives the latency from just before the publish to the result in the waiter as p50, p95 and max, the time to the `accepted` line, and the wait's bound.

**`executor-lost-result`** runs `executor_lost_result`. The proof support runs one `executor.proof` order. A first wait takes its result and drops it. The support then deletes the Operation's Valkey list, and a second wait must time out. Next it sends a new order, with a new nonce, for the same Operation. The step needs:

- `repeat: true` in the waiter;
- the same result as the first, apart from `repeat` and the Node's clock;
- the first result kept on the Operation, and the Operation `succeeded`;
- one `done` record for the Operation in the journal.

**Measured.** On 2026-11-17, task 5739 ran both steps by hand on its copy's nested fleet, without the converge. The executor and an observer with the pass-through were built from the branch and installed on the nested `app-dev` Node. The proof key was pinned through `executor_key`'s script. Each run made 50 round trips. Latency runs from the publish to the result in the waiting request:

| Run | p50 | p95 | max | `accepted` p50 | `accepted` p95 | `accepted` max |
| --- | --- | --- | --- | --- | --- | --- |
| Observer without `TCP_NODELAY` | 46.0 ms | 54.1 ms | 88.3 ms | 0.9 ms | 10.6 ms | 36.0 ms |
| With `TCP_NODELAY`, run 1 | 6.2 ms | 27.7 ms | 35.3 ms | 1.6 ms | 22.8 ms | 30.9 ms |
| With `TCP_NODELAY`, run 2 | 6.2 ms | 11.5 ms | 13.4 ms | 1.3 ms | 6.1 ms | 6.6 ms |
| With `TCP_NODELAY`, run 3 | 6.2 ms | 13.8 ms | 14.5 ms | 1.3 ms | 7.3 ms | 9.6 ms |
| With `TCP_NODELAY`, run 4 | 4.0 ms | 7.0 ms | 38.1 ms | 0.7 ms | 1.6 ms | 33.7 ms |
| With `TCP_NODELAY`, run 5 | 4.7 ms | 13.9 ms | 32.0 ms | 1.2 ms | 5.2 ms | 27.8 ms |

The executor wrote its `done` record 3–4 ms after the publish (median of 50 orders, `executor-websocket-burst`'s step). Every trip was `done`, recorded on its Operation and run once.

**The tail is not explained yet.** The `accepted` line shows the same tail: its p95 was 1.6–22.8 ms and its max up to 33.7 ms with `TCP_NODELAY`. That line leaves the executor at once, so the tail comes before the executor answers, on the publish or the Reverb side.

**The lost result.** In each of the six lost-result runs the second waiter timed out. Its bound was 500 ms (`ORBIT_EXECUTOR_WAIT_MS`), and it returned after 504–575 ms. This lateness is the one [the waiting request](#the-listener-and-the-hand-off) describes: Valkey checks a blocked `BLPOP`'s timeout on its server tick, and the nested Gateway's Valkey runs the default `hz 10`. The activation path requires the orchestrator to set `hz 100`. The repeat then brought the first result back in 7–19 ms. The journal held `open`, `done` and `seen` for that Operation: one run.

**Status.** The copy has the required 2,000,000 ID map and the Instance host’s stock image, so the proof plan omits `waits: idmap`. Both cases keep `fails_today`, because the pinned agent delivery has no `executor-linux-x86_64` and no observer release with the pass-through yet. The converge and no-op cases have not been rerun.


## Rust sleep and discard

Slice 4 adds the `power` family on `app-dev`. Signed `sleep` and `discard` orders target an Instance. `executor.power_proof` exercises them through durable Operations used only by the proof; production `instance.sleep` keeps the Python flow until slices 7/8. The observer still passes bytes only.

The executor records and atomically fsyncs every non-`max` swap limit in the whole container cgroup subtree before opening it. It freezes through the Incus local API, lists every subtree process and scans readable maps and pagemap in parallel. Its plan includes every readable present or swapped page (bits 63 and 62), excluding guard markers (bit 58), process start times, boot id, Incus incarnation and cgroup identity.

[Linux 7.0](https://github.com/torvalds/linux/blob/v7.0/fs/proc/task_mmu.c#L1975) reports lightweight guard markers inside readable mappings with both the swapped bit (62) and the guard bit (58); these markers contain no page to restore. Capture excludes them without excluding their readable neighbors. Mappings without read permission and the kernel special mappings remain excluded. The plan is atomically published with fsync before reclaim. Reclaim waits for `memory.current` to settle, for at most two seconds. The 32 MiB residual anonymous policy remains: ordinary containers stop when memory stays; nested-fleet containers resume and fail instead. The asleep marker comes last.

A repeat Operation returns its journaled result under the per-target lock. Startup repeats an open sleep and keeps a valid plan already published. `discard` removes the plan and limits record after a stop or fresh boot. Destroy sends no signed order: after it deletes the container it removes the Instance's folder under `/var/lib/orbit/executor/power` with the Python helper's files and the marker. A signed destroy is a follow-up (Orbit task 5891). The Rust state lives under `/var/lib/orbit/executor/power`; it does not replace the Python state yet. A container Incus calls running while the kernel holds it frozen refuses with `instance.container_frozen_unmanaged`, preserving its recovery state.

The kind chart is [`docs/cli/proof/executor-power-proof.mmd`](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/proof/executor-power-proof.mmd). The executor logs the complete action time, including receipt and asleep-marker fsync, with reclaim measured separately. The private receipt timer ends before its own publication.

**Measured on a real clone.** Subtask 5768 run 2 installed the fixed executor (SHA-256 `6c133c9cdf3d719b802791f23b7b46674548736d9cf6e06b2f671aafa985ce50`) on `nested-app-dev`. The existing `executor-sleep-clone` (Instance 4, creation/completion Operations 237/238) ran T3 0.1.7 and Vite 7.2.2, with Vite on port 8080. All 30 signed sleeps had durable `executor.power_proof` Operations, complete plans containing all 15 subtree processes, T3 and Vite included, and a committed asleep marker. Plans held 635,932,672–636,919,808 bytes.

The copy and Node had swap allowances of 12,884,901,888 and 5,368,709,120 bytes. The clone's own `memory.swap.max` was `0` before every sleep, `max` while asleep and `0` after every wake; sleep also recorded its inner 32 MiB polkit limit. No Incus swap setting was applied to the clone. Every sleep used swap. Rust left 14,544,896–16,154,624 bytes in RAM and 378,744,832–379,793,408 in swap. Python left 14,553,088–14,766,080 bytes in RAM and 380,137,472–381,841,408 in swap.

| Node measurement, 30 samples | p50 | p95 | max |
| --- | --- | --- | --- |
| Rust sleep, including receipt and marker fsync | 452.304 ms | 543.592 ms | 551.332 ms |
| Rust reclaim and counter settling, included above | 398.938 ms | 492.065 ms | 494.284 ms |
| Python marker, prepare and residual calls; capture skipped | 509.563 ms | 5,644.503 ms | 5,822.532 ms |
| Python reclaim call, included above | 293.000 ms | 500.000 ms | 511.000 ms |
| Python residual calls and settling, included above | 92.990 ms | 4,206.703 ms | 4,227.580 ms |

Percentiles use nearest rank. Both totals cover the Node's sleep work through final publication and the respective counter policy, excluding Gateway boot, transport and Ansible startup. Python ran the current helper through its production transient-unit path and residual retry rule, with a fixed Rust sleep/wake before each sample.

Python skipped plan capture as `placement_unproven` in all 30 samples. Its timings do not cover the same work as Rust and are not a like-for-like comparison. The nested fleet also cannot use remote `process_madvise`; the wake section explains this limit.

Raw samples, per-sleep RAM and swap, syscall timing, durable Operation records and the journal audit are under `.orbit-artifacts/5753/live-proof-5768/run-2/`. `rust-samples.jsonl`, `python-samples.jsonl`, `samples.csv` and `summary.json` hold the measurements. `durable-operations.json` and `journal-audit.json` were fetched from the nested Gateway and Node for Operations 380–439, 443–445 and 447: 60 single sleep/wakes, three burst wakes and one recovered wake. All 64 Operations succeeded, each with exactly one journal completion whose outcome is `done`, matching the Gateway's action, target and outcome; no mismatches were found. Journal compaction has removed their expired open/seen records.

This was manual live proof through the existing proof helper; the shared fleet-reset runner, converge install and production command paths were not rerun. CI proof remains disabled and its fresh fleet has no real T3/Vite clone fixture. Doctor reported healthy with seven checks and zero drift, but its Instance/Process families checked zero items; direct process, plan, cgroup and HTTP assertions supply that evidence.

| Error or result | Meaning |
| --- | --- |
| `instance.power_discard_failed` | The plan or limits record could not be removed. |
| `instance.container_freeze_failed` | Freeze, reclaim or the swap-full policy failed. Frozen recovery state stays available. |
| `instance.container_frozen_unmanaged` | Incus calls a container running while its kernel cgroup remains frozen. |
| `skipped` plan | Capture could not produce a complete plan within its bounds. Sleep continues, and the private receipt records the reason. |

## Rust wake

Slice 5 adds the `wake` action to the `power` family. Like sleep, only the proof-only `executor.power_proof` Operation sends it; the proof Operation succeeds on `done` and `partial`. Production `instance.wake` keeps the Python flow: slice 6 has the Gateway call this action, and slice 7 Swamp and the relay.

The wake reads the Incus state first. A stopped container drops its plan and fails `instance.container_stopped`, because a boot is the run's work. A frozen container gets these steps, in order.

**Plan bounds.** The plan's page size must be the system page size. Its processes, ranges and bytes stay within the capture's bounds, and each range is non-empty, page-aligned and ends below 2^56 without overflow. Otherwise the plan is dropped as `plan_invalid` before any work is built.

**Identity, before any memory syscall.** The plan's boot id, cgroup device and inode, and Incus incarnation must equal the live ones. Its init PID must be the container's. For each recorded process the executor opens a pidfd, compares the process's start time, and sends signal 0 through the pidfd. A PID reused before the open fails the start time; one reused after it fails the signal. Any mismatch drops the plan with no memory syscall. The wake goes on without a restore.

**Restore through the pool.** The plan's pages split into items of at most 1,024 pages. An item reads the process's pagemap and keeps only the pages that are not resident now. It calls `process_madvise(MADV_WILLNEED)` on them through the pidfd, so every swap read starts at once. Then it reads one byte of each page with `process_vm_readv`. A short read skips the failed page and continues through the rest of the item; the wake receipt and journal count unreadable pages as `pages_failed`.

On Linux 7.0, [remote `process_madvise`](https://github.com/torvalds/linux/blob/v7.0/mm/madvise.c#L2154) requires `capable(CAP_SYS_NICE)`, which [checks the initial user namespace](https://github.com/torvalds/linux/blob/v7.0/kernel/capability.c#L414). It returns `EPERM` in the nested fleet. The hint is optional: nested measurements read pages through `process_vm_readv` without the WILLNEED head start. The restore ends after 2 s. Items not started by then are skipped, and the result is `partial`.

An item still running stops at its next deadline check, and the wake waits for it for a grace of 250 ms.

An item still inside `process_madvise` or `process_vm_readv` after the grace is stalled: neither call can be cancelled, and a page fault can wait on a pager or a device for good. The wake then reports `partial` with `instance.restore_stalled` and goes on: it unfreezes, closes the limits, checks readiness and releases the target's lock. This is safe. A late `process_vm_readv` only copies the target's pages into the executor's own buffer, and a late `MADV_WILLNEED` only starts a read into the page cache. Neither changes anything in the target.

The stalled reader still guards its target. A later `sleep` or `discard` of the same target waits up to 10 s for it, so no freeze, scan or plan overlaps it. After that it fails `instance.restore_readers_busy`.

The stalled target leaves the front of the restore queue, so other targets go on. Its worker stays in the syscall until it returns, and the pool starts a replacement, so it keeps its configured number of working threads.

The pool holds at most twice its size in threads. Past that cap a new stall starts no replacement and is logged, and other targets get the working threads that remain. When a stalled syscall returns, its worker exits if the pool is at size again, and rejoins otherwise. A worker that fails to start is logged, and the pool runs with what it has. An item whose pagemap cannot be read, or whose read fails or comes back short, also makes the result `partial`.

**Unfreeze.** The executor unfreezes through the Incus local API, with the short freeze bounds of 5 s. It never unfreezes a replacement container, one with another incarnation. Then it waits up to 5 s for the kernel to thaw the cgroup. It never writes `cgroup.freeze` itself. A cgroup that stays frozen fails `instance.container_frozen_unmanaged`.

**Limits.** It writes back exactly the values of the limits record and removes the record. A record of another incarnation names cgroups that are gone. It is dropped, and nothing is written.

**Readiness.** The executor sends a request from the Node straight to the container's `eth0` address and web port 8080. It retries until an answer below 500, within one absolute bound of 1.5 s. Every connect, write and read gets only the time left, so a reply sent byte by byte cannot pass after the bound. Then the asleep marker goes. An unready app fails `instance.web_unready`, and the marker stays.

A container that already runs gets only the limits and readiness steps, and reports `done`. So crash recovery, which runs an open wake again, and a repeat after the unfreeze are safe. A container that Incus calls running while its kernel cgroup is still frozen fails `instance.container_frozen_unmanaged` with no write; Incus lost that freeze, and an operator thaws it. Nothing on the wake's path syncs to disk: the journal's syncer covers the order.

**The restore pool.** One per Node, with its own threads, separate from the connection threads, so order checks never wait behind a restore. The role writes its size to `/etc/orbit/executor/restore-threads`: 32, the measured size for the current Nodes' swap devices. Swap-in waits on the device, so the size follows the device's queue depth, not the CPU count. The executor refuses to start with fewer than 16 or more than 256. Jobs queue in arrival order. A target leaves the front of the queue only when every one of its items has finished, so no item of the next target starts before then.

| Result | Meaning |
| --- | --- |
| `done` | The container runs and answers. Every planned page that was not resident was read back, or the container already ran. |
| `partial`, `instance.restore_deadline` | The container runs and answers, but the 2 s bound cut the restore. |
| `partial`, `instance.restore_stalled` | The container runs and answers, but a restore read was still stuck in its syscall 250 ms after the bound. |
| `failed`, `instance.restore_readers_busy` | A `sleep` or `discard` found a stalled restore read of a wake of the same target still running after 10 s. |
| `partial`, `instance.restore_read_failed` | The container runs and answers, but a pagemap or a planned page could not be read. |
| `partial`, `instance.restore_no_plan`, `instance.restore_plan_stale`, `instance.restore_plan_invalid` | The container runs and answers without a restore: there was no plan, an identity check failed, or the plan could not be read or broke a bound. |
| `failed`, `instance.container_stopped` | The container is stopped; the run boots it. |
| `failed`, `instance.container_start_failed` | Incus did not report the same container running after the unfreeze, or another error. |
| `failed`, `instance.container_frozen_unmanaged` | The kernel cgroup stayed frozen. |
| `failed`, `instance.web_unready` | The app gave no answer below 500 within 1.5 s. The marker stays. |

The Rust tests cover the identity checks, pool order, the cut at the bound, resident pages, exact limits, the marker, readiness and crash recovery after the unfreeze; the restore tests read a real buffer of the test process.

**Measured on the same clone.** All 30 signed wakes returned `done`, answered HTTP 200, restored the clone's swap limit to `0` and removed the marker. Each read back 91,932–92,180 nonresident pages, with zero failed pages. Pages read p50/p95/max were 92,127/92,177/92,180; `samples.csv` records every wake's count.

| Wake measurement, 30 samples | p50 | p95 | max |
| --- | --- | --- | --- |
| Node action through the app answering and marker removal | 147.840 ms | 494.080 ms | 517.545 ms |
| Restore, included above | 122.675 ms | 417.062 ms | 478.530 ms |
| Incus unfreeze API, included above | 9.471 ms | 18.734 ms | 19.041 ms |
| Gateway publish through the waiting result | 164.158 ms | 520.478 ms | 535.378 ms |

Measured wakes 1–4 took 432–518 ms. Restore accounted for most of that time. The samples do not separate storage latency from scheduling.

The unfreeze measurement sums the local API's state-change request and operation wait, from socket connect to close. It excludes the surrounding identity/state reads and kernel-thaw polling. Wake samples used `strace` on connect, send and close calls; their totals include its overhead. The app check is the executor's direct Node-to-container request, not a visit through Caddy or the future Gateway fast path.

**Unreadable-page diagnosis.** A frozen-clone diagnostic found 39 unreadable guard markers in 16 readable anonymous mappings: systemd-journald 2, T3 16, rsyslogd 2, Python 1, polkitd 5, T3's resource monitor 1 and Vite's Node process 12. Every failure had pagemap bits 58 and 62 set and returned `EFAULT`. These were lightweight guards inside `rw-p` mappings with `VmFlags: gu`, rather than PROT_NONE reservations or VM_IO/PFNMAP mappings. Capture already excluded mappings without read permission; it now also excludes the guard markers while retaining their readable neighbors. Exact mapping bounds and failed page addresses, without memory contents, are in `diagnostic-mappings.json` and `diagnostic-guard-bits.json` under `run-2`.

A separate syscall diagnostic after the fix returned `done`, read 92,580 pages with zero failures and observed 129 `process_madvise` calls returning `EPERM`. All 129 `process_vm_readv` batches succeeded. This agrees with the Linux 7.0 capability rule above: these nested wake timings have no WILLNEED head start. The memory-read trace used raw syscall arguments, so it captured no target memory contents.

Instances 5 and 6 were also real clones with T3 and Vite. One simultaneous three-clone burst used the same 32-thread pool:

| Instance | Node wake to app answer | Restore, included | Incus unfreeze API, included | Pages read | Failed pages |
| --- | --- | --- | --- | --- | --- |
| 6 | 238.297 ms | 193.382 ms | 13.950 ms | 91,399 | 0 |
| 5 | 402.372 ms | 354.889 ms | 8.632 ms | 90,808 | 0 |
| 4 | 484.466 ms | 450.542 ms | 11.867 ms | 92,451 | 0 |

All three answered HTTP 200 and returned `done`. Restore time includes waiting behind an earlier target. The last result arrived 1,163.346 ms after launching the three Gateway helper processes; that scope includes their boot time.

**SIGKILL recovery passed.** The first restore syscall was delayed by 1 s for fault injection. The executor was killed while Instance 4 was frozen, with its plan intact and swap open. systemd restarted it, and startup replayed open Operation 447. The recovered wake read 92,311 pages with zero failures. The app answered HTTP 200 after 2,352.641 ms from the kill; its cgroup thawed, swap returned to `0`, and the plan and marker were removed.

The first waiter timed out. A fresh signed order for the same Operation returned `done` with `repeat: true` in 4.774 ms, and the Operation succeeded. The current journal audit retains exactly one completion for Operation 447, with outcome `done`. Raw evidence is in `burst.json`, `crash-node.jsonl`, `crash-gateway.jsonl` and `crash-recovery-journal.txt` beside the sleep samples.
