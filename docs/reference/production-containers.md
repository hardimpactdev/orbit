---
title: "Production containers"
description: "Build each production Instance release in its off-traffic preparation container, clone the finished build, switch traffic to the ready clone, and keep the previous release stopped for rollback."
covers:
  - swamp/scripts/release-container.py
  - apps/gateway/app/Domain/Instances/Deployment/**
---

# Production containers

Production `app-prod` Instances (and container Gateways) deploy through the `production-release` Swamp workflow ([ADR 0263](/decisions/0263-run-production-releases-in-hot-live-and-old-containers)). Each release runs in an unprivileged Incus container inside project `orbit-releases` with only user `orbit`, no coding agent, no model route, and FrankenPHP in classic mode on port `8080` (no PCOV or PHP Data Bridge).

## Prepare the AppProd Node

Add or converge the role with `orbit node:role:add <node> app-prod [--converge --full]` to install the release controller, stock image, pinned static PHP runtime, bridge, egress proxy, and memory-reclaim service. Production Nodes require a ZFS Incus pool named `orbit-releases` (or an existing `orbit-instances` ZFS pool to create a child dataset); nested proof `app-prod` Nodes use btrfs instead. `dir` pools are refused.

Once a production Instance starts its first container deployment, `instance:destroy` and `instance:unregister` are refused so release storage cannot be orphaned. Deploy a new release after changing a Route domain or web root so FrankenPHP's config updates.

### The pinned runtime

The app-prod converge builds `/opt/orbit-production-runtime` from the recipe's runtime folder parts, less every part that the recipe marks `"dev_only": true` and its programs. Those are the development tools `laravel-lsp` and `phpactor`. The folder also leaves out `laravel-lsp-root`, the finder of the Laravel language server. A production release prepares no [PHP symbol index](/reference/development-containers#php-symbol-index). Development containers keep all three in `/opt/orbit-runtime`.

## Release states and records

Each Instance retains up to three containers between deploys (four briefly during cutover):

| Container | Name | Role |
| --- | --- | --- |
| Preparation | `prepare-instance-<id>` | Off-traffic build container; sleeps in swap between deploys via `orbit-instance-power --release` (or stops on swapless Nodes) |
| `hot` | `instance-<id>-<sha12>` | Candidate cloned from `prepare-instance-<id>/built-<operation>` for private readiness checks |
| `live` | `instance-<id>-<sha12>` | Serving release (`boot.autostart=true`), running FrankenPHP, Processes, and container timers |
| `old` | `instance-<id>-<sha12>` | Previous live release, stopped after drain (`boot.autostart=false`) for fast rollback |

On the Node, Orbit stores `/var/lib/orbit/production/<id>/live` (serving container name), `upstream.caddy` (bridge IP and port `8080`, imported by Caddy sites), `.release/inventory.json`, and `.release/operation.json`. Persistent data lives on custom volume `instance-<id>-shared`, mounted at `/srv/shared` in every container. The Gateway records serving and rollback commits in `instances.production_release` from bound Node receipts.

## Deploy

`instance:deploy` and automatic production deployments run one Operation per Instance:

1. **Check and wake:** Verifies the live link (`deployment.selection_invalid` on drift), runs the [capacity check](#capacity), and wakes or boots `prepare-instance-<id>`.
2. **Build and seal:** Attaches `/srv/shared` and the pinned runtime, checks out the commit, renders `.env`, runs `before_activation` steps as `orbit`, installs disabled units, and snapshots `built-<operation>`.
3. **Clone and sleep:** Clones `hot` from `built-<operation>`, deletes the transient snapshot, and freezes or stops `prepare-instance-<id>`.
4. **Readiness:** Verifies commit and digest, active `orbit-frankenphp.service`, three consecutive HTTP `200` replies on `/up` or `/` (60 s limit), valid `public/storage`, and no PCOV or PHP Data Bridge.
5. **Switch:** Journals the previous selection under the Node routing lock, updates `upstream.caddy` and `live`, and reloads Caddy (restoring the previous selection on failure).
6. **Handover:** Stops old timers, starts new timers, gracefully stops old Processes (120 s), starts running new Processes, and flips `boot.autostart`.
7. **After steps and retire:** Runs `after_activation` steps in the new live container, drains and stops the old container, and deletes the earlier `old` release.

Draining waits up to 600 seconds for queued send bytes to clear before stopping the old container and promoting any dependent ZFS releases. Warm `vendor` and `node_modules` directories stay across builds only when their inputs match. Readiness checks allow 5 seconds per HTTP request on `/up` (Laravel) or `/` (other apps).

## Rollback

`instance:rollback <instance>` starts the stopped `old` container (`rollback.release_not_retained` if absent), refreshes `.env` and database listeners, runs readiness checks (skipping `public/storage` validation), switches traffic and hands over work, stops the rolled-away live as the new `old`, pauses automatic deploys, and blocks the rolled-away commit. Rollback runs no deploy steps, performs no capacity check, and never restores database data.

## Sleep and wake

Preparation containers already sleep and wake via `orbit-instance-power --release`. Sleeping **live** production containers is **planned** ([ADR 0265](/decisions/0265-sleep-idle-production-containers)). An idle live container will sleep after one hour without stopping on full swap, while `never_sleep`, `keep_alive`, and attached Node services keep it awake. Caddy will hold visitor requests for up to 10 seconds during wake and return `503` with `Retry-After` on timeout or failure.

## Database and storage

Orbit derives each MySQL or PostgreSQL route from the Instance's Database attachment and binds a guest-loopback listener inside the container to the server's WireGuard or LAN port. Preparation, `hot`, and rollback verify each attachment with `SELECT 1` before cutover. Every schema migration and queued job payload must be expand-only across one release so `live` and `old` both run safely against the current database.

Shared paths (default `storage`) symlink from the checkout into `/srv/shared`. For Laravel apps with `storage` shared, preparation automatically links `public/storage` to `../storage/app/public` when absent. Keep release-specific compiled views and caches off `/srv/shared`.

## Capacity

Before waking preparation, deploys and checkout migrations verify three host resources (refusing with `deployment.node_capacity`, which retries without counting toward automatic-deploy commit blocks):

- **RAM:** Covers `2 × working - prep_resident + max(0, live_need - live_resident) + 1 GiB` headroom.
- **Swap:** On swap-enabled hosts, covers `working - prep_swap` for preparation reclaim (`0` on swapless hosts or for Gateways).
- **Pool space:** Covers release size plus `max(1 GiB, pool_total / 10)` headroom (`10 GiB` fixed floor for Gateways), plus shared data and SQLite copy size during a first checkout migration.

## Host paths

Because release containers lack host install folders, admission rewrites exact literal host paths in Process commands, Process environments, and rendered `.env` files before deployment:

| Host path | Container path |
| --- | --- |
| `/usr/bin/php`, `/usr/bin/php<version>` | Runtime folder's matching PHP binary |
| `/opt/orbit/vite-plus/bin/<tool>` | `/opt/orbit-runtime/current/bin/<tool>` (except `chrome-headless-shell`) |
| `/opt/orbit/vite-plus/js_runtime/node/<version>/bin/{node,npm,npx}` | `/opt/orbit-runtime/current/bin/<tool>` when `<version>` matches the recipe Node |
| `/opt/orbit/chrome-headless-shell/<version-or-sha>/.../chrome-headless-shell` | `/opt/orbit-runtime/current/chrome-headless-shell/chrome-headless-shell-linux64/chrome-headless-shell` |
| Instance checkout or `/home/orbit-app-<project>/current` | `/srv/workspace/current` |

Any shell expansion, glob, mismatched Node version, or other path under `/opt/orbit/`, `/home/orbit-app-<project>`, or `/usr/bin/php` refuses with `deployment.host_path_unsupported`.

## First release and migration

### After a clone

[`instance:clone`](/reference/instance-cloning) creates a host production checkout (optionally seeding `database.sqlite` via `--sqlite-source-path`). Its first container deploy runs the checkout migration below. Placements without an existing checkout start directly with a fresh preparation container and empty `/srv/shared` volume.

### A checkout production Instance

The first container deploy of a host checkout builds the serving commit, copies shared files and SQLite into `/srv/shared` while the checkout serves, and verifies `hot` privately. At cutover, Orbit fences routes with HTTP `503`, acquires the legacy Schedule lock, stops legacy PHP-FPM/Processes/timers, confirms SQLite did not change since backup, checks `hot` through Caddy, records the migration commit in the Node journal, and opens traffic.

An interruption before the migration commit returns traffic to the host; an interruption after the migration commit recovers forward on the Node with:

```bash
sudo /usr/local/libexec/orbit-instance-release-phase recover <instance-id> <full-commit> <operation-id>
```

If the Gateway missed the terminal receipt (`deployment.selection_unconfirmed`), re-run `orbit instance:deploy <instance>` to adopt the recovered release and run any remaining `after_activation` steps without rebuilding or re-copying data.

## Failures and errors

Failures before or during `switch` keep the previous `live` serving and remove `hot`; failures during `handover`, `after_activation`, or `retire` leave the new `live` serving.

### Errors

Production container deploys and rollbacks report these error codes:

| Code | Cause |
| --- | --- |
| `deployment.host_path_unsupported` | Command or environment references an unmapped host path |
| `deployment.node_capacity` | Insufficient RAM, swap, or storage pool headroom on the Node |
| `deployment.not_ready` | Candidate failed private readiness checks |
| `deployment.selection_invalid` | Live link, inventory, or `after_activation` step order drifted |
| `deployment.selection_unconfirmed` | Gateway has no confirmed release receipt after recovery; re-run `instance:deploy` |
| `deployment.step_failed` | A deploy step failed |
| `deployment.switch_failed` | Caddy validation or reload failed during cutover |
| `rollback.release_not_retained` | No stopped `old` release exists |
