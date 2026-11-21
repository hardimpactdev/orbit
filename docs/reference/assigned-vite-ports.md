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

Multiple development Instances can run Vite on the same Node. Host checkout Instances each get a unique host `vite_port`, while container Instances have isolated network namespaces. The `vp-dev` Process preset runs Vite on that port and connects it to Caddy and [hibernation wake](/reference/app-dev-runtime-hibernation). Browsers never see the port directly: they load assets through the Route's HTTPS origin.

## Assignment

Orbit assigns `vite_port` when creating or registering a development checkout Instance (production Instances receive none). Assignment stores a preference and creates no Process.

If the Instance already has a recorded port on the Node, Orbit checks it first; otherwise it searches upward from `5173` through `65535` under the Node's operation lock, skipping:

- Ports held by other Instances on the Node.
- Ports bound by any IPv4 or IPv6 TCP socket on the Node (except `TIME_WAIT`).
- Common service ports: `3306`, `5432`, `5672`, `6379`, `8000`, `8080`, `8443`, `9000`, `9090`, `9200`, `11211`, `15672`, and `27017`.

`instance:list`, `instance:show`, the API, and the SDK return `vite_port`. The assignment survives hibernation, dependency pruning, Process replacement, and reboots. Removal releases it after cleanup, and [transfer](/reference/instance-transfer) assigns a new port on the destination and releases the source port after cleanup.

## The vp-dev preset

Create the preset Process on a development Instance (`--instance` accepts an Instance ID or exact Route domain):

```bash
orbit process:create vite --instance=orbit.test --preset=vp-dev --start
```

The preset requires `/usr/local/bin/vp`, a readable `package.json`, and an installed `node_modules` in the Instance's [application directory](/reference/projects#application-directory) (checkout root for non-Laravel apps). It installs no dependencies, sets `restart_policy: on-failure`, and refuses custom commands, runtimes, or Docker flags. An Instance may have at most one `vp-dev` Process; see [Processes: Presets](/reference/processes-and-schedules#presets).

On a checkout Instance, the unit binds loopback strictly and reads its port from `/etc/orbit/vite/app-instance-<id>.env`:

```ini
EnvironmentFile=/etc/orbit/vite/app-instance-<id>.env
UnsetEnvironment=VITE_DEV_SERVER_CERT VITE_DEV_SERVER_KEY
ExecStart=/usr/local/bin/vp dev --host=127.0.0.1 --port=${ORBIT_DEV_SERVER_PORT} --strictPort --base=/__orbit/vite/
```

Unsetting the certificate variables keeps Vite on plain HTTP behind Caddy TLS.

## Migrated container presets

[Migration](/cli/instance/migrate) carries a supported `vp-dev` Process into its Instance container with its identity, assigned port, and desired state intact. Inside the container, it runs `/opt/orbit-runtime/current/bin/vp` bound to `0.0.0.0` with its port set directly in the unit, and Caddy proxies `/__orbit/vite` to the container's bridge address and port.

New container copies of captured `vp-dev` definitions use port `5173` independently inside their own network namespace without host port allocation.

## Start, restart, and wake

On a checkout Instance, an explicit start or restart prepares Vite before serving traffic:

1. If the Instance's Vite already answers on the assigned port, start returns without restarting or disrupting traffic.
2. Otherwise, Orbit rechecks the port (up to three attempts on conflict), writes the Vite environment file, and updates Caddy's `/__orbit/vite` handler.
3. Orbit starts Vite, verifies its listener, and marks the Instance awake on explicit `process:start`.

A [hibernation wake](/reference/app-dev-runtime-hibernation#wake) uses only the recorded port without reassigning ports or republishing Caddy; if the port is unavailable, run [`process:start`](/cli/process/start) to recheck and republish. If Vite does not bind or answer in time, start fails with `vite.listener_unavailable` (on direct Process control) or `vite.not_ready` (on checkout readiness failure).

## Application setup

Caddy proxies `https://<domain>/__orbit/vite/` to the assigned port while keeping the `/__orbit/vite/` prefix. Every Process of a Route-backed development Instance receives these variables (the [`vite` preset](/reference/processes-and-schedules#the-vite-preset) never receives `ORBIT_DEV_SERVER_PORT`; Route-less migrated presets receive only `ORBIT_DEV_SERVER_PORT`):

| Variable | Value |
| --- | --- |
| `ORBIT_DEV_SERVER_ORIGIN` | `https://<domain>/__orbit/vite` |
| `ORBIT_DEV_SERVER_HOST` | The Route domain. |
| `ORBIT_DEV_SERVER_PATH` | `/__orbit/vite` |
| `ORBIT_DEV_SERVER_PORT` | The assigned port. |

Point Vite's `server.origin` at the origin of `ORBIT_DEV_SERVER_ORIGIN` and `server.hmr.path` at `hmr`. The `vp-dev` unit also sets `__VITE_ADDITIONAL_SERVER_ALLOWED_HOSTS` to the Instance's active Route hostnames (primary first, comma-separated) so stock `laravel-vite-plugin` accepts the Instance's domains.

The `vp-dev` unit does not load the application `.env` as a systemd `EnvironmentFile` so Orbit's unit settings cannot be overridden by `.env`. Vite loads `.env` directly; if your `vite.config` reads an application variable from `process.env`, use Vite's `loadEnv` instead. Do not pin a manual port or add `keep_alive` just to start on wake (`--start` handles wake automatically).

## Why it works this way

### A stored port, not discovery

Letting Vite choose a random port would require Orbit to discover it from framework-specific files like `public/hot` or a custom plugin. Storing one port per checkout Instance and running `--strictPort` works for every Vite app.

### Unique within the network namespace

Checkout Instances share the host network and need unique host ports; container Instances have isolated network namespaces and can reuse ports independently.

### An explicit preset

Storing the preset explicitly connects start, restart, wake, and transfer to port preparation without guessing from Process names or commands. The [`vite` preset](/reference/processes-and-schedules#the-vite-preset) runs on fixed port `5173` inside containers and needs no host port assignment or `/__orbit/vite` proxy path.
