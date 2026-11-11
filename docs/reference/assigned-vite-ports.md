---
title: "Assigned Vite ports"
description: "How Orbit gives each development Instance its own Vite port, and how the vp-dev Process preset connects that port to systemd, Caddy, and wake."
covers:
  - apps/gateway/app/Domain/AppDev/{VitePortAllocator,VitePortRuntime,ViteProcessLifecycle,DevelopmentServerEndpoint}.php
  - apps/gateway/app/Infrastructure/AppDev/RemoteVitePortRuntime.php
  - apps/gateway/app/Domain/Processes/VpDevPreset.php
  - apps/gateway/database/migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php
---

# Assigned Vite ports

Several development Instances can run Vite on one Node. Checkout Instances each hold a unique host Vite port; container Instances have separate network namespaces. The `vp-dev` Process preset runs Vite on that port and connects it to Caddy and to [wake](/reference/app-dev-runtime-hibernation). Browsers never see the port: they load assets through the Route's HTTPS origin.

For checkout Instances, the Gateway calls the [Node agent](/reference/node-agent#commands) to select a port, check its listener, and write the Vite environment and traffic markers. The Gateway owns the stored assignment and the retry policy.

## Assignment

For checkout Instances, Orbit assigns `vite_port` when it creates or registers a development Instance. Production Instances get none. An assignment creates or starts no Process. Caddy, wake, and the `vp-dev` preset use only that assigned port.

When this Instance already has a recorded port on the Node, the search starts there. When it has no recorded port, the search starts at `5173` and moves up to `65535`. It never tries a port below its start. It skips:

- ports that other Instances on the Node hold,
- ports that any TCP socket on the Node uses, over IPv4 or IPv6, except in `TIME_WAIT`,
- common service ports: `3306`, `5432`, `5672`, `6379`, `8000`, `8080`, `8443`, `9000`, `9090`, `9200`, `11211`, `15672`, and `27017`.

Two Nodes can use the same port. Host reservations keep each checkout port unique per Node through the assignment table. The search runs under the Node's operation lock and fails when no port is left.

`instance:list`, `instance:show`, the API, and the SDK return `vite_port`. The assignment is a stored preference, not an open socket. It survives hibernation, dependency pruning, Process replacement, and reboots. Removal releases it after runtime cleanup. A [transfer](/reference/instance-transfer) assigns a port on the destination and releases the source port after cleanup.

## The vp-dev preset

Create the preset Process on a development Instance. `--instance` accepts an Instance ID or its exact Route domain.

```bash
orbit process:create vite --instance=orbit.test --preset=vp-dev --start
```

The preset needs `/usr/local/bin/vp`, a readable `package.json`, and an installed `node_modules`. It installs no dependencies and edits no application code. It sets the command, the working directory, and restart on failure. A custom command, runtime, or Docker option conflicts with the preset and is refused. An Instance has at most one preset Process. Naming a plain Process `vp-dev` has no effect. The [Processes](/reference/processes-and-schedules#presets) page lists every preset.

For a Laravel Instance, preparation checks `package.json` and `node_modules` in its [application directory](/reference/projects#application-directory), and the Process runs from that same directory. With root `server/web/public`, both prerequisites live in `<checkout>/server/web`, and Laravel's Vite plugin writes `<checkout>/server/web/public/hot`. Root `public` keeps these paths at the checkout root. Non-Laravel Instances keep checkout-root prerequisite checks and working directories. Orbit does not install missing dependencies during preparation.

On a checkout Instance, the preset runs Vite on loopback with strict binding:

```ini
EnvironmentFile=/etc/orbit/vite/app-instance-<id>.env
UnsetEnvironment=VITE_DEV_SERVER_CERT VITE_DEV_SERVER_KEY
ExecStart=/usr/local/bin/vp dev --host=127.0.0.1 --port=${ORBIT_DEV_SERVER_PORT} --strictPort --base=/__orbit/vite/
```

Orbit writes `ORBIT_DEV_SERVER_PORT` to the environment file before each start. Systemd reads it at start, so a new port needs no unit change. The removed certificate variables keep Vite on plain HTTP; Caddy terminates TLS.

## Migrated container presets

[Migration](/cli/instance/migrate) carries a supported `vp-dev` Process into its Instance container. The Process keeps its identity, assigned port and desired state. It uses `/opt/orbit-runtime/current/bin/vp`, binds `0.0.0.0`, and reads its mapped workspace environment file and private Process environment. Its unit carries the assigned port directly; it needs no Node Vite environment file. Start and restart use the same container systemd controls as other Processes and do not reassign a host port.

New container copies of captured preset definitions use `5173` independently. Migrated Instances keep their recorded ports. Orbit uses no host allocation or listener probe for container ports. A recorded conflict inside the same container refuses; readiness detects an unmanaged listener that prevents binding.

Only the Node can reach the assigned Vite port in the container. Caddy sends `/__orbit/vite` and its descendants, including HMR, to that bridge address and port. The shared container intent preserves the ingress rule after a deployment. Unknown preset versions, custom commands, external paths and recorded port conflicts refuse before migration downtime.

## Start, restart, and wake

An explicit start or restart of the preset on a checkout Instance runs one preparation step. These preparation, port reassignment and readiness rules apply to checkout Instances; migrated presets use container systemd controls:

1. If the preset's Vite already answers on the port, start returns without restarting it.
2. This also applies to automatic starts during wake, transfer restore, and activation. Orbit does not suspend traffic or re-project Caddy for a healthy endpoint.
3. An explicit start marks the Instance awake only after Vite is ready.
4. An automatic start that must prepare Vite suspends traffic and may clear the awake marker.
5. Orbit checks the port again. Another listener on the port makes Orbit pick a new port. The other listener keeps running.
6. It writes the environment file and points the workload Caddy's `/__orbit/vite` path at the port.
7. It starts Vite and waits until Vite answers the client request on that port.

A [wake](/reference/app-dev-runtime-hibernation#wake) uses only the recorded port and publishes nothing. If that port is unavailable, the wake and its retries fail without changing the assignment. Use [`process:start`](/cli/process/start) to recheck and publish the assignment.

Explicit starts retry with a new port when another listener takes it between the check and bind. It makes at most three attempts within the deadline. Another startup error does not change the port. After `process:restart` of the preset, the next HTTP request goes through the wake page. An explicit `process:start` marks the site awake after readiness. An automatic start preserves the awake marker only when its owned endpoint is already ready; a start that must prepare Vite suspends traffic and may clear the marker. When Vite does not become ready, the start fails with `vite.not_ready`.

## Application setup

The workload Caddy proxies `https://<domain>/__orbit/vite/` to the port and keeps the `/__orbit/vite/` prefix. Every Process of a development Instance with a Route gets these variables. Without a Route, only a migrated preset receives `ORBIT_DEV_SERVER_PORT`; origin, host and path remain absent:

| Variable | Value |
| --- | --- |
| `ORBIT_DEV_SERVER_ORIGIN` | `https://<domain>/__orbit/vite` |
| `ORBIT_DEV_SERVER_HOST` | The Route domain. |
| `ORBIT_DEV_SERVER_PATH` | `/__orbit/vite` |
| `ORBIT_DEV_SERVER_PORT` | The assigned port. |

Set Vite's `server.origin` to the origin of `ORBIT_DEV_SERVER_ORIGIN` and `server.hmr.path` to `hmr`. Configure assets and HMR for the Route origin. Laravel's plugin writes the public URL into `public/hot`, but Orbit does not read that file. Plain Vite applications use the same setup.

The `vp-dev` unit sets `__VITE_ADDITIONAL_SERVER_ALLOWED_HOSTS` to the Instance's authoritative Route hostnames, primary first and then Route ID order, separated by commas without schemes or ports. A stock `laravel-vite-plugin` configuration has no host list, so Vite would otherwise refuse the Instance's hostname.

The unit renders on Process create/update and container clone, migration and deployment; later Route changes reach Vite at the next such render, as with `ORBIT_DEV_SERVER_HOST`, and existing container Instances receive this setting at their next deployment.

Withdrawn Routes are excluded; initial creation includes its reserved primary Route before publication.

Let Orbit pick the port. Do not select one by hand, and do not add `keep_alive` only to make the Process start on wake: `--start` already does that.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### A stored port, not discovery

Vite can pick another port by itself, but Orbit would then have to find out which one. `public/hot` exists only for Laravel, and behind the proxy it holds the public URL, not the local port. A discovery plugin would add a dependency to every application. So Orbit stores the port and runs Vite with `--strictPort`.

### Unique within the network namespace

Checkout ports share the Node network, so their reservations are unique per Node. Container ports belong to separate network namespaces and can repeat.

### No socket held while asleep

For checkout Instances, the stored host assignment keeps other checkout Instances off the port. A process outside Orbit can still take it, and strict binding plus a new check at start handle that case.

### An explicit preset

Orbit does not guess from a Process name or command that it runs Vite. The stored preset connects start, restart, wake, and transfer to port preparation.

The `vp-dev` Process preset uses the assigned Vite port for a development Instance and does not apply to Node or Project targets.

The `vp-dev` unit does not load the application `.env` as a systemd `EnvironmentFile`, on either a host checkout or a container. Vite loads that file itself, so workspace edits follow Vite's normal reload behavior and live references can expand. Orbit's own unit settings remain authoritative: systemd gives an `EnvironmentFile` precedence over `Environment=` regardless of directive order, so passing the app file would override them. Vite configuration that reads an application key from `process.env` must use `loadEnv` instead.
