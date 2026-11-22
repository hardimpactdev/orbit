---
title: "App-dev runtime hibernation"
description: "How Orbit stops the Processes of idle development Instances, prunes their dependencies after a long idle period, and wakes them on the next HTTP request."
covers:
  - apps/gateway/app/{Actions,Domain,Infrastructure}/Hibernation/**
  - apps/gateway/app/Http/Controllers/Api/RuntimeActivationsController.php
  - apps/gateway/config/orbit.php
---

# App-dev runtime hibernation

Development Instances often leave Processes such as Vite running long after anyone stops using the site. Orbit stops idle development Processes after one hour of inactivity and deletes lockfile-rebuildable dependencies (`vendor` and `node_modules`) after one week. The next HTTP request waits while Orbit restores dependencies, starts Processes, and serves the app.

Hibernation settings live under the `hibernation` key of [`config/orbit.php`](https://github.com/hardimpactdev/orbit/blob/main/apps/gateway/config/orbit.php).

## What hibernates

Node-level Process hibernation applies when every condition holds:

| Condition | Value |
| --- | --- |
| Owner | An Instance. |
| Instance | A development checkout Instance on a Node with an active `app-dev` role. |
| Desired state | `running`. Stopped Processes stay stopped. |
| `keep_alive` | `false`. |

[Container Instances](/reference/development-containers#sleep-and-wake) are excluded from per-Process hibernation; the same sweep sleeps and wakes the container as a whole through the same activation endpoint.

Hibernation never touches Node Processes, production Instances, Schedules, PHP-FPM services or pools, or Caddy socket paths.

## Keep a Process running

Set `--keep-alive` on a Process or Process definition to keep it running while the Instance sleeps (for example, a queue worker that must stay online):

```bash
orbit process:create queue --instance=12 --runtime=systemd \
  --command=/usr/bin/php --command=artisan --command=queue:work \
  --restart=on-failure --keep-alive --start
```

Waking an Instance still starts any desired-running `keep_alive` Process that is down. `process:stop` sets `desired_state=stopped`, and wakes leave stopped Processes stopped. In a container Instance, a desired-running `keep_alive` Process keeps the entire container awake.

## Idle window and sweep

`orbit-runtime-hibernator.timer` runs on the Gateway every 10 minutes. For each Instance, it reads the newer of the Caddy access log timestamp and the awake marker. The same sweep also puts eligible [development containers](/reference/development-containers#sleep-and-wake) to sleep according to `ORBIT_HIBERNATION_CONTAINER_SLEEP`.

Configure hibernation in the Gateway's environment (not an Instance's `.env`):

| Setting | Default | Config key | Environment key |
| --- | --- | --- | --- |
| Idle window | 3,600 seconds | `orbit.hibernation.idle_seconds` | `ORBIT_HIBERNATION_IDLE_SECONDS` |
| Dependency idle window | 604,800 seconds | `orbit.hibernation.dependency_idle_seconds` | `ORBIT_HIBERNATION_DEPENDENCY_IDLE_SECONDS` |
| Sweep interval | 600 seconds | `orbit.hibernation.sweep_seconds` | `ORBIT_HIBERNATION_SWEEP_SECONDS` |
| Wake timeout | 60 seconds | `orbit.hibernation.wake_timeout_seconds` | `ORBIT_HIBERNATION_WAKE_TIMEOUT_SECONDS` |
| Cold wake timeout | 1,800 seconds | `orbit.hibernation.cold_wake_timeout_seconds` | `ORBIT_HIBERNATION_COLD_WAKE_TIMEOUT_SECONDS` |
| Container idle sleep | Off | `orbit.hibernation.container_sleep` | `ORBIT_HIBERNATION_CONTAINER_SLEEP` (`false`, `without_t3`, or `true`) |
| Containers that never sleep | None | `orbit.hibernation.container_never_sleep` | `ORBIT_HIBERNATION_CONTAINER_NEVER_SLEEP` (comma-separated Instance IDs) |

`ORBIT_HIBERNATION_CONTAINER_SLEEP` accepts three values: `false` (or unset) disables automatic container sleep, `without_t3` sleeps only containers without a T3 server (such as defaults; clones stay awake with reason `t3_server`), and `true` sleeps all eligible development containers. Any unrecognized value falls back to `false` and logs one error until the value changes or cache clears. Dry runs print decisions for each mode side by side.

After the idle window, the sweep stops each non-`keep_alive` desired-running Process without changing `desired_state` and removes the awake marker. Instances with no eligible Processes never sleep and are never pruned. An Instance with no recorded HTTP activity counts as idle and sleeps on the first sweep.

### Dependency prune

The same sweep deletes `vendor` and `node_modules` when all of the following hold:

- The Instance is asleep and not already marked cold.
- No HTTP activity occurred during the dependency idle window.
- No Process record changed during that window.
- No checkout file outside `vendor`, `node_modules`, and `.git` changed during that window.
- No desired-running Process has `keep_alive: true`.

For Laravel Instances, pruning and restoration operate in the [application directory](/reference/projects#application-directory) (source activity checks still cover the entire checkout). Orbit deletes `vendor` only beside `composer.json` and `composer.lock`, and deletes `node_modules` only beside `package.json` and a single JavaScript lockfile. It never follows symlinks, keeps every lockfile, and writes the cold marker. The [dependency view](/reference/instance-dependencies) remains available while cold because it reads repository evidence through the Gateway.

## Wake

Caddy checks for the awake marker (or container asleep marker) on every incoming request. When an Instance is asleep, Caddy calls `GET /api/v1/runtime-activations/app-instance/{id}` on the Gateway over WireGuard.

The Gateway holds the request while the Instance wakes (up to `ORBIT_VISIT_WAKE_WAIT_SECONDS`, default 30 seconds, for containers) and returns one of four responses:

| Outcome | Answer |
| --- | --- |
| Wake succeeded | `307` to the same path and query; the browser repeats the request and reaches the app. |
| Wake failed | `503` `The wake failed (<error_code>). Reload the page to try again.` |
| Wait deadline passed | `503` `The Instance is still waking. Reload the page in a moment.` |
| Other lifecycle work held the Instance | `503` `The Instance is busy with other work. Try again shortly.` |

For a container Instance, the Gateway admits or joins the single `instance.wake` Operation, using [signed power delivery](/reference/executor#signed-power-delivery) or [prepared restore](/reference/prepared-restore) to resume frozen containers quickly, falling back to the full wake workflow if needed. While other lifecycle work holds the Instance, the held request retries admission every 250 ms until the wait deadline. An interrupted wake with an unknown order fences further wakes until an operator inspects the Node.

For a checkout Instance, the wake runs in order:

1. If the cold marker exists, restore dependencies with `composer install --no-interaction --prefer-dist` and `vp install --frozen-lockfile` (using the cold wake timeout).
2. Start every desired-running Process. `vp-dev` uses its recorded port and never reassigns or republishes Caddy.
3. Wait until each Process is running (polled every 0.5 seconds) and `vp-dev` answers on its [assigned Vite port](/reference/assigned-vite-ports).
4. Clear the cold marker and write the awake marker.

Concurrent requests share one wake lock. If a recorded Vite port is unavailable or its Caddy upstream is stale, wake fails without changing the port or writing the awake marker; run [`process:start`](/cli/process/start) to recheck the port and republish Caddy. Explicit `process:start` for Vite marks the Instance awake even when Vite was already listening.

While an Instance is [migrating into a container](/cli/instance/migrate), its awake marker is removed and requests receive `503` (`Instance [<name>] is moving into a container. Try again when the move has ended.`) until migration finishes.

### Probe requests

Requests with header `X-Orbit-Probe: 1` skip the wake check and are excluded from access logs. [`profile --instance`](/cli/profile) sends this header so profiling never wakes a sleeping Instance or resets its idle timer.

### After a reboot

Awake markers live in `/dev/shm`, so a reboot clears them. Development Process units are never enabled at boot, so the first HTTP request after a reboot wakes the Instance.

## Host directories

`app-dev` role setup creates the marker and access-log directories before publishing Caddy sites:

| Path | Owner | Mode | Use |
| --- | --- | --- | --- |
| `/dev/shm/orbit/hibernation` | `root:caddy` | `0755` | `app-instance-{id}.awake` markers. |
| `/data/caddy/orbit/hibernation` | `root:caddy` | `2775` | `app-instance-{id}.log` access logs, `app-instance-{id}.cold` markers, and `app-instance-{id}.asleep` container markers. |

Parent directories below protected roots (`/dev/shm`, `/tmp`) are set to `0755` so Caddy can traverse them, and Caddy configuration is validated as the `caddy` user so new log files stay writable.

## Docker Processes

Development Instance Docker Processes map restart policy `always` to `unless-stopped` so an idle stop survives a Docker daemon restart.

## Inspect

While an Instance's awake marker is absent, [Doctor](/cli/doctor) does not flag stopped desired-running Processes as drift unless `keep_alive` is `true`. `process:list --instance=ID` shows desired state, observed state, and `keep_alive`.

## Why it works this way

### Restart policy is not keep-alive

A restart policy handles crashes while a Process is active; `keep_alive` is a separate setting for Processes that must stay up during idle sleep.

### Schedules keep running

Schedules run independently so recurring jobs fire on time even when an Instance's interactive Processes are asleep.

### PHP-FPM stays up

Development sites share one PHP-FPM master per PHP version, and each pool already shuts down idle workers. Stopping PHP-FPM would disrupt other sites.

### The visit waits for the app

Holding the initial HTTP request until the wake finishes and answering `307` gives the browser the real page after one redirect without custom loading pages, polling scripts, or failure state headers. Both soft and cold wakes share the same Caddy intercept contract.

### Keep-alive blocks the prune

A `keep_alive` Process can still require `vendor` or `node_modules`, so Orbit never prunes dependencies while one is desired running.
