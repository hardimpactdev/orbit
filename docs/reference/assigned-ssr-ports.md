---
title: "Assigned SSR ports"
description: "How Orbit gives each development Instance its own Inertia SSR port, and the environment contract that applications follow so two task workspaces on one Node never share an SSR server."
covers:
  - apps/gateway/app/Domain/AppDev/SsrEndpoint.php
  - apps/gateway/database/migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php
---

# Assigned SSR ports

Multiple development Instances on one Node can run an Inertia server-side rendering (SSR) server at the same time. Inertia defaults to port `13714`, so pinning a fixed port makes two workspaces share one SSR server and render each other's HTML. Orbit assigns each development Instance its own `ssr_port` and passes it through the environment.

## Assignment

Orbit assigns `ssr_port` when creating or registering a development Instance (production Instances receive none). Assignment stores a preference and creates no Process.

If the Instance already has a recorded port on the Node, Orbit keeps it; otherwise it searches upward from `13714` through `65535` under the Node's operation lock, skipping:

- SSR ports held by other Instances on the Node.
- [Vite ports](/reference/assigned-vite-ports) assigned on the Node.
- Ports bound by any IPv4 or IPv6 TCP socket on the Node (except `TIME_WAIT`).
- The same common service ports skipped by Vite port assignment.

Orbit never kills an existing listener; it skips occupied ports. `instance:show`, the API, and the SDK return `ssr_port`. The assignment survives hibernation, Process replacement, and reboots. Removal releases it with the Instance. A [transfer](/reference/instance-transfer) assigns a new port on the destination and releases the source port after cleanup. An Instance without an assigned `ssr_port` receives one when recreated.

## Application setup

Every Process of a development Instance with an `ssr_port` receives these variables (as do [Project checks](/reference/tasks#project-check), setup steps, and command deliverables). When an Instance has no `ssr_port`, Orbit leaves any Process-defined values untouched.

| Variable | Value |
| --- | --- |
| `ORBIT_SSR_PORT` | The assigned port. |
| `INERTIA_SSR_URL` | `http://127.0.0.1:<ssr_port>` |

Applications running in Orbit workspaces should read these variables rather than pinning a port:

- **SSR build:** do not bake a fixed port into the SSR bundle at build time.
- **SSR server:** read `process.env.ORBIT_SSR_PORT` at startup.
- **Laravel:** configure `ssr.url` in `config/inertia.php` with `env('INERTIA_SSR_URL', 'http://127.0.0.1:13714')`.
- **Browser and SSR tests:** point test runners at `ORBIT_SSR_PORT` and `INERTIA_SSR_URL`.

Keep `13714` only as the fallback when the variables are unset (such as on a local laptop).

## Why it works this way

### Mirror the Vite port

Using the same stored-port model and live socket probe as [Assigned Vite ports](/reference/assigned-vite-ports) gives operators one predictable pattern.

### Environment, not discovery

Because Orbit cannot inspect what port an SSR bundle hardcodes, handing the port through standard environment variables lets each application own its SSR server without a custom preset.

### Never take a port back

Skipping occupied ports avoids disrupting another workspace or external process already listening on `13714`.
