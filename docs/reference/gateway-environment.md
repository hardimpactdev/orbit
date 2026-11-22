---
title: "Gateway environment"
description: "Where the Gateway reads its own .env settings, how to apply a change, and what each key in the example file does."
covers:
  - apps/gateway/.env.example
  - apps/gateway/app/Domain/Nodes/GatewayRuntimeFiles.php
  - apps/gateway/app/Actions/*/*Swamp*Credential*Action.php
  - apps/gateway/app/Console/Commands/*Swamp*Credential*Command.php
  - apps/gateway/app/Console/Commands/InstallNestedFleetClubKeyCommand.php
  - apps/gateway/app/Domain/Tasks/SwampReader*.php
  - apps/gateway/app/Infrastructure/Tasks/SwampReaderEnvironment.php
  - apps/gateway/app/Infrastructure/SwampCredentials/*.php
---

# Gateway environment

The Gateway is a Laravel application that reads its settings from a `.env` file. This page documents the Gateway's own environment file; [Instance environment variables](/reference/environment-variables) covers `.env` files managed for Instances.

## Where the file lives

The environment file path depends on how the Gateway is deployed:

| Deployment | File path |
| --- | --- |
| Installed Gateway | `/home/orbit/shared/gateway.env` (linked by host releases, mounted read-only into container releases). |
| Local development | `apps/gateway/.env` (copy from `apps/gateway/.env.example`). |

Installed Gateways cache configuration, so edits take effect only after [Apply an env change](/reference/gateway-recovery#apply-an-env-change).

## Required Swamp SSH identity

Every deployment must set `ORBIT_SWAMP_SSH_PUBLIC_KEY` to the OpenSSH public key of the swamp role worker identity (`ssh-keygen -y -f /etc/orbit/node-proxy/id_ed25519` on the swamp Node). Without a valid key, SSH-backed operations refuse with HTTP 503 (`swamp.ssh_identity_unavailable`). Update this key whenever rotating that identity.

## Swamp task output and credentials

These settings configure Gateway reads from Swamp and the nested-fleet Club key:

| Variable | Meaning |
| --- | --- |
| `ORBIT_SWAMP_READ_TOKEN` | Read-only Swamp token (`workflow:*`, `data:*`) for [Swamp run output](/reference/tasks#swamp-run-output) and dependency views. Max 4,096 bytes; never exposed to browsers. |
| `ORBIT_SWAMP_RUN_TOKEN` | Swamp execution token used by the Gateway to start Operations. |
| `ORBIT_SWAMP_ADDRESS` | Swamp server address (default `swamp.orbit` on port 443; TLS always verifies `swamp.orbit`). |
| `ORBIT_SWAMP_READ_TOKEN_NAME` | Installed reader token name (`[A-Za-z0-9._-]+`), written by `gateway:swamp-credentials` or `gateway:swamp-reader:install`. |
| `ORBIT_SWAMP_READ_TOKEN_EXPIRES_AT` | Installed reader token UTC expiry, written by the installer and checked hourly. |
| `ORBIT_SWAMP_RUN_TOKEN_NAME`, `ORBIT_SWAMP_RUN_TOKEN_EXPIRES_AT` | Installed run token name and UTC expiry, written by `gateway:swamp-credentials`. |
| `ORBIT_SWAMP_CA_PATH` | Orbit root CA file (default `<ORBIT_HOME>/ca/root.pem`). |
| `ORBIT_NESTED_FLEET_CLUB_API_KEY` | Swamp Club key delivered to nested-fleet Gateways, written by `gateway:nested-fleet-club-key:install`. |

### Install the nested-fleet Club key

Run `gateway:nested-fleet-club-key:install` on the Gateway host as `orbit`, piping the key on stdin (at least 16 printable characters, no spaces or quotes):

```bash
set +x
<key on stdin> | php /home/orbit/orbit/apps/gateway/artisan gateway:nested-fleet-club-key:install
```

The installer holds the Gateway release lock, creates a mode-`0600` backup, atomically updates `shared/gateway.env`, and republishes the read-only container env mount. Because run starts read this key directly from the env file, it takes effect without reloading queue workers ([Club key of the nested Gateway](/reference/development-containers#the-club-key-of-the-nested-gateway)). If publication is interrupted, run `sudo /usr/local/libexec/orbit-gateway-environment refresh`.

### Renew the Swamp read and run tokens

The Gateway holds two 30-day Swamp access tokens: `ORBIT_SWAMP_READ_TOKEN` (`user:orbit-gateway-read`, workflow and data read grants only) and `ORBIT_SWAMP_RUN_TOKEN` (`user:orbit-gateway`). Each generation is named `<base>-<YYYYMMDD>` (or `<base>-<YYYYMMDD>-2` if that day's name is revoked or unusable); the active generation is the prefix before the first `.` of the env value (`_NAME` and `_EXPIRES_AT` are informative only).

`gateway:swamp-credentials` runs hourly (and during every Gateway release via `--release` / `--revoke`) from live facts only, calling `/usr/local/lib/orbit-swamp/gateway-token` on the Swamp Node over managed SSH:

1. Lists active tokens and consumer generations without returning secrets; any Gateway token expiring within 10 days or inactive is due.
2. Mints today's dated name (`mint read|run`, or reveals an already active generation of today so lost replies never mint twice) and streams the reveal on stdout without logging or passing secrets as arguments.
3. Verifies a workflow-history read, writes a mode-`0600` backup, atomically updates `shared/gateway.env`, and runs `gateway:release:configure`.
4. Revokes a predecessor generation once the serving config holds the new name and all long-running Gateway processes have restarted since the env changed.

Long-running Gateway processes checked for restart are `orbit-agent-view`, Horizon, engine, and `schedule-work`. On [release containers](/reference/gateway-recovery#move-the-gateway-to-release-containers), `swamp/scripts/gateway-swamp-token-install.py` and `orbit-gateway-environment refresh` publish the updated read-only mount. `swamp access token rotate` is never used because it ends active sessions immediately.

During a Gateway release, `Renew Swamp tokens` starts `orbit-swamp-token-renewal` on the Swamp Node ([Swamp token renewal](/reference/node-provisioning#swamp-token-renewal)), renews due Gateway tokens, and re-caches candidate config. It prints `gateway.swamp_node_renewal_not_installed` if the Swamp Node unit is absent, and fails with `gateway.release_swamp_token_expiring` if any live consumer still holds a token expiring within 3 days. After handover, `Revoke replaced tokens` runs `--revoke`. If hourly renewal fails and an installed Gateway token expires within 3 days, the Gateway opens an operator assistance request on the `orbit` Project.

`gateway:swamp-reader:install` remains available as the manual stdin installer for `ORBIT_SWAMP_READ_TOKEN` (max 16 KiB `swamp access token reveal --yes --json` with `--expires-at`). After manual install, apply config, verify a fresh `/api/v1/tasks/{task}/swamp-runs` read, and revoke the old name once no Gateway process holds it.

## Services dependency detection

Set `ORBIT_DEPENDENCY_DETECTION_SERVICES_NODE_ID` (mapped to `dependency_detection.services_node_id`) to the Services Node ID to enable `POST /api/v1/projects/{project}/dependency-read-token`. When unset or invalid, the endpoint stays disabled. See [GitHub App: Services dependency credentials](/reference/github-app#services-dependency-credentials) for activation steps.

## Keys in the example file

`apps/gateway/.env.example` lists the core settings a Gateway needs. All other keys follow standard [Laravel configuration](https://laravel.com/docs/configuration).

| Key | Meaning |
| --- | --- |
| `APP_NAME`, `APP_ENV`, `APP_DEBUG`, `APP_URL`, `APP_LOCALE`, `APP_FALLBACK_LOCALE`, `APP_FAKER_LOCALE`, `APP_MAINTENANCE_DRIVER`, `APP_MAINTENANCE_STORE` | Standard Laravel app settings. |
| `APP_KEY` | Encryption key. When empty, the Gateway reads `ORBIT_HOME/gateway.app-key`. |
| `ORBIT_HOME` | Durable Gateway state directory (default `$HOME/.orbit`). See [Preserve a complete state set](/reference/gateway-recovery#preserve-a-complete-state-set). |
| `ORBIT_GATEWAY_CHECKOUT` | Stable Gateway checkout path (default `/home/orbit/orbit/apps/gateway`). |
| `ORBIT_GATEWAY_CONTAINER` | Set to `1` by release containers (not in `.env`). See [Operation-version fence](/reference/operations#operation-versions-across-a-gateway-release). |
| `PHP_CLI_SERVER_WORKERS`, `BCRYPT_ROUNDS` | Local dev server workers and password hash cost. |
| `LOG_CHANNEL`, `LOG_STACK`, `LOG_DAILY_DAYS`, `LOG_DEPRECATIONS_CHANNEL`, `LOG_LEVEL` | Laravel logging ([Gateway request logs](/reference/gateway-recovery#gateway-request-logs)). |
| `DB_CONNECTION`, `DB_DATABASE` | SQLite database connection; empty `DB_DATABASE` uses `ORBIT_HOME/gateway.sqlite`. |
| `SESSION_*`, `FILESYSTEM_DISK`, `QUEUE_CONNECTION`, `CACHE_STORE` | Laravel session (`array` for stateless API), storage, queue, and cache drivers. |
| `ORBIT_GATEWAY_QUEUE_CONNECTION`, `ORBIT_GATEWAY_QUEUE_REDIS_DATABASE` | Redis Database connection and index for the [Gateway queue](/reference/gateway-queue) (Horizon). Tests set `ORBIT_GATEWAY_QUEUE_DRIVER=database`. |
| `ORBIT_HIBERNATION_IDLE_SECONDS`, `ORBIT_HIBERNATION_SWEEP_SECONDS`, `ORBIT_HIBERNATION_WAKE_TIMEOUT_SECONDS`, `ORBIT_HIBERNATION_CONTAINER_SLEEP`, `ORBIT_HIBERNATION_CONTAINER_NEVER_SLEEP` | [App-dev runtime hibernation](/reference/app-dev-runtime-hibernation) and [container sleep](/reference/development-containers#sleep-and-wake) settings. |
| `ORBIT_VISIT_WAKE_WAIT_SECONDS` | Seconds the Gateway holds an HTTP visit to a sleeping container Instance while waking (default `30`). |
| `ORBIT_ENGINE_KINDS`, `ORBIT_NATS_SEED`, `ORBIT_NATS_PORT` | [Gateway engine](/reference/gateway-engine) Operation routing and NATS machine bus settings (default port `4222`). |
| `ORBIT_EXECUTOR_PUBLIC_KEYS`, `ORBIT_EXECUTOR_SIGNING_KEY`, `ORBIT_EXECUTOR_WAIT_MS`, `ORBIT_EXECUTOR_WAKE_ENABLED`, `ORBIT_EXECUTOR_POWER_ENABLED` | [Executor](/reference/executor) Ed25519 keys, wake budget (default `500` ms), and rollout switches. |
| `ORBIT_CONN_HELD_SESSION_PREMINT`, `ORBIT_CONN_MANAGED_TARGETS`, `ORBIT_CONN_THREAD_INDEX_POLL` | [Conn](/reference/conn) held-session preminting, lab grant revocation routes, and thread-index polling (all off by default). |
| `ORBIT_WEBSOCKET_REPOSITORY`, `ORBIT_WEBSOCKET_INSTALL_PATH`, `ORBIT_WEBSOCKET_PORT` | Defaults for the `websocket` role ([Reverb apps](/reference/websocket-apps#the-reverb-server)). |
| `TYPESAFE_API_KEY` | API key for the Jev classifier in [Tasks](/reference/tasks). |
| `ORBIT_TASKS_COPIES_MAX`, `ORBIT_TASKS_COPY_PUBLISH_PROJECTS`, `ORBIT_TASKS_PR_MODE`, `ORBIT_TASKS_RESERVED_TIMEOUT_SECONDS`, `ORBIT_TASKS_REVIEW_REQUEST_LOGINS`, `ORBIT_TASKS_PULL_REQUEST_AUTHORS`, `ORBIT_TASKS_GITHUB_REVIEWERS` | [Tasks](/reference/tasks) copy limits, publish allowlist, PR mode, claim timeout, and GitHub reviewer/author lists. |
| `ORBIT_TASKS_COPY_IMPLEMENTER_*`, `ORBIT_TASKS_COPY_REVIEWER_*` | Default `PROVIDER` (`claude`), `MODEL` (`claude-opus-5-5`), and `EFFORT` (`medium` / `high`) for [task copy threads](/reference/tasks#copy-turn-agents). |

### Feature and retention keys

Additional keys live in `config/orbit.php` and `config/retention.php` rather than `.env.example`:

- **Nested-fleet Projects:** `ORBIT_INSTANCES_NESTED_FLEET_PROJECTS` (JSON array of Project IDs, default `[]`) and `ORBIT_INSTANCES_DEFAULT_DESTROY_PROJECTS` (JSON array of disposable Project IDs whose default may be destroyed, default `[]`).
- **Nested-fleet maintenance:** `ORBIT_INSTANCES_NESTED_FLEET_REFRESH` (post-release refresh, default `false`), `ORBIT_INSTANCES_NESTED_DEFAULT_ROOT_SIZE` (`<n>GiB`, min `40GiB`, default `80GiB`), and `ORBIT_INSTANCE_REMOVAL_REAPER` (default `true`).
- **Analytics, releases, and agent delivery:** `ORBIT_ANALYTICS_PLAUSIBLE_VERSION`, `ORBIT_CLI_RELEASE_TRUSTED_ROOT` ([Release provenance](/reference/gateway-recovery#release-provenance)), `ORBIT_RELEASE_ALERT_WEBHOOK_*`, `ORBIT_AGENT_DELIVERY`, and `ORBIT_AGENT_CONTRACT` ([Node agent](/reference/node-agent#commands)).
- **Disk and record retention:** `ORBIT_FAILED_JOBS_HOURS`, `ORBIT_JOB_BATCH_HOURS`, `ORBIT_ACTIVITY_LOG_DAYS`, `ORBIT_JOURNAL_*`, and `ORBIT_LOG_ROTATE_*` ([Gateway disk retention](/reference/gateway-recovery#disk-retention)).

Fleet-owned settings such as `instances.mailpit` and `instances.fleet_projection` (default `/opt/orbit-gateway-environment` for container Gateways) need no `.env` key.

## Settings no code reads

During `gateway` role convergence, Orbit automatically strips unused settings listed in `GatewayRuntimeFiles::RetiredSettings` from `shared/gateway.env`, including `ORBIT_SWAMP_DIRECT_WRITERS`, `ORBIT_PI_PORT`, `ORBIT_SWAMP_PROOF_CLUB_API_KEY`, and all `ORBIT_TASK_VMS_*` keys. You can also remove unused `ORBIT_GATEWAY_RELEASE_SMOKE_TIMEOUT` and `ORBIT_GATEWAY_RELEASE_SMOKE_PROJECT` keys when editing `shared/gateway.env`.
