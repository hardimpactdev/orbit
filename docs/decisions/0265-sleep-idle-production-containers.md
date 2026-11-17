---
title: "ADR 0265: Sleep idle production containers"
sidebarTitle: "0265 Production sleep"
description: "In progress. An idle production live sleeps after one hour and wakes before receiving traffic. Deployment independently freezes the preparation and rollback containers when their work has drained."
---

# ADR 0265: Sleep idle production containers

A production Instance's live release container sleeps when it is idle, by the same rule as a development container: the same idle window, sweep, Operations, reasons and dry run. The whole container sleeps, with no Process left out, and it wakes fully ready, with the fast wake and the [prepared restore](/reference/prepared-restore) of [ADR 0261](/decisions/0261-restore-a-sleeping-containers-memory-before-it-resumes). Hot stays awake through readiness. The release operation freezes old after its drain. A visitor never sees a progress page: Caddy holds the request while live wakes.

## Status

In progress.

The owner decided on 2026-11-16 that idle sleep also applies to production containers. The orchestrator decided parts 8, 9 and 10 on the same day; the owner may overrule them. No part is built. Production containers ([ADR 0263](/decisions/0263-run-production-releases-in-hot-live-and-old-containers)) are not built yet, so this design is built with them.

This decision applies the idle sweep to the serving production release. [ADR 0263](/decisions/0263-run-production-releases-in-hot-live-and-old-containers) owns release-driven sleep for the preparation and rollback containers, their capacity and rollback rules.

Principle: [One way, one name](/mission#principles) and [Lean](/mission#principles). One sleep rule serves every container Instance. A production Instance that nobody uses frees its Node's memory as a development Instance does.

## Context

[Sleep and wake](/reference/development-containers#sleep-and-wake) freezes an idle development container and moves its memory to swap. A frozen Laravel container holds about 13 MiB of RAM. The fast wake resumes it in a fraction of a second, and the prepared restore reads its resident pages back before the resume.

ADR 0250 and ADR 0263 kept production containers out of this rule. Many production Instances get few visitors, such as previews and small Projects. Each one holds its whole memory on the `app-prod` Node all day.

Production differs from development in these ways that drive the design:

- An Instance has up to three release containers. Only live serves.
- A visitor can be a person, an API client or a webhook. A progress page with status 401 breaks an API client and a webhook.
- Workers and Schedules run in live. A job or a Schedule fire must not run twice.
- A deploy and a rollback move the Routes between containers with one Caddy reload.
- There is no T3 in production.

## Decision

The numbered parts below settle which release container sleeps, how a deploy and a rollback meet a sleeping live, what counts as traffic, what a visitor sees, what happens to Schedules and workers, how an Instance opts out, and the rollout.

### 1. Idle sleep for live

<a id="1-only-live-sleeps"></a>

The [idle sleep](/reference/development-containers#sleep-and-wake) acts on the live release container of each production Instance. The same parts apply as in development:

- the idle window of one hour and the sweep of the [runtime hibernator](/reference/app-dev-runtime-hibernation#idle-window-and-sweep);
- the `instance.sleep` and `instance.wake` Operations, and the `instance:sleep` and `instance:wake` commands, which act on live;
- the table of reasons and its order;
- `orbit:runtime-hibernator --dry-run`, which lists each release container with its reason.

The `ContainerSleepScope` and the sweep gain production live containers. The three `t3_` rows and `t3_server` never apply, because a production container has no T3 server. So the values `without_t3` and `true` of `ORBIT_HIBERNATION_CONTAINER_SLEEP` both act on live. Production gets no value of its own. A Gateway that already uses `without_t3` or `true` puts each production live to sleep as soon as production containers exist.

The reason `not_live` excludes preparation, hot and old from the idle sweep. The deploy and rollback operations own their power state.

### 2. Hot never sleeps

Hot exists only during a deploy Operation. An open Operation keeps the Instance awake with `busy`, so the sweep never sleeps hot, and never sleeps live while a deploy or rollback runs. The sweep reports `not_live` for hot, because that row comes first.

A native Schedule fire records its `schedule.run` Operation only after it ends. So `busy` also covers a fire whose Swamp run has not ended: the sweep reads the Instance's running `schedule-<uuid>` runs with the check that retire uses.

### 3. A deploy and the switch

A deploy wakes its preparation container and clones the finished build while live can remain asleep. The [capacity check](/reference/production-containers#capacity) includes a possible wake of live during the deploy and the handover. Use live’s recorded working set from before its last freeze when no Instance limit is set.

A visit during a deploy wakes live as at any other time. A deploy changes live only after the switch, so the wake of a production live is admitted beside an open deploy or rollback. The switch, the wake and the handover's resume take the Instance's power lock, the unit `orbit-instance-power-<id>` of the [power helper](/reference/prepared-restore), so none of them runs in the middle of another.

The switch never waits for a wake: the new live just passed readiness. The switch step renames the upstream file and the live link into place and reloads Caddy. Once the reload succeeded, the same step removes the Instance's asleep marker. A failed switch keeps the marker, or puts it back, with the previous files. The new live starts its own watch, so it has one full idle window before its first sleep.

A wake reads the live link after it takes the power lock, and acts only on the container that the link names. A wake that finds no marker after the switch ends with `wake: already_running` and never resumes or starts the previous live.

After a verified switch, handover resumes a sleeping previous live to drain requests, Processes and timers. Once those units finish, retire freezes the drained container as old and reclaims its working memory to swap.

Rollback wakes old from swap or cold-starts it after a reboot. After readiness, switch and handover, the drained former live becomes the frozen rollback release.

### 4. Retain old for a swap-backed rollback

<a id="4-old-stays-stopped-never-frozen"></a>

The release operation freezes old and measures swap use. Rollback resumes that container and checks it against the current configuration and database. The retained filesystem supports a normal boot if previous memory was lost. The idle sweep excludes old (`not_live`).

### 5. A production sleep never stops live

A development sleep stops the container when swap cannot take its memory. A production sleep never does: a stopped live would cold-boot its units at the next visit. The sleep resumes live, closes swap, removes the marker and fails with `instance.container_freeze_failed`. The sweep then keeps live awake for one idle window with the new reason `swap_full`.

Live keeps `boot.autostart`. A Node reboot boots live awake, with its Processes and timers. The boot removes the asleep marker: a boot unit that the `app-prod` role installs removes the marker of each production Instance once Incus started its live. The Gateway then records `power_state` `running` from the Node agent's report of the boot, and starts a fresh watch, so live gets one full idle window before its next sleep. No visitor and no fire waits for a wake after a reboot.

### 6. A running live never swaps

Every release container gets `memory.swap.max` 0 while it runs, also without a memory limit. A development container gets it from `limits.memory`. The build proves which Incus setting writes it for a container without `limits.memory`. A sleep opens swap and a wake closes it, as in development.

### 7. Traffic is HTTP

Each request through any Route of the Instance reaches the Caddy of its `app-prod` Node and writes the Instance's access log, which the sweep reads. A Cluster Route through a Router counts too, because the Router sends to the same Caddy.

A request with `X-Orbit-Probe: 1` does not count, such as the readiness check and a profile run. An uptime check without the probe header keeps live awake; that is intended.

### 8. A visitor waits for the wake

A production visitor never gets the development progress page with status 401. When a request meets the asleep marker, Caddy calls the activation endpoint. The Gateway admits or joins the Instance's `instance.wake` and answers at once, as in development, so a held request takes no Gateway worker. Then the Node's Caddy itself holds the request: it tries the live upstream again until the asleep marker is gone, and then proxies the request to live. A person, an API client or a webhook only sees a slower first answer. The targets are about 1.5 s for a warm wake, in which the fast wake's [prepared restore](/reference/prepared-restore) reads the recorded pages back, and about 2.4 s for a cold wake, in which the pages come back from swap one fault at a time. The live proof measures them.

The hold has a bound of 10 s. After it, Caddy answers `503` with `Retry-After: 5` and a plain page that names the Instance. The wake goes on, and the next request after it finds live awake.

A failed wake stores the failure as in development. While it is stored, the activation endpoint says so, and Caddy answers `503` with `Retry-After: 30` and the same plain page at once. The first request 30 s or more after the failure starts one new wake by itself, so a stored failure gets at most one retry each 30 s. `instance:wake` still retries at once.

The `app-prod` role installs the power helper and the fast wake's worker, as the `app-dev` role does.

Caddy has no built-in wait on a file. So the build must prove how the Node's Caddy holds a request until the marker is gone, within the 10 s bound, before it proxies the request, and the live proof measures it.

### 9. Schedules and workers

- **Native cron Schedules.** A due fire that finds the asleep marker starts or joins the wake and waits for it. Then it runs in live. The wake that a fire starts does not count as activity, so live sleeps again at the next sweep when nothing else keeps it awake. The sweep never sleeps live while a fire runs in it (`busy`).
- **Container timers.** Each systemd-calendar Schedule, such as the per-minute Laravel scheduler, is a container timer, and it pauses with the container. Missed fires are not caught up: a timer that came due during the sleep fires once at the resume. So a per-minute scheduler skips the minutes of the sleep, and a daily timer fires late, at the next wake.
- **Queue workers.** The worker Processes pause with the container. Queued jobs wait for the next wake.

A Project whose jobs or scheduler must run without visitors sets [never sleep](#10-never-sleep-is-a-field-of-the-instance) on the Instance, or gives a Process `keep_alive`. A desired-running `keep_alive` Process keeps the whole container awake, as in development.

### 10. Never sleep is a field of the Instance

Each Instance record gets one field, `never_sleep`. `instance:update` sets it, `instance:show` shows it, and the sweep reads it for development and production alike, with the reason `never_sleep`. It replaces `ORBIT_HIBERNATION_CONTAINER_NEVER_SLEEP`. When the build adds the field, each id in that setting moves into the field, and the setting goes.

An Instance with an attached [Node service](/cli/instance/node-service-attach), such as PowerSync, already stays awake with `node_service`. PowerSync's Processes are Node Processes on the Services Node, and a sleep never touches them.

### 11. Rollout

Production joins the sweep under the existing values of `ORBIT_HIBERNATION_CONTAINER_SLEEP`, `true` included, once production containers exist. The sleep-and-wake case of the [live proof](/decisions/0263-run-production-releases-in-hot-live-and-old-containers#live-proof-on-a-fixture-production-project) passes before a real Project moves.

The live proof of ADR 0263 changes:

- **One fire.** Each Schedule fires once each minute, except while the Node reboots. While live sleeps, a container timer skips its minutes; a native cron Schedule still fires each minute, because each fire wakes live.
- **Sleep and wake, new.** The load loop pauses, and live sleeps after its idle window. The proof measures how long Caddy holds a visit, and checks that a due native Schedule wakes live and runs once, that the worker resumes and runs the queued jobs, and that no request fails. Then a deploy and a rollback each run while live sleeps: no request fails, the previous live drains and freezes as old, and each job runs once. Last, the Node reboots while live sleeps: live boots awake, the boot removes the marker, the Gateway records `running` and starts a fresh watch, and the first request gets the app with no wake.

## Rejected alternatives

- **Production containers never sleep.** Every idle production Instance would hold its whole memory on the Node.
- **Sleep only the web server, and keep workers running.** The owner decided that the whole container sleeps. A partial sleep is a second rule beside the container rule.
- **The development progress page for production.** Status 401 and an HTML page break an API client and a webhook. A held request only answers more slowly.
- **Stop live when swap is full.** The next visit would wait for a cold boot of every unit. A live that stays awake costs only memory.
- **Apply the visitor wake to old.** Only a release operation can resume the rollback container. A visitor always resolves the selected live release.
- **Wake live to prepare the new release.** The dedicated preparation container owns the build, so a deploy can leave live asleep until a visit or handover needs it.
- **Catch up missed scheduler minutes.** A scheduler that catches up runs every missed task at the wake. Laravel's scheduler expects one run each minute.
- **A separate setting value for production.** One rule for every container Instance is simpler. The never-sleep field gives each Instance its own choice.

## Consequences

- An idle production Instance frees its memory. The first request after a sleep waits about 1.5 s to 2.4 s for the wake, or gets `503` after 10 s.
- A job that runs at the freeze can run twice after the wake: its queue reservation expires during the sleep, and another worker takes it. A Project that cannot accept this sets `never_sleep` or a `keep_alive` Process.
- A per-minute scheduler skips the minutes while live sleeps. A Project that needs every minute sets `never_sleep` or a `keep_alive` Process.
- An uptime check without the probe header keeps live awake.
- An Instance with PowerSync never sleeps while its Node service is attached.
- A Node reboot loses the frozen state. Live boots with its units and without the marker, so its first visitor waits for no wake.
- A native cron Schedule whose interval is shorter than the idle window wakes live at each fire, and live sleeps again at the next sweep. Each fire then costs a wake and a sleep. Set `never_sleep` on such an Instance.
- A sleeping live wakes only while the Gateway answers, because the Gateway admits each wake. The hold itself is in the Node's Caddy and takes no Gateway worker.
- After a failed wake, live's Routes answer `503` until a retry succeeds. A visit starts one retry each 30 s; `instance:wake` retries at once.

## Affects

- Components: apps/gateway, apps/cli, apps/docs
- ADRs: [0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers), [0261](/decisions/0261-restore-a-sleeping-containers-memory-before-it-resumes), [0263](/decisions/0263-run-production-releases-in-hot-live-and-old-containers)
- Detail: [Production containers](/reference/production-containers#sleep-and-wake), [Instance containers](/reference/development-containers#sleep-and-wake), [App-dev runtime hibernation](/reference/app-dev-runtime-hibernation), [Prepared restore](/reference/prepared-restore)
- Verify: Gateway Pest tests of the sweep's reasons for release containers, the never-sleep field and the switch's marker step; the sleep and wake cases of the production charts; the sleep-and-wake case of the live proof
