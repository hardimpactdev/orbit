---
title: "Update and recover a Gateway"
description: "Preserve source, state, and keys through a Gateway update, recover from a failed update, inspect request logs, receive release alerts, and follow fleet rollouts and reboots."
covers:
  - apps/gateway/app/{Domain,Infrastructure}/Releases/**
  - apps/gateway/config/{app,logging,retention}.php
  - apps/gateway/app/{Infrastructure/Logging/**,Infrastructure/Database/**,Providers/Domains/DatabaseServiceProvider.php}
  - apps/gateway/{app/Domain/Gateway/GatewayCacheStore.php,database/migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php,database/migrations/*add_release_inputs_to_nodes.php,resources/sigstore/trusted_root.json}
  - "{apps/gateway/app/Actions/Fleet/*Rollout*.php,apps/gateway/app/Actions/Fleet/AdmitFleetCliAction.php,apps/gateway/app/Actions/Fleet/ContinueReleaseFleetAction.php}"
  - apps/gateway/app/**/GatewayReleases/**
  - apps/gateway/app/**/*GatewayRelease*.php
  - apps/gateway/{app/Domain/Nodes/NodeUpdat*.php,app/Data/Nodes/NodeUpdatingData.php,app/Domain/Fleet/**,app/Infrastructure/Fleet/**,app/Data/Fleet/**,config/fleet.php}
---

# Update and recover a Gateway

This guide explains how Orbit updates the Gateway safely, how to recover when an update fails, how release alerts work, and how fleet rollouts and drained reboots follow a release.

## Gateway request logs

The Gateway logs exceptions at `ERROR` when the HTTP response status is 500 or higher. Lower statuses are client refusals recorded in [Activity](/cli/activity) instead of the error log.

Every log entry written during an HTTP request carries that request's `request_id`, matching `activity_log.request_id`. Search the Gateway logs by request ID to correlate an Activity row with its server log entries.

SQLite transactions begin `IMMEDIATE`, holding the write lock from the start. Waiting writers time out after 5 seconds with `database is locked`. When a transaction holds the write lock for 2 seconds or more, the Gateway logs `Long SQLite write transaction` with `held_ms` and the `origin` file and line. Timing stops before post-commit callbacks so broadcast and dispatch network calls are excluded.

`swamp.vault_sync_unavailable` does not indicate a SQLite lock; its `Swamp execution copy unavailable` warning logs `phase`, `http_status`, and a safe remote code (`phase=connect` with `http_status=429` means Swamp throttled WebSocket upgrades at 50 per source IP per 60 seconds).

Gateway log files rotate daily and retain 14 days by default (`LOG_DAILY_DAYS`).

### Disk retention

Gateway role convergence installs host log-retention files (`journald.conf.d/orbit-retention.conf`, `logrotate.d/orbit-gateway`, and the hourly `logrotate.timer` drop-in), controlled by these defaults:

| Variable | Default | Purpose |
| --- | --- | --- |
| `ORBIT_JOURNAL_MAX_MB` | `256` | Maximum persistent journal size |
| `ORBIT_JOURNAL_KEEP_FREE_MB` | `1024` | Free space reserved for persistent journals |
| `ORBIT_JOURNAL_DAYS` | `7` | Maximum journal retention age in days |
| `ORBIT_JOURNAL_RUNTIME_MAX_MB` | `64` | Maximum runtime journal size |
| `ORBIT_LOG_ROTATE_MB` | `32` | Maximum size before logrotate rotates Gateway logs |
| `ORBIT_LOG_ROTATE_KEEP` | `7` | Rotated Gateway log files retained |

The Gateway scheduler also runs daily database retention jobs: `queue:prune-failed` (`ORBIT_FAILED_JOBS_HOURS`, default `168`), `queue:prune-batches` (`ORBIT_JOB_BATCH_HOURS`, default `24`), and `activitylog:clean` (`ORBIT_ACTIVITY_LOG_DAYS`, default `30`). None of these vacuums SQLite; SQLite reuses freed pages until you run `VACUUM` manually.

Copy local recovery archives off the Gateway, verify them remotely, and delete superseded local copies outside `ORBIT_HOME` so later archives do not nest them. Automatic release retention manages only `backups/pre-*.sqlite`.

## Preserve a complete state set

The SQLite database alone is not a recoverable Gateway backup. Store all five inputs together off-host with administrator-only access:

| Input | Why it matters |
| --- | --- |
| Exact monorepo commit and lock files | Reinstalls matching CLI, Gateway, and SDK code |
| Gateway `.env` | Stores configuration, custom database paths, and optional `APP_KEY` |
| Complete `ORBIT_HOME` | Holds `gateway.sqlite` and WAL files, SSH keys and known hosts, WireGuard keys, root CA, generated state, and `gateway.app-key` |
| Machine configuration or VM snapshot | Preserves services, firewall, DNS, networking, ownership, and packages |
| Application backups | Application databases, uploads, and source are separate from Gateway state |

The database also stores agent-reported Node facts, including the latest [`node:metrics`](/cli/node/metrics) snapshots. A nonempty `APP_KEY` in `.env` takes precedence over `ORBIT_HOME/gateway.app-key`; keep the effective key with its encrypted data and never regenerate it during an update or restore.

## Back up before an update

Stop incoming traffic and scheduled writers before archiving state, or take a provider snapshot while the machine is stopped. On a quiescent host, run from a root console using a fresh archive path:

```bash
sudo systemctl stop orbit-runtime-hibernator.timer
sudo systemctl stop orbit-runtime-hibernator.service caddy php8.5-fpm
sudo install -d -m 0700 /root/orbit-backups
test ! -e /root/orbit-backups/gateway-before-update.tar.gz || exit 1
sudo tar --acls --xattrs -czpf /root/orbit-backups/gateway-before-update.tar.gz \
  -C / home/orbit/.orbit home/orbit/shared/gateway.env
sudo chmod 0600 /root/orbit-backups/gateway-before-update.tar.gz
sudo tar -tzf /root/orbit-backups/gateway-before-update.tar.gz
```

Adjust the paths if `ORBIT_HOME` or `DB_DATABASE` differs, and always include `-wal` and `-shm` files alongside SQLite ([SQLite WAL default](/solutions/sqlite-wal-default)). Transfer and verify the archive off-host before updating.

## Move the Gateway to release containers

A container Gateway keeps Caddy, WireGuard, SQLite, and Gateway identity on the host while running the Laravel application inside an unprivileged Incus container with FrankenPHP and Laravel Octane.

An established container Gateway holds at most three containers: a stopped `preparation` container, the serving `live` container, and a stopped `old` container (`boot.autostart=false`). Before waking `preparation`, each build checks RAM, swap, and pool disk space under the [production capacity rules](/reference/production-containers#capacity); insufficient capacity refuses preflight with `gateway.release_node_capacity`.

The admitted commit pins PHP, FrankenPHP, and Composer into an immutable host directory mounted read-only into `preparation` and inherited by the candidate. Preparation updates the checkout in place, installs locked no-dev Composer dependencies, and installs the verified `apps/web` artifact at `/home/orbit/web/current`. Once stopped and renamed to `gateway-<id>`, the candidate boots with its checkout and web tree mounted read-only. It runs additive Laravel migrations directly against SQLite (with no pre-migration copy), passes private health checks, and loads compatible Swamp definitions while active Operations keep running.

The Gateway requires its Horizon and scheduler Processes to be named `horizon` and `schedule-work`. Follow these steps for the initial host-to-container move:

1. Run `orbit gateway:auto-release:disable` and wait for active releases to finish.
2. Deploy the container tooling through the host release flow and note its verified Operation ID, full commit SHA, and web archive.
3. Run `bin/gateway-container-prepare` in check mode, then apply with the Node's storage, network, and image settings.
4. Run `sudo bin/gateway-container-move <verified-release-operation-id> <published-web.zip>` to move the same commit onto containers.
5. Verify `/up`, `orbit gateway:status`, an authenticated peer API call, and background workers.
6. Release the next forward commit so the former live retires as `old` and `preparation` is rebuilt, then converge the `gateway` role to remove host PHP-FPM.

If a move or handover is interrupted, run `sudo /usr/local/libexec/orbit-gateway-rollback --recover` at the Node console to resume the durable switch journal.

### Handover, retire, and preparation

The switch journal records the new serving commit before Caddy switches upstream. Retire runs as one idempotent sequence shared by releases, rollbacks, and `--recover`:

1. Start Horizon on the new live container, verify the public Gateway endpoint, and record the commit in the inventory's `verified` list.
2. Move the scheduler and background units, then wait for the former live container's HTTP sockets and Horizon jobs to drain (up to 600 seconds) before disabling its units.
3. Stop the former live container with `boot.autostart=false` and promote the most recent verified former live to `old`.
4. Once the new live passes its public check, delete extra stopped releases and clone a fresh `preparation` container via ZFS clone and `zfs promote`. Otherwise keep the extra release as `earlier` until rollback or `--recover`.

If Horizon fails to start on the selected container, Orbit keeps the outgoing workers and handover journal active so you can inspect the journal, repair the issue, and rerun `--recover`.

### Host environment and nested-fleet refresh

After editing `/home/orbit/shared/gateway.env`, run `sudo /usr/local/libexec/orbit-gateway-environment configure` on the Node to publish the updated file into the container and reload workers.

On a nested Gateway inside an Instance fleet, `sudo /usr/local/libexec/orbit-gateway-environment refresh` copies `/etc/orbit/instance-fleet` and the six proxy lines of `/etc/environment` into the read-only container environment mount (`instance-fleet-environment`, `instance-fleet-proxy.env`, and `instance-fleet`). Container units load `instance-fleet-proxy.env` through `50-orbit-fleet-proxy.conf` ([Instance host in the fleet](/reference/development-containers#instance-host-in-the-fleet)).

## Release layout

Before container adoption, a host PHP-FPM Gateway runs from immutable release worktrees beside the live link:

| Path | Content |
| --- | --- |
| `/home/orbit/releases/<id>/` | Linked Git worktree for one commit (12-hex-digit `<id>`), its `vendor/` trees, and `REVISION` |
| `/home/orbit/orbit` | Symlink to the active release used by `PATH`, systemd units, PHP-FPM, and Caddy |
| `/home/orbit/shared/orbit.git` | Bare repository backing every release worktree |
| `/home/orbit/shared/gateway.env` | Shared private (`0600`) Gateway environment file linked into each release |
| `/home/orbit/shared/gateway-storage/` | Shared Laravel storage directory (logs, file cache, locks) |
| `ORBIT_HOME` | Gateway database, keys, CA, and generated state outside releases |

The Gateway reads the release link and inventory directly as its own account without calling the Node agent. Sealed release source directories are read-only; only `apps/gateway/bootstrap/cache` (mode `0700`) and `apps/cli/storage` remain writable. `gateway:status` reports `APP_VERSION` if set, otherwise the commit in `REVISION`, otherwise `dev`.

### Release as a Swamp run

`orbit gateway:release:deploy <sha>` and [automatic releases](#automatic-releases) run as one Swamp workflow (`gateway.release`, see the [release flow chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/gateway/release-deploy.mmd)).

Admission requires a full commit SHA, no other active release (`gateway.release_in_progress`), a verified [agent pin](#build-and-seal-a-release), and a valid [web build](#web-build) from an accepted CI run. It stores a short-lived GitHub read token in the execution vault and runs scripts from the target commit's `swamp/scripts/`.

On a host PHP-FPM Gateway, `operation-gateway-release` runs from the Swamp Node over SSH:

| Step | Work |
| --- | --- |
| Fetch and build | Fetches the commit into `shared/orbit.git`, checks ancestry (`gateway.release_downgrade`) and disk floor (`gateway.release_disk_low`), builds and seals the release, and prints its Swamp grants |
| Install web build | Downloads, verifies, and extracts the `apps/web` artifact into `web/releases/<id>` |
| Snapshot and migrate | Copies SQLite with `VACUUM INTO`, then runs `artisan migrate --force --isolated=1` |
| Wait for active Operations | Polls every 5 seconds (up to 600 seconds) until [release idle guard](#release-idle-guard) Operations settle, else fails with `gateway.release_busy` |
| Renew Swamp tokens | Runs `gateway:swamp-credentials --release` in the candidate ([Renew the Swamp read and run tokens](/reference/gateway-environment#renew-the-swamp-read-and-run-tokens)); refuses with `gateway.release_swamp_token_expiring` while any live consumer holds a token expiring within 3 days |
| Load Swamp | Runs [`operation-gateway-swamp-load`](#load-swamp) on the Swamp Node |
| Activate | Drains Horizon, switches links via the rollback script, verifies `/up` and version at `https://gateway.orbit`, restarts Horizon, resumes cleanups, and prunes old releases |
| Revoke replaced tokens | Runs `gateway:swamp-credentials --revoke` to revoke replaced Gateway tokens once no process can hold them (non-fatal) |

A failed verification switches both links back to the previous release, resumes cleanups, and records `switched_back` (raising `release_failed`). Any other failure from Migrate onward, a timeout (3,600-second run budget), or an unreadable result pauses automatic releases for operator inspection.

After verification, pruning retains the newest `ORBIT_GATEWAY_RELEASES_KEEP` releases (default `3`, minimum `2`), the current and previous releases, and active web builds. A verified forward release also stamps task [deploy times](/reference/tasks#deploy-times) and queues the [fleet rollout](#fleet-rollout) pass.

Read releases with `orbit gateway:release:list` and `orbit gateway:release:show <id>`.

### Release idle guard

Host PHP-FPM releases wait up to 600 seconds (polling every 5 seconds) for active Operations whose execution input is built after admission. Container Gateway releases allow Operations to continue across cutover.

| Covered kind | Reason |
| --- | --- |
| `instance.create`, `instance.clone`, `instance.deploy*`, `instance.migrate`, `instance.destroy`, `instance.unregister`, `instance.restore` | Build container, environment, teardown, or Process input from serving Gateway code |
| `instance.mail:update`, `instance.sleep`, `instance.wake`, `instance.complete`, `instance.connection_environment`, `instance.node_service_detach` | Hold active Instance lifecycle or proxy state across cutover |
| `conn.*`, `process.*` | Build checkout, session, or Process unit state from serving code |

Kinds with plans fully captured at admission (`route.create`, `route.update`, `route.target_set`, `route.destroy`, `schedule.*`, `instance.env_import`, `proof.*`, `vault.sync`) are excluded. On timeout, stderr lists the blocking Operation IDs with `gateway.release_busy`, the old release stays live, and automatic releases pause.

### Load Swamp

Swamp serve on the Swamp Node reads `swamp/` from its staging checkout with `--hot-reload`. During each release, `operation-gateway-swamp-load` runs on the Swamp Node ([flow chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/gateway/swamp-load.mmd)):

1. Checks the target commit and verifies no switch is open on the Gateway Node.
2. Fetches and checks out the target commit in the serve checkout (forward-only unless forced).
3. Writes the rendered `swamp/grants/orbit-gateway.yaml` and `swamp/grants/orbit-serve.yaml` (`0640`) via `@swamp/cfgmgmt/file`.
4. Compares `swamp/ansible/requirements.yml` with `/opt/orbit/ansible/requirements.yml`; drift adds a `swamp.collections_drift` warning without failing the release.
5. Reloads Swamp serve (`swamp serve reload`) and grants (`swamp access reload`).
6. Applies trigger overrides from `swamp/config/swamp-node-schedules.json` ([Schedules of the Swamp Node](/reference/operations#schedules-of-the-swamp-node)).
7. Verifies served workflows match disk (excluding dynamic `schedule-*` workflows).

Load runs under `set -euo pipefail`, preserves `swamp` stderr on failure, and never restarts serve or workers. If serve rejects the serve-driver token, Load prints `gateway.release_swamp_token_expired` (start `orbit-swamp-token-renewal.service`, or mint by hand if already expired); if the Swamp Club credential env is missing or broken, it prints `gateway.release_swamp_auth_gate_blocked` (restore `/home/orbit/.config/swamp/env.sh`; [Swamp token renewal](/reference/node-provisioning#swamp-token-renewal)). Any failure pauses the release with `gateway.release_swamp_load_failed` while leaving the old Gateway live.

### Build and seal a release

Admission reads the target commit's pin from `apps/gateway/resources/agent/frozen-manifest.json`, verifies its published manifest, `SHA256SUMS`, and [provenance](#release-provenance) offline in PHP, and fails with `gateway.release_agent_delivery_unavailable` or `gateway.release_provenance_invalid` if invalid.

The build step verifies free disk space (database size plus 1 GiB), creates the worktree, links shared `.env` and storage, runs `composer install --no-dev` and `composer check-platform-reqs --no-dev` in `apps/cli` and `apps/gateway`, writes the agent manifest, seals source permissions with `REVISION`, and caches config at mode `0600`. Existing sealed releases of the same commit are reused.

#### Release provenance

CI signs each CLI/agent release with Sigstore ([CLI binaries: Provenance](/reference/cli-binaries#provenance)). Both release admission and [desired fleet state](/reference/self-update#how-the-gateway-resolves-it) download `provenance.sigstore.json` without authentication and verify it offline with PHP's OpenSSL extension against `apps/gateway/resources/sigstore/trusted_root.json`.

The verifier requires a Sigstore bundle v0.3 containing one Fulcio certificate, one DSSE envelope with a SLSA v1 in-toto statement, and one Rekor v1 `dsse` log entry with a valid signed entry timestamp, inclusion proof, and checkpoint. The certificate SAN must match `https://github.com/<owner>/<repo>/.github/workflows/orbit-cli-release.yml@refs/heads/main` with OIDC issuer `https://token.actions.githubusercontent.com`, and the statement must digest `SHA256SUMS` (and `manifest.json` for release admission).

Any invalid bundle or unsupported format refuses admission with `gateway.release_provenance_invalid`. A misconfigured trust root or non-GitHub repository refuses with `gateway.release_provenance_unconfigured` without spending retry attempts. To refresh the committed trust root:

```bash
gh attestation trusted-root | head -n 1 | jq . > apps/gateway/resources/sigstore/trusted_root.json
```

For an emergency key rotation before a commit lands, place a refreshed trust root in `ORBIT_HOME`, set `ORBIT_CLI_RELEASE_TRUSTED_ROOT` in the shared env file, run [`gateway:release:configure`](#apply-an-env-change), deploy the commit that updates the repo copy, and remove the override.

#### Web build

CI uploads `apps/web` as `web-dist-<sha>` (retained 14 days, max 128 MiB, SHA-256 required) from a green `push` or `workflow_dispatch` run of `.github/workflows/ci.yml` on `main`. Fork, PR, or non-main artifacts are rejected.

The Swamp step downloads, verifies the digest, extracts only regular files and safe relative directories (max 20,000 entries, 512 MiB, root `index.html` required), and atomically moves the build into `web/releases/<id>`. Missing or invalid artifacts refuse admission with `gateway.release_web_build_missing` or `gateway.release_web_build_invalid`. Container rollbacks reuse `old`'s installed web build.

### Migrations and the snapshot

Host PHP-FPM releases copy SQLite before migrating using `VACUUM INTO` to `ORBIT_HOME/backups/pre-<id>-<time>-<random>.sqlite` (mode `0600`), retaining the newest two snapshots plus any snapshot named by a pause.

Both build and snapshot enforce a disk floor of at least 2 GiB and 10% free space on the root disk (`gateway.release_disk_low`), pruning older snapshots first if needed. Migrations run via `php artisan migrate --force --isolated=1` while the old release still serves, so every migration must follow expand-and-contract rules. A failed migration pauses automation and names its snapshot for operator recovery.

### Roll back

Roll back to a previous release by its 12-character commit ID:

```bash
orbit gateway:release:rollback <id>
```

On a container Gateway, `<id>` must be the stopped `old` container. Admission starts a forced `gateway.release` with trigger `rollback` that needs no GitHub token, agent delivery, or web build. It starts `old`, recaches config from `.env`, restarts FrankenPHP, reloads `old`'s Swamp workflows, switches traffic through the journal, and retires the rolled-away live as the new `old`.

On a host PHP-FPM Gateway, rollback resolves the full commit, reuses the sealed release, reloads Swamp, snapshots SQLite, runs migrations, switches links, and verifies health. Rollbacks never restore database data and always pause automatic releases with reason `rollback`.

### Roll back without Swamp

When Swamp or the Gateway is unreachable, run the local rollback helper on the Gateway Node. On a container Gateway:

```bash
sudo /usr/local/libexec/orbit-gateway-rollback [<old-id>]
```

It runs the container rollback phases directly using Incus and Caddy without touching Swamp or Gateway records. To resume an interrupted container handover or rollback, run `sudo /usr/local/libexec/orbit-gateway-rollback --recover`. Once the Gateway answers again, reconcile Swamp workflows with `orbit gateway:release:deploy <full-sha> --reconcile` (if a reconcile lease is stranded in `/var/lib/orbit/gateway-release-switch/reconcile`, clear it with `sudo /usr/local/libexec/orbit-gateway-release-phase reconcile-end <sha> <operation-id>` after confirming the run stopped).

On a host PHP-FPM Gateway, run:

```bash
sudo /home/orbit/orbit/bin/gateway-rollback <id>
```

It atomically switches `/home/orbit/orbit` and `web/current`, reloads PHP-FPM, and restarts running services under the application path while preserving the shared database.

### Apply an env change

Back up `/home/orbit/shared/gateway.env` at mode `0600` before editing. Because releases cache Laravel configuration, apply edits explicitly:

- **Container Gateway:** Run `sudo /usr/local/libexec/orbit-gateway-environment configure` on the Node.
- **Host PHP-FPM Gateway:** Run `php /home/orbit/orbit/apps/gateway/artisan gateway:release:configure` as `orbit` to atomically rewrite `bootstrap/cache/config.php` (`0600`).

Never run `php artisan config:cache` directly in a live release.

## Automatic releases

The Gateway scheduler runs `gateway:release:auto` every 5 minutes (as a queued job when the [Gateway queue](/reference/gateway-queue#scheduled-work) is configured). Automatic releases are disabled by default. Enable, inspect, or disable them with:

```bash
orbit gateway:auto-release:enable
orbit gateway:auto-release:status
orbit gateway:auto-release:disable
```

Each tick checks enablement, pause state, active releases, and dead records, then reads the current `REVISION` and repository origin. It asks GitHub for the newest green first-parent commit on `ORBIT_GATEWAY_RELEASE_BRANCH` (default `main`) passing `ORBIT_GATEWAY_RELEASE_CHECK` (default `Required checks`) and `Gateway release inputs`. Once the serving release's fleet, default, and seed cycle settles, it admits the next Swamp release.

Retryable failures back off for 10 minutes (up to three attempts). Waiting for an unpublished agent delivery (`waiting_for_agent_delivery`) spends no retry budget but counts toward the 30-minute stall alert. Each tick prints one JSON summary:

| Result | Meaning |
| --- | --- |
| `disabled`, `paused` | Automatic releases are off or paused |
| `busy` | Another release holds the lock or is active |
| `not_adopted`, `no_deployed_release` | Invalid release layout or unreadable `REVISION` |
| `up_to_date` | No newer eligible commit exists |
| `waiting_for_release_cycle` | Serving release still has pending fleet, default, or seed work |
| `waiting_for_agent_delivery` | Candidate's agent delivery is not published yet |
| `backing_off` | Retryable failure is less than 10 minutes old |
| `source_unavailable` | GitHub, the App, or git origin could not be read |
| `started`, `failed` | Swamp run admitted, or admission failed |

Check `orbit gateway:auto-release:status` and `orbit gateway:status` to inspect state. Because the tick runs every 5 minutes, a last check older than 6 minutes indicates a scheduler or queue issue.

### Pause

A pause is durable state that stops automatic releases until you run `orbit gateway:auto-release:resume` or a manual release ends `verified`. Every pause raises a `release_paused` alert:

| Reason | Cause |
| --- | --- |
| `migration_failure` | Migrate or a later step failed without switching back |
| `rollback` | A rollback went live |
| `manual_deploy` | A manual deploy pinned an older commit while a newer green commit existed (or GitHub could not confirm) |
| `interrupted` | A release died after touching the schema or live link |
| `marker` | Legacy `ORBIT_HOME/gateway-release.paused` marker file exists |

Manual deploys and rollbacks still run while paused. Resume refuses with `gateway.release_not_paused` if nothing is paused, and failed commits are not automatically retried.

### Failure and alerts

A release that pauses, switches back, or exhausts retries raises one [release alert](#release-alerts) (recorded once per release). Two stall conditions raise `release_stalled`:

- GitHub errors, retryable failures, or a busy release lock lasting 30 minutes.
- The branch head remaining unreleased for 6 hours (checked even while paused, catching force pushes).

## Recover a failed update

Keep traffic and automation paused and preserve the failed state for diagnosis. Never run a generic migration rollback to guess schema compatibility with older code.

If failure occurred before any database or machine change, restore the previous commit and locked dependencies, then verify status and HTTPS. Once migrations or machine changes ran, restore the complete pre-update state set (preferring a stopped-machine VM snapshot). Never connect a restored clone to the live fleet alongside the original Gateway because they share WireGuard, SSH, and CA identities.

For an archive restore, stop all Gateway writers, move the failed `ORBIT_HOME` aside, and restore the archive with original ownership and permissions. Check out the matching commit, reinstall locked dependencies, restore matching external database and service config, and clear stale Laravel cache. Start services and verify status, Doctor, DNS, and HTTPS before reopening access.

## Release alerts

Release, fleet rollout, fleet reboot, and automatic production deployment failures raise alerts outside database transactions. Each alert records an Activity entry (`release:alert`), a [Problem](/reference/tasks#outer-loop) fingerprint (`release|{kind}|{resource}|{attempt}|{fault}`, eligible on first occurrence), and an optional signed webhook:

| Kind | When |
| --- | --- |
| `release_failed` | A release failed without going live, or switched back |
| `release_paused` | A release paused automatic releases |
| `release_stalled` | Automatic releases stalled for 30 minutes or head went unreleased for 6 hours |
| `release_follow_up_failed` | A serving release's [follow-up phase](#follow-up-phases) failed |
| `release_cleanup_paused` | Document cleanup remained paused after a release switch |
| `release_scheduler_silent` | The release scheduler stopped ticking within its expected window |
| `release_gateway_agent_failed` | The Gateway Node's own agent delivery or check failed |
| `rollout_halted` | A fleet rollout stopped at a failed Node |
| `reboot_halted` | A [fleet reboot](#fleet-reboot) pass stopped at a failed Node |
| `reboot_unconfirmed` | The Swamp Node scheduled a reboot but reported no new boot within 30 minutes |
| `reboot_overdue` | A Node remained reboot-due for more than 3 days |
| `instance_deploy_failed` | An automatic production Instance deployment failed |
| `instance_deploy_stalled` | An automatic production Instance deployment waited 30 minutes |
| `instance_deploy_paused` | A manual deploy or rollback paused automatic deployment of a production Instance |

Each alert carries `target`, `repository`, `sha`, `release_id`, redacted `summary` (max 1,000 chars), and optional `evidence_url`.

### Webhook

Configure both variables in the Gateway's `.env` to receive webhook alerts (leave both unset on disposable clones):

| Variable | Content |
| --- | --- |
| `ORBIT_RELEASE_ALERT_WEBHOOK_URL` | Receiver URL |
| `ORBIT_RELEASE_ALERT_WEBHOOK_SECRET` | HMAC-SHA256 signing secret (any random value for Slack incoming webhooks) |

The Gateway sends `POST` with `X-Orbit-Timestamp`, `X-Orbit-Signature: sha256={hex}` (signing `{timestamp}.{raw_body}`), and a JSON payload containing `event` (`release.alert`), `kind`, `subject`, `summary`, `evidence_url`, Slack-escaped `text`, `occurred_at`, `request_id`, `activity_id`, and `problem_fingerprint`. Timeouts are 3 seconds to connect and 10 seconds total, with no redirects or retries.

## Fleet rollout

A verified Gateway release triggers two distinct layers of fleet work:

- **Agent and CLI delivery (`fleet.rollout`):** Delivers the pinned `orbit-agent` binary (and packaged CLI on `ci-runner` Nodes) in risk order via `operation-fleet-rollout`. Trigger manually via `POST /api/v1/fleet/rollout`.
- **Verified release fleet pass:** Runs automatically after a Gateway release verifies and daily at 03:05 Europe/Amsterdam with rollout or refresh enabled.

The fleet pass validates the serving recipe before admitting work. A Conn Server release can require proof from a standalone canary. The pass dry-runs and updates that Node first. A failed canary holds the pass. Its Operation shows the failure and rollback result. The [Conn Server rollout](/reference/development-containers#conn-server-rollout) explains how to retry the failed stage and promote the verified pin.

During the verified release fleet pass, each eligible Node (with the serving Gateway Node last) runs delivery and then admits either a scoped role `node.converge` (when role inputs changed) or a full `node.converge` (when host inputs changed). Once all Nodes are `current`, `phases.fleet.status` becomes `current` and default refresh and seed publication may advance.

`ORBIT_FLEET_ROLLOUT` defaults to `true`. `ORBIT_FLEET_ROLLOUT_ORDER` accepts a comma-separated list of workload Node names to visit first.

A failed delivery stops the pass and raises `rollout_halted`. A failed role or host converge marks that Node `failed` and continues to the next Node so the phase ends `failed` and retries on the next pass.

Automatic role or host converges yield to active Instance, Conn, and Process work on the target Node for up to 5 minutes (`operation.target_busy` queues new conflicting work while waiting; removals `instance.destroy` and `instance.unregister` always pass), then admit beside remaining holders. An ended deployment admitted by the release's own refresh (`origin` or `default_refresh`) never holds the converge (dropping from `waiting_for`), while other ended Operations keeping a fence (such as an [executor fence](/reference/executor)) continue to hold the Node. Task work neither waits for nor blocks a converge.

To repair a single Node manually, run [`orbit node:converge NODE`](/cli/node/converge).

### Follow-up phases

Extensions can register release follow-up phases beside `fleet` (for example `ai_router_guard`). Follow-up phases run once `fleet` settles (`current`, `disabled`, or `failed`), keep automatic release admission waiting while `pending`, and raise `release_follow_up_failed` on failure without rolling back the Gateway.

### Rollout set and order

`fleet.rollout` includes every `active` managed Linux Node (with a WireGuard address and pinned SSH host key) that holds at least one active role other than `gateway` and a record-only role such as `agent` and is not the serving Gateway host. Roleless and macOS Nodes stay out and update via `orbit self-update`. The serving Gateway joins the verified release pass last for its own agent and workload roles.

Nodes run one at a time in lowest-risk order (a Node with multiple roles joins its highest-risk group, ordered by Node ID inside each group):

| Group | Roles |
| --- | --- |
| 1 | `app-dev` |
| 2 | `ci-runner`, `metrics`, `analytics` |
| 3 | `database`, `websocket`, `vpn`, `router` |
| 4 | `app-prod`, `ingress`, `swamp` |

Rollouts run at any hour right after a verified release with no time window. A busy binary-publication lock defers that Node without stopping the pass. If a commit's CLI release is still missing after 30 minutes, CLI-eligible Nodes [fall back to an ancestor release](/reference/self-update#fallback-to-an-ancestor-release).

### A failed Node

When delivery fails on a Node, native dependencies stop later Nodes, record the Node's error code, and raise `rollout_halted` once per Node, commit, and error code. After repairing the cause, the next pass (at least 5 minutes later, via a new release, the daily pass, or `POST /api/v1/fleet/rollout`) starts again at that Node. Do not use `swamp workflow resume` to retry a failed rollout.

### Which Nodes a pass visits

A `fleet.rollout` pass visits an eligible Node when it has no terminal result for the desired commit, its last visit ended `deferred`, `failed`, `cancelled`, or `skipped`, or its reported agent/CLI version drifted from desired state (with a 5-minute cooldown between visits). Doctor reports lagging rollout Nodes as `node.release_lag`.

### Nodes being updated

`GET /api/v1/nodes` and `GET /api/v1/nodes/{node}` expose `updating`. It is `null` unless a Gateway release record is `running`, in which case the active `gateway` Node reports `{ kind: "gateway_release", since, release }`. The Gateway broadcasts [`node.updated`](/reference/events#node) when a release starts running and when it ends.

## Fleet reboot

Every night at 03:06 Europe/Amsterdam (when `ORBIT_FLEET_REBOOT=true`, default `true`), Swamp runs a `fleet.reboot` pass that reboots managed Linux Nodes carrying `/var/run/reboot-required` one at a time after draining their work ([ADR 0253](/decisions/0253-patch-nodes-and-reboot-them-drained)). Start a manual pass at any hour with `POST /api/v1/fleet/reboot` (optionally with `node_ids`, ignoring the 03:00–06:00 Europe/Amsterdam window).

<div className="flow-at" data-chart="fleet/reboot"></div>

Fleet reboots, fleet rollouts, and Gateway releases never overlap (`fleet.reboot_waiting`, `fleet.rollout_busy`, `gateway.release_in_progress`, or `fleet.reboot_busy`). Nodes run in six risk groups: rollout groups 1–4 (with roleless Nodes in group 1 and `swamp` moved out of group 4), group 5 for the control path (`vpn` hub, Gateway Node, and the Node serving `swamp.orbit`), and group 6 for the `swamp` Node.

Hold a specific Node from rebooting with `orbit node:update NODE --reboot-hold=true` (clear with `--reboot-hold=false`).

### One Node of the pass

Each Node runs three Ansible phases (`drain`, `reboot`, `check`) from the Swamp Node with a fixed `reboot_drain_seconds` budget of 900 seconds:

| Phase | Work |
| --- | --- |
| `drain` | Retries SSH reads up to 7 times, skips halted/held/out-of-window Nodes, freezes and stops idle CI runners (`ci_busy` on timeout), or stops Gateway scheduler and drains Horizon |
| `reboot` | Reboots the Node and waits up to 10 minutes for SSH and a new boot. The `swamp` Node schedules `orbit-planned-reboot` to drain serve and workers afterward |
| `check` | Waits up to 5 minutes for a new boot ID, newest kernel, cleared `/var/run/reboot-required`, no newly failed units, and active `orbit-agent` |

A failed or unreachable Node (`fleet.node_unreachable`, `fleet.reboot_old_kernel`, `fleet.reboot_units_failed`, `fleet.reboot_lost`, or `fleet.reboot_check_failed`) halts later Nodes and raises `reboot_halted`. If the root run is interrupted without Node evidence, unrecorded Nodes report `unknown` (`run_interrupted`), fail the pass, and require manual verification of stopped runners or scheduled Swamp reboots.

Every 5 minutes, the Gateway confirms `scheduled` Swamp Node reboots via agent `metrics` uptime and raises `reboot_unconfirmed` after 30 minutes without a new boot. Doctor reports `node.reboot_due` and raises `reboot_overdue` when a Node stays reboot-due for more than 3 days (even with a reboot hold).

### CI jobs lost at the freeze

After a CI host reboots cleanly, two non-fatal steps (`mint` and `rerun`) mint scoped `actions: write` GitHub App tokens for Project repositories. They inspect failed runs whose jobs started within a minute of a runner freeze and were active at the freeze, call GitHub's `rerun-failed-jobs` endpoint once per affected attempt, and revoke the tokens. Results appear under `reran`, `rerun_pending`, `rerun_refused`, `rerun_truncated`, and `rerun_error`.

## Limits

Gateway releases and fleet rollouts operate within these boundaries:

- Releases require an established release layout or container setup, verified agent delivery, a valid CI web build, and Swamp access.
- Every migration must be expand-and-contract; rollbacks never restore database snapshots.
- Fleet rollouts visit one Node at a time; a failed delivery blocks later Nodes until repaired.
- `fleet.rollout` delivers only the agent binary, CLI binary, and public CLI profile; role and host footprint changes require `node:converge` or the verified release fleet pass.

## Why it works this way

### One small verifier for one bundle shape

The Gateway is the fleet's trust anchor, so it verifies release provenance offline before any Node sees the bytes, avoiding runtime GitHub API calls, workflow signing keys, or extra verification binaries. Accepting only the single Rekor v1 `dsse` bundle shape emitted by `actions/attest-build-provenance` keeps the PHP verifier small and fails closed if the upstream format changes.

### Expand and contract

The old release serves while the new release migrates. Keeping migrations additive allows zero-downtime cutover and safe rollback without restoring SQLite snapshots.

### Laravel owns migrations

Delegating migration discovery (including vendor migrations) to `artisan migrate --force --isolated=1` avoids duplicating migration state checks in release scripts.

### The Gateway is not an Orbit Instance

The Instance deploy pipeline depends on a healthy Gateway. Keeping the Gateway release path independent ensures you can always release, roll back, or recover the control plane directly.
