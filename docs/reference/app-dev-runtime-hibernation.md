---
title: "App-dev runtime hibernation"
description: "How Orbit stops the Processes of idle development Instances, prunes their dependencies after a long idle period, and wakes them on the next HTTP request."
covers:
  - apps/gateway/app/{Actions,Domain,Infrastructure}/Hibernation/**
  - apps/gateway/app/Http/Controllers/Api/RuntimeActivationsController.php
  - apps/gateway/config/orbit.php
---

# App-dev runtime hibernation

A development Instance often runs Processes, such as Vite, long after anyone uses the site. Orbit stops those Processes after an idle hour. After an idle week, it also deletes the dependency directories that lockfiles can rebuild. The next HTTP request waits while Orbit restores and starts everything, and then reaches the app. An explicit `process:start` for a Vite development Process marks the Instance awake, including when Vite was already listening; an automatic HTTP wake does not turn that temporary wake into a manual one.

Hibernation settings live under the `hibernation` key of [`config/orbit.php`](https://github.com/hardimpactdev/orbit/blob/main/apps/gateway/config/orbit.php).

## What hibernates

Hibernation applies to a Process that meets every condition:

| Condition | Value |
| --- | --- |
| Owner | An Instance. |
| Instance | A development Instance on a Node with an active `app-dev` role. |
| Desired state | `running`. Orbit leaves a stopped Process stopped. |
| `keep_alive` | `false`. |

Processes in [Instance containers](/reference/development-containers#processes) are excluded from this Node-level policy. A container sleeps as a whole, through the same sweep: see [Sleep and wake](/reference/development-containers#sleep-and-wake). Its Route uses the same activation endpoint as below.

Restart policy does not matter. This Process policy never touches Node Processes, production Instances, Schedules, PHP-FPM services or pools, or Caddy socket paths. Planned: a production Instance's live release container sleeps as a whole by the container rule ([ADR 0265](/decisions/0265-sleep-idle-production-containers)); see [Production containers](/reference/production-containers#sleep-and-wake).

The Gateway's [Project Document cleanup gate](/reference/project-documents#local-state-and-startup-ordering) is separate from Instance hibernation. Its local runtime directory, `documents.cleanup_runtime`, changes no hibernation threshold or wake behavior. Restarting a Gateway consumer invalidates document-body cleanup authorization; waking an Instance does not grant it.

## Keep a Process running

Set `keep_alive` on a Process or process definition to keep it running while the Instance sleeps. Use it for a worker that must keep draining jobs:

```bash
orbit process:create queue --instance=12 --runtime=systemd \
  --command=/usr/bin/php --command=artisan --command=queue:work \
  --restart=on-failure --keep-alive --start
```

A wake still starts every desired-running Process, including a keep-alive Process that is down. `process:stop` records `desired_state=stopped`, and a wake leaves that Process stopped. Keep-alive has no effect outside hibernation. In a container Instance, a desired-running keep-alive Process keeps the whole container awake, because a frozen container runs no Process.

The [Node agent](/reference/node-agent#commands) reads activity times, manages awake and cold markers, inspects checkout dependencies, and prunes eligible directories. The Gateway owns the idle thresholds and wake decisions.

## Idle window and sweep

`orbit-runtime-hibernator.timer` runs on the Gateway host every 10 minutes. It reads the last HTTP activity of each Instance: the newer of the Instance's Caddy access log and its awake marker. The same run applies the [idle sleep of development containers](/reference/development-containers#sleep-and-wake) to the Instances that `ORBIT_HIBERNATION_CONTAINER_SLEEP` names: none, only those without a T3 server, or all. It uses the same idle window and sweep interval. The [gateway role convergence](/cli/node/role-add) installs the service and enables the timer; releases leave them unchanged.

Set these values in the Gateway's environment, not an Instance's `.env`. They configure hibernation only; [task-agent effort](/reference/tasks#drivers) uses separate Gateway settings. `ORBIT_TASKS_PROVISIONING_FAILURE_THRESHOLD` counts [task workspace provisioning failures](/reference/tasks#execution-ownership), not wake failures. The repository map that [trusts GitHub feedback](/reference/tasks#trusted-github-feedback), `orbit.tasks.github_reviewers`, also lives in Gateway configuration but changes no hibernation threshold, Process keep-alive setting, or wake behavior. Neither does `ORBIT_TASKS_COPIES_MAX`, which caps nested-fleet [task copies](/reference/tasks#task-copy).

| Setting | Default | Config key | Environment key |
| --- | --- | --- | --- |
| Idle window | 3,600 seconds | `orbit.hibernation.idle_seconds` | `ORBIT_HIBERNATION_IDLE_SECONDS` |
| Dependency idle window | 604,800 seconds | `orbit.hibernation.dependency_idle_seconds` | `ORBIT_HIBERNATION_DEPENDENCY_IDLE_SECONDS` |
| Sweep interval | 600 seconds | `orbit.hibernation.sweep_seconds` | `ORBIT_HIBERNATION_SWEEP_SECONDS` |
| Wake timeout | 60 seconds | `orbit.hibernation.wake_timeout_seconds` | `ORBIT_HIBERNATION_WAKE_TIMEOUT_SECONDS` |
| Cold wake timeout | 1,800 seconds | `orbit.hibernation.cold_wake_timeout_seconds` | `ORBIT_HIBERNATION_COLD_WAKE_TIMEOUT_SECONDS` |
| Container idle sleep | Off | `orbit.hibernation.container_sleep`, the environment value as written | `ORBIT_HIBERNATION_CONTAINER_SLEEP`: `false`, `without_t3` (only Instances without a T3 server, such as defaults) or `true`. Planned: `without_t3` and `true` also act on each production live, which has no T3 server |
| Containers that never sleep | None | `orbit.hibernation.container_never_sleep` | `ORBIT_HIBERNATION_CONTAINER_NEVER_SLEEP`, comma-separated Instance ids. Planned: the Instance's `never_sleep` field replaces it, and its ids move into the field ([ADR 0265](/decisions/0265-sleep-idle-production-containers)) |

`ORBIT_HIBERNATION_CONTAINER_SLEEP` is one setting with three values. `false`, or no value, puts no container to sleep by itself. `true` lets the idle sleep act on every development container. `without_t3` lets it act only on Instances whose runtime records no T3 server, which today are the Projects' defaults; a clone stays awake with the reason `t3_server`.

Any other value, such as `1` or a typo, counts as `false`: nothing sleeps by itself, and no run fails. The first run that reads it logs one error that names the value; the next runs log it no more until the value changes or the Gateway's cache is cleared, which logs it once again. Each dry run prints the warning.

The dry run prints each value's decisions side by side, so an operator sees what a change would do before making it. A dry run with `--instance` says that the named Instances act as under `true`, as a named run does.

After the idle window, the sweep stops each desired-running Process that is not keep-alive. It keeps `desired_state` as it is and removes the awake marker. When the [Node agent view](/reference/node-agent#gateway-view) is fresh and shows a Process already stopped, the sweep skips it. When an Instance has no desired-running Process without keep-alive, the sweep skips it: it never sleeps and is never pruned. An Instance with no recorded HTTP activity counts as idle, so it sleeps at the first sweep.

### Dependency prune

The same sweep deletes `vendor` and `node_modules` when all of these are true:

- The Instance is asleep.
- It is not already marked cold.
- It had no HTTP activity for the dependency idle window.
- No Process row of the Instance changed in that window.
- No checkout file outside `vendor`, `node_modules`, and `.git` changed in that window.
- No desired-running Process has `keep_alive`.

For a Laravel Instance, dependency inspection, pruning, and restoration use its [application directory](/reference/projects#application-directory). Root `apps/site/public` means `apps/site/vendor` and `apps/site/node_modules` inside the checkout; Composer and Vite+ install commands run in `apps/site`. Root `public` and non-Laravel Instances keep checkout-root dependency paths. Hibernation does not prune sibling dependency trees. Source activity still covers the whole checkout, so edits outside the application directory also prevent pruning.

Orbit deletes `vendor` only next to `composer.json` and `composer.lock`. It deletes `node_modules` only next to `package.json` and exactly one JavaScript lockfile. It never follows a symlink and keeps every lockfile. Then it writes the cold marker. The [Swamp dependency view](/reference/instance-dependencies) stays available because it reads repository evidence through the Gateway, independently of the Instance runtime.

## Wake

Caddy on the Instance's Node checks for the awake marker on every request. When the marker is missing, Caddy calls `GET /api/v1/runtime-activations/app-instance/{id}` on the Gateway over WireGuard, trusting the Orbit root certificate. The caller must be the Instance's Node, or a Node with an [access grant](/cli/node) to it.

The Gateway holds that request while the Instance wakes. When the wake succeeds, it answers `307` to the same path and query. The browser repeats the request, with the same method and body, and the awake app answers it. The visitor gets the app's own page after one extra round trip ([why a redirect](/reference/executor#the-wake-inside-the-request)). Otherwise the Gateway answers `503` with a short `text/plain` sentence that says what failed. The answer has no HTML, script or automatic reload. A reload sends a new request, which starts a new wake.

For a Node-level Process wake, the request runs the wake below and answers when it has ended.

A container Instance's site calls the same endpoint behind its asleep marker. The Gateway admits or joins the one `instance.wake` Operation and waits for it up to `ORBIT_VISIT_WAKE_WAIT_SECONDS` (default 30 s). A signed executor wake ends in about 0.4 s. A container frozen by the old Python path resumes through its Node inside the request ([the Node resumes a frozen container](/reference/development-containers#the-node-resumes-a-frozen-container)). The Node first reads the container's recorded pages back from swap, still frozen, then resumes it ([Prepared restore](/reference/prepared-restore)). If that resume fails, the Instance's Swamp wake workflow runs in the same Operation while the visit keeps waiting. Such a wake takes 14–19 s.

| Outcome | Answer |
| --- | --- |
| The wake reached the app | `200`; Caddy continues the original request |
| The wake failed | `503` `The wake failed (<error_code>). Reload the page to try again.` |
| The deadline passed during the wake | `503` `The Instance is still waking. Reload the page in a moment.` |
| Other lifecycle work held the Instance until the deadline | `503` `The Instance is busy with other work. Try again shortly.` |

While other lifecycle work holds the Instance, the visit tries to admit the wake again every 250 ms. Orbit keeps no memory of a failed wake: the visit that gets the failure ends that wake at once, even before its queued completion runs, so a reload admits a new one. The exception is an interrupted wake whose order is unknown ([ADR 0266](/decisions/0266-sleep-and-wake-containers-through-a-signed-order-executor), R2). Its fence admits no second wake, so every visit gets its failure until an operator verifies the Node.

An admitted signed Operation sends its order once, over the Node's WebSocket. A failed publish or an order that expires without `accepted` fails the wake, and the held visit gets the plain `503`. Nothing retries the order. A signed wake does not read the Python wake files or use the Python power helper. See [Signed power delivery](/reference/executor#signed-power-delivery) for handback.

The Gateway records completion, lifecycle and notice handoffs in SQLite. It queues a newly admitted signed wake's delivery after the response; hooks and notices run in the background. [Wake handoff recovery](/reference/executor#wake-handoff-recovery) covers lost jobs and unfinished deliveries without another visit.

The Gateway's Valkey must run with `hz 100` before the signed path is enabled. At its default `hz 10`, a 200 ms `BLPOP` timeout measured 297 ms, and a 500 ms timeout measured 504–575 ms. The orchestrator applies this host setting; the Gateway change does not configure Valkey.

Planned: a production live wakes the same way. The Node's Caddy holds the visitor's request until the asleep marker is gone, up to 10 s, and proxies it to live ([Production containers](/reference/production-containers#sleep-and-wake)).

On the nested fleet, a visit to an Instance frozen by the old Python path got the app's own page in 2.07 s. A forced failed wake got the plain 503 `The wake failed (instance.container_start_failed). Reload the page to try again.` in 23.9 s.

The wake runs in this order:

1. When the cold marker exists, restore dependencies with `composer install --no-interaction --prefer-dist` and `vp install --frozen-lockfile`. This uses the cold wake timeout.
2. Start every desired-running Process. A `vp-dev` wake uses its recorded port and never assigns or publishes another port.
3. Wait until each one runs. A `vp-dev` Process must answer on its [assigned Vite port](/reference/assigned-vite-ports).
4. Clear the cold marker and write the awake marker.

Any other Process is ready when its runtime reports running. If the recorded Vite port is unavailable or Vite cannot start on it, the wake fails without changing the stored port or writing the awake marker. Retrying the wake keeps the same port. Use [`process:start`](/cli/process/start) to recheck the assignment and publish it through the normal Process operation.

Orbit checks each Process every 0.5 seconds. A fresh Node agent view answers without SSH. Orbit confirms a `failed` answer, or a timeout, over SSH before the wake fails.

An Instance that [moves into a container](/cli/instance/migrate) is the exception: the move removes its awake marker, and the Gateway starts no wake until the move has ended. Requests get a plain 503 meanwhile, `Instance [<name>] is moving into a container. Try again when the move has ended.` The move writes the awake marker itself.

Every intercepted request runs the wake. Concurrent requests share one Process lock, so one wake runs at a time. A failed wake keeps the cold marker, and the request gets the plain 503 with the failure's message. An unexpected exception gives `The runtime wake failed. Check the Gateway log before retrying.` without exposing its details. The next request starts a new wake.

### Check a port saved by an earlier failed wake

The former wake could save a new port before its Caddy publication failed. The Gateway has no authoritative record of the activated Vite port, so a wake cannot detect that earlier mismatch. Before waking a suspected Instance, compare `instance:show`'s `vite_port` with its upstream in the Node's loaded Caddy configuration through the local admin API. Reading the Caddyfile alone does not prove what is loaded.

If the ports differ, run [`process:start`](/cli/process/start) for the Instance's Vite Process. Its native operation checks the assignment and publishes the handler before starting Vite. A successful operation restores the serving state; a wake retry alone cannot repair a stale handler.

### Probe requests

A request with `X-Orbit-Probe: 1` skips the wake and is not logged. [`profile --instance`](/cli/profile) sends it, so you can measure a sleeping Instance without waking it or resetting its idle time. `log_skip` needs Caddy 2.8 or newer, which Orbit installs from its [pinned package source](/reference/node-provisioning#package-sources).

### After a reboot

The awake markers live in `/dev/shm`, so a reboot clears them. Development Process units start with `systemctl start` and are never enabled for boot. The first request after a reboot wakes the Instance.

## Host directories

Native `app-dev` role addition creates the marker and access-log directories with cfgmgmt directory resources before publishing Caddy sites. Each marker write uses the shared directory setup before Caddy reads a marker. The marker writer checks both directory paths and resolves the Caddy group before changing either directory.

| Path | Owner | Mode | Use |
| --- | --- | --- | --- |
| `/dev/shm/orbit/hibernation` | `root:caddy` | `0755` | `app-instance-{id}.awake` markers. |
| `/data/caddy/orbit/hibernation` | `root:caddy` | `2775` | `app-instance-{id}.log` access logs and `app-instance-{id}.cold` markers. |

Orbit sets each parent directory below the protected host roots to `0755` so the `caddy` user can reach them. It leaves protected roots such as `/dev/shm` and `/tmp` unchanged. Awake markers belong in the marker directory; cold markers belong in the access-log directory. A marker outside those directories is refused before directory setup. The Gateway validates each Caddy build as the Caddy user, so new log files stay writable by the service.

## Docker Processes

A Docker Process of a development Instance maps restart policy `always` to Docker `unless-stopped`. An idle stop then survives a Docker daemon restart.

## Inspect

While the awake marker is missing, [Doctor](/cli/doctor) does not report a desired-running, stopped Process as drift, unless it is keep-alive. `process:list --instance=ID` shows desired and observed states and `keep_alive`.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### Restart policy is not keep-alive

A restart policy covers crashes while a unit runs. Treating it as a reason to stay awake was rejected. `keep_alive` is a separate field.

### Schedules keep running

Schedules are independent systemd timers, and they must fire on time. So hibernation never pauses them.

### PHP-FPM stays up

All development sites of one PHP version share one master, and each pool already ends idle workers. Stopping the service or removing a pool would affect every site.

### No start at boot

Enabling development units for boot would start every Instance without a request. So units start only on demand.

### The visit waits for the app

The Gateway holds the visit until the wake has ended, so the visitor's first answer is the app's own page. A progress page that polls the app was rejected: it delayed the first app request and needed its own state header, retry flag and failure memory.

### One answer for both tiers

A soft wake and a cold wake give the same answers. Caddy owns one intercept contract.

### Keep-alive blocks the prune

A keep-alive Process can still need `vendor` or `node_modules`. So Orbit never prunes an Instance with one.
