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

The Gateway is a Laravel application, so it reads its own settings from a `.env` file. This page covers that file. [Instance environment variables](/reference/environment-variables) covers the `.env` files that the Gateway manages for Instances.

## Settings no code reads

A setting that no code reads any more leaves the file through the `gateway` role's convergence, never by hand. `GatewayRuntimeFiles::RetiredSettings` lists them, and the role's runtime phase removes each key from `shared/gateway.env`. Every release links that file as its `.env`. It now lists these settings:

| Setting |
| --- |
| `ORBIT_TASK_VMS_UPCLOUD_TOKEN_FILE` |
| `ORBIT_TASK_VMS_UPCLOUD_MAX_VMS` |
| `ORBIT_TASK_VMS_UPCLOUD_PLAN` |
| `ORBIT_TASK_VMS_UPCLOUD_DISK_GB` |
| `ORBIT_SWAMP_DIRECT_WRITERS` |
| `ORBIT_TASK_VMS_ENABLED` |
| `ORBIT_TASK_VMS_CONTAINER_PLACEMENT` |
| `ORBIT_TASK_VMS_DEV_CLUSTER_ID` |
| `ORBIT_TASK_VMS_WIREGUARD_RANGE` |
| `ORBIT_TASK_VMS_SWAMP_HOST` |
| `ORBIT_TASK_VMS_MEDIUM_PROJECTS` |
| `ORBIT_TASK_VMS_LARGE_PROJECTS` |
| `ORBIT_TASK_VMS_PI_ARTIFACT_PATH` |
| `ORBIT_TASK_VMS_PI_ARTIFACT_SHA256` |
| `ORBIT_TASK_VMS_PI_MODELS` |
| `ORBIT_TASK_VMS_INCUS_HOSTS` |
| `ORBIT_TASK_VMS_TOPOLOGY_PATHS` |
| `ORBIT_PI_PORT` |
| `ORBIT_SWAMP_PROOF_CLUB_API_KEY` |

The four UpCloud task VM settings are unread since #3181 removed UpCloud. `ORBIT_SWAMP_DIRECT_WRITERS` chose a writer per Operation kind; every kind now has one writer. The other `ORBIT_TASK_VMS_*` settings, `ORBIT_PI_PORT` and `ORBIT_SWAMP_PROOF_CLUB_API_KEY` configured task VMs, the removed Pi server and the proof topologies of task VMs; the Gateway reads none of them since the task VM settings went ([old paths](/reference/old-paths#task-vm-settings)). Port 3774 is free again: a Node service may use it.

## Services dependency detection

The orchestrator enables read-token requests for one Services peer during deployment.

| Variable | Meaning |
| --- | --- |
| `ORBIT_DEPENDENCY_DETECTION_SERVICES_NODE_ID` | Gateway-owned Services Node ID, exposed as `dependency_detection.services_node_id`. Unset or invalid disables the dependency read-token route. The route ships disabled until the orchestrator follows the activation steps below. |

Deploy Gateway first. Verify Services with `orbit node:show 17 --json`. On Gateway as `orbit`, back up `/home/orbit/shared/gateway.env` with mode `0600`, then set its single `ORBIT_DEPENDENCY_DETECTION_SERVICES_NODE_ID=17` entry without changing other settings. Apply the change with the command for the current [Gateway deployment](/reference/gateway-recovery#apply-an-env-change). From Services, verify `POST /api/v1/projects/20/dependency-read-token` succeeds without printing its token, then deploy Services. Follow the [release-safe env procedure](/reference/gateway-recovery#apply-an-env-change) and the exact backup/route-check commands in the [GitHub App](/reference/github-app#services-dependency-credentials) page. The orchestrator applies this setting during deployment; a merge does not enable it.

## Required Swamp SSH identity

Every deployment must set `ORBIT_SWAMP_SSH_PUBLIC_KEY`. It is the public half of the swamp role worker's SSH identity. Without a valid key, every `identity()` and `forNode()` caller refuses with HTTP 503 and `swamp.ssh_identity_unavailable`.

| Variable | Meaning |
| --- | --- |
| `ORBIT_SWAMP_SSH_PUBLIC_KEY` | Required OpenSSH public key for the swamp role worker identity. Set it from `ssh-keygen -y -f /etc/orbit/node-proxy/id_ed25519` on the swamp Node, the source the role installs as `/etc/orbit/swamp-role-worker/ssh-identity`. Update it when rotating that identity. |

## Swamp task output

The Gateway reads [Swamp run output](/reference/tasks#swamp-run-output) with these optional deployment settings. Without a reader token, the task panel shows unavailable.

| Variable | Meaning |
| --- | --- |
| `ORBIT_SWAMP_READ_TOKEN` | Dedicated Swamp token with workflow and output-data read grants only. Store it in the Gateway environment; never expose it to the browser. |
| `ORBIT_SWAMP_ADDRESS` | Swamp server address, default `swamp.orbit`, on port 443. Set its WireGuard address when Gateway DNS cannot resolve that name. TLS always verifies `swamp.orbit`. |
| `ORBIT_SWAMP_READ_TOKEN_NAME` | Installed token name, written by `gateway:swamp-credentials` or `gateway:swamp-reader:install`. |
| `ORBIT_SWAMP_READ_TOKEN_EXPIRES_AT` | Installed UTC expiry, written by the installer and checked hourly. |
| `ORBIT_SWAMP_RUN_TOKEN_NAME`, `ORBIT_SWAMP_RUN_TOKEN_EXPIRES_AT` | The same for `ORBIT_SWAMP_RUN_TOKEN`, written by `gateway:swamp-credentials`. |
| `ORBIT_SWAMP_CA_PATH` | Orbit root CA file. Default `<ORBIT_HOME>/ca/root.pem`, with `ORBIT_HOME` defaulting to `$HOME/.orbit`. |
| `ORBIT_NESTED_FLEET_CLUB_API_KEY` | The Swamp Club key of nested fleets' Gateways. Written by `gateway:nested-fleet-club-key:install`; each run start reads this file. Empty: a nested-fleet container starts without it. |

Two provisions receive a names-only execution copy: Project 62 (`swamp-proof`), and the task group that a `proof:run` on a task VM creates. Every other task, in the Orbit Project too, gets none. A `proof:run` in copies gets no proof credential: each copy's nested Gateway gets the nested-fleet key at its start.

Proof provision writes the credential to `/home/orbit/.config/swamp/club-api-key` inside the owned Task VM, mode `0600` for `orbit`, in a `0700` directory. Three pinned official `@swamp/ssh exec` argument lists run with sudo: `install -d` makes the directory, `rm -f` drops a previous file or link, and `install … /dev/stdin` writes the token. The native parent sends the token only on SSH stdin. Run history records that the step had stdin, never the value; neither the Operation input nor its result contains it. Each step has a 10-second dispatch budget and runs under `timeout 30`. A missing credential refuses proof placement. Normal task cancellation destroys the VM and its private filesystem.

### Install the nested-fleet Club key

`gateway:nested-fleet-club-key:install` writes `ORBIT_NESTED_FLEET_CLUB_API_KEY` from stdin. Run it on the Gateway host as `orbit`, including when containers serve traffic. It reads one token of at least 16 printable characters without spaces or quotes; one final newline is allowed. It holds the Gateway release lock and uses the same protected env writer as the reader installer: a private mode-`0600` backup, then one atomic rename that keeps every other setting. It never prints the value or takes it as an argument.

```bash
set +x
<the key on stdin> | php /home/orbit/orbit/apps/gateway/artisan gateway:nested-fleet-club-key:install
```

On a prepared Gateway, the installer also publishes the replacement into the directory mounted read-only by every release. Only the current env file is visible there. The backup stays on the host.

Each successful installation takes effect without a worker reload. The queue workers that start runs keep the configuration they loaded at boot, so each run start reads this one setting from the env file itself. The next start of each nested-fleet container delivers it ([Club key of the nested Gateway](/reference/development-containers#the-club-key-of-the-nested-gateway)). If the host process stops during publication, run `sudo /usr/local/libexec/orbit-gateway-environment refresh` before starting another nested fleet. This republishes the complete host file. Keep the private backup under the Gateway operator's retention policy.

### Renew the Swamp read and run tokens

The Gateway holds two Swamp access tokens: `ORBIT_SWAMP_READ_TOKEN` (principal `user:orbit-gateway-read`, workflow and data read grants only) and `ORBIT_SWAMP_RUN_TOKEN` (principal `user:orbit-gateway`). Each lasts 30 days. A generation is named `<base>-<YYYYMMDD>` after its UTC renewal day, such as `orbit-gateway-read-20261121`. When that name is revoked or unusable, the next is `<base>-<YYYYMMDD>-2`. A plain `orbit-gateway-read` or an older `<base>-<UTC stamp>` belongs to the same family. The generation in use is the name before the first `.` of the env value. `_NAME` and `_EXPIRES_AT` beside it are informative only.

`gateway:swamp-credentials` runs every hour. It works from live facts only, so a rerun after a crash at any step finds the same facts and finishes:

The Gateway finds the Swamp Node by its `swamp` role and reaches it over its managed SSH. There it runs one fixed entrypoint that the swamp role installs, `/usr/local/lib/orbit-swamp/gateway-token`:

1. `list` returns each token's name, principal and expiry, and the generation each Swamp Node consumer holds. It returns no secret.
2. A token is due by that list: its generation in the env expires within ten days, or is not active.
3. `mint read|run` mints today's name with the base's fixed principal for 30 days.
4. An active name of today is revealed again instead. A lost reply therefore never mints a second generation.
5. The Gateway reads the reveal on stdout. No argument, log or message holds the secret.

The protected installer then does the switch:

1. It requires a dated name of that base and an expiry more than ten days away.
2. It verifies a workflow-history read with the new token and writes a private mode-`0600` backup.
3. It replaces the token, its name and its expiry in one atomic rename.
4. The command applies `gateway:release:configure` as long as the cached configuration holds an older name. A rerun after a failed configure applies it too.

A predecessor is another active token of the same family and principal that expires before the installed one. The command revokes it when two facts hold. First, the serving configuration holds the installed name. Second, every long-running Gateway process started after the env last changed: `orbit-agent-view`, Horizon, the engine and `schedule-work`. Systemd reports when each main process started. A release handover restarts them; a Reconcile does not count.

In a [release container](/reference/gateway-recovery#move-the-gateway-to-release-containers) the guest sees the env only as a read-only projection. Both the hourly run and a release install due tokens through the same protected host path. `swamp/scripts/gateway-swamp-token-install.py` runs on the host as `orbit` to write the backup and atomic rename. Then `orbit-gateway-environment refresh` publishes the new inode to the read-only projection, and `gateway:release:configure` (or the candidate's config cache during a release) caches it.

During a release, the `swamp-credentials` phase runs `gateway:swamp-credentials --mint-for-host=read|run` in the candidate and pipes each verified reveal into that host script. The hourly run reaches the Gateway host over managed SSH with the reveal on protected stdin. The projection is never writable.

This uses the existing SSH authority of the Gateway over the Swamp Node and adds no grant. The entrypoint mints only the two Gateway bases with their fixed principals, so the read token stays read-only. It revokes only `orbit-gateway-read*` and `orbit-gateway-run*` names. `swamp access token rotate` is never used: it would end every session that still uses the name.

A release meeting the release lock ends the hourly run without error; the release renews itself.

Every Gateway release, on a host or in release containers, runs its `Renew Swamp tokens` step before `Load Swamp on Node 209`. It starts `orbit-swamp-token-renewal` on the Swamp Node ([Swamp token renewal](/reference/node-provisioning#swamp-token-renewal)), renews the Gateway tokens, and caches the candidate's configuration again, so the new release loads the installed generation. Until the swamp role converge later in the release installs `orbit-swamp-token-renewal.service`, the step prints `gateway.swamp_node_renewal_not_installed` and skips only the Swamp Node's renewal.

The step then fails with `gateway.release_swamp_token_expiring` while a live consumer holds a generation that expires within three days, or that serve does not list. Those are the serve-driver file, each worker slot's `server.token` and `enrollment.token`, and both Gateway env values. After the handover, `Revoke replaced tokens` runs the revocation rule above. A failed or skipped revocation is found again by the hourly run.

When renewal fails and an installed read or run token expires within three days, the hourly run asks the operator for assistance on the `orbit` Project. The Swamp credential monitor cannot see the Gateway env, so this is the alert for the generation the Gateway holds.

`gateway:swamp-reader:install` stays the manual writer for the read token, for example after a lost Swamp control plane. It reads at most 16 KiB of `swamp access token reveal --yes --json` from stdin with the mint's `--expires-at`, verifies a read, and writes the same backup and atomic rename. Apply configuration after it, verify a fresh `/api/v1/tasks/{task}/swamp-runs` read, and revoke the old token only once no Gateway process holds it.

Swamp stores the authoritative secret encrypted in its control-plane vault. `reveal` emits plaintext on stdout inside the private pipe. The writer's temporary file, the installed env file, protected backups, and Laravel's configuration cache contain plaintext and require mode `0600`. The [Swamp access commands](https://swamp-club.com/manual/reference/swamp-serve/access-commands) define minting, reveal, and revocation.

#### Why the Gateway renews its own tokens

An operator renewal missed its deadline once: the serve-driver token expired and two releases failed at Load Swamp. The Gateway already reaches the Swamp Node over its managed SSH, as `orbit`, the account that holds the serve-driver token, so that authority could always mint.

A fixed entrypoint narrows what the Gateway runs with it to the two Gateway bases and their own revocation, and grants it nothing in Swamp: the read token stays read-only. Minting a new name, then switching, then revoking once no process can hold the old name keeps each live session open; `rotate` would end them.

## Where the file lives

The file's path depends on how the Gateway runs.

| Gateway | File |
| --- | --- |
| Installed Gateway | `/home/orbit/shared/gateway.env`. Host releases link to it. Container releases read a private mount. |
| Development checkout | `apps/gateway/.env`. Copy it from `apps/gateway/.env.example`. |

An installed Gateway caches its configuration, so an edit takes effect only after [Apply an env change](/reference/gateway-recovery#apply-an-env-change).

## Keys in the example file

`apps/gateway/.env.example` lists the keys a new Gateway needs, with development defaults. Keys that start with `ORBIT_` are Orbit settings. The other keys are standard [Laravel configuration](https://laravel.com/docs/configuration).

| Key | Meaning |
| --- | --- |
| `APP_NAME`, `APP_ENV`, `APP_DEBUG`, `APP_URL` | Laravel application name, environment, debug mode, and URL. |
| `APP_KEY` | Encryption key. When it is empty, the Gateway reads `gateway.app-key` in `ORBIT_HOME`, so a replaced checkout keeps reading the settings it encrypted. |
| `APP_LOCALE`, `APP_FALLBACK_LOCALE`, `APP_FAKER_LOCALE` | Laravel locales. |
| `APP_MAINTENANCE_DRIVER`, `APP_MAINTENANCE_STORE` | Where Laravel records maintenance mode. |
| `ORBIT_HOME` | Directory for the Gateway's durable state. It defaults to `$HOME/.orbit`. [Preserve a complete state set](/reference/gateway-recovery#preserve-a-complete-state-set) lists what it holds. |
| `ORBIT_GATEWAY_CHECKOUT` | Stable path of the Gateway checkout. The [release layout](/reference/gateway-recovery#release-layout) and the Gateway's Node agent units use it. It defaults to `/home/orbit/orbit/apps/gateway`. |
| `ORBIT_GATEWAY_CONTAINER` | Not in the env file. The release container runtime sets it to `1`. See the [Operation-version fence](/reference/operations#operation-versions-across-a-gateway-release). |
| `ORBIT_HIBERNATION_IDLE_SECONDS`, `ORBIT_HIBERNATION_SWEEP_SECONDS`, `ORBIT_HIBERNATION_WAKE_TIMEOUT_SECONDS` | Development runtime hibernation timing. See [App-dev runtime hibernation](/reference/app-dev-runtime-hibernation). |
| `ORBIT_CONN_HELD_SESSION_PREMINT` | The pre-mint of held Conn sessions when a container Instance's T3 server is recorded. Off by default; a Node's first read makes its session. See [Held sessions](/reference/conn#how-the-gateway-makes-one). |
| `ORBIT_CONN_MANAGED_TARGETS` | The routes of managed Conn grant revocation for lab targets. Off by default; edge removal records managed revisions either way. See [Managed grant revocation](/reference/conn#managed-grant-revocation). |
| `ORBIT_CONN_PUSH` | iOS push notification device registration, attention ingestion, and APNs dispatch for Conn. Off by default. See [Push notifications](/reference/conn#push-notifications). |
| `ORBIT_CONN_THREAD_INDEX_POLL` | The reads of the Conn thread index: the minute poll, the list's read-through and the last read before a sleep. Off by default. See [Thread index](/reference/conn#thread-index). |
| `ORBIT_HIBERNATION_CONTAINER_SLEEP`, `ORBIT_HIBERNATION_CONTAINER_NEVER_SLEEP` | Which containers the idle sleep acts on: `false` (the default, also when invalid), `without_t3` or `true`; and the Instance ids it skips. Planned: production lives too ([ADR 0265](/decisions/0265-sleep-idle-production-containers)). See [Sleep and wake](/reference/development-containers#sleep-and-wake). |
| `PHP_CLI_SERVER_WORKERS` | Number of workers for `php artisan serve` in local development. |
| `BCRYPT_ROUNDS` | Laravel password hashing cost. |
| `LOG_CHANNEL`, `LOG_STACK`, `LOG_DAILY_DAYS`, `LOG_DEPRECATIONS_CHANNEL`, `LOG_LEVEL` | Laravel logging. [Gateway request logs](/reference/gateway-recovery#gateway-request-logs) describes the files. |
| `DB_CONNECTION` | Laravel database connection. The Gateway uses SQLite. |
| `DB_DATABASE` | Path of the SQLite database. Empty means `ORBIT_HOME/gateway.sqlite`. A bootstrapped Gateway names that path, because the waits and the start of a nested fleet read it here. |
| `SESSION_DRIVER`, `SESSION_LIFETIME`, `SESSION_ENCRYPT`, `SESSION_PATH`, `SESSION_DOMAIN` | Laravel sessions. The API is stateless, so the example uses the `array` driver. |
| `FILESYSTEM_DISK`, `QUEUE_CONNECTION`, `CACHE_STORE` | Laravel storage, queue, and cache drivers. |
| `ORBIT_GATEWAY_QUEUE_CONNECTION`, `ORBIT_GATEWAY_QUEUE_REDIS_DATABASE` | The Redis Database connection and database index of the [Gateway queue](/reference/gateway-queue), which Laravel Horizon works. Empty, the scheduler runs its commands itself. Tests set `ORBIT_GATEWAY_QUEUE_DRIVER=database` to keep the queue in SQLite. |
| `ORBIT_ENGINE_KINDS` | Comma-separated Operation kinds that run on the [Gateway engine](/reference/gateway-engine) instead of Swamp, such as `firewall.allow,firewall.deny,firewall.remove`. Empty by default: every kind runs on Swamp |
| `ORBIT_NATS_SEED` | The machine bus's nkey account seed, from `orbit:engine-keys`. Without it the Gateway runs no `nats-server`. See [Authentication](/reference/gateway-engine#authentication) |
| `ORBIT_NATS_PORT` | The machine bus's port, default `4222`. `nats-server` listens on it on the Gateway's WireGuard address only; the Gateway and every Node connect there |
| `ORBIT_EXECUTOR_PUBLIC_KEYS` | One or two base64 Ed25519 public keys, comma-separated, that every [executor](/reference/executor) Node pins. Until it is set, converges skip the executor install. See [Rollout](/reference/executor#rollout) |
| `ORBIT_EXECUTOR_SIGNING_KEY` | The base64 64-byte Ed25519 secret key that signs every [executor](/reference/executor) order, whose public half is one of `ORBIT_EXECUTOR_PUBLIC_KEYS`. Only `orbit` reads it, and it is never printed or logged. See [Rollout](/reference/executor#rollout) |
| `ORBIT_EXECUTOR_WAIT_MS` | The [executor wake budget](/reference/executor#the-wake-inside-the-request) in milliseconds, from admission through recording in-bound success. Default 500. The orchestrator sets Gateway Valkey to `hz 100` |
| `ORBIT_VISIT_WAKE_WAIT_SECONDS` | How long the Gateway holds a [visit to a sleeping container Instance](/reference/app-dev-runtime-hibernation#wake) while it wakes, in seconds. Default 30 |
| `ORBIT_EXECUTOR_WAKE_ENABLED` | Enable [executor container wakes](/reference/executor#the-wake-inside-the-request); default `false`. Signed power routing also selects the executor for wakes. Before turning it on, [`orbit:executor-parity`](/reference/executor#build-parity) must pass. |
| `ORBIT_EXECUTOR_POWER_ENABLED` | Enable [signed power and lifecycle orders](/reference/executor#signed-power-delivery); default `false`. Switch in the order of [Switching engines](/reference/executor#switching-engines). Before turning it on, [`orbit:executor-parity`](/reference/executor#build-parity) must pass. |
| `ORBIT_WEBSOCKET_REPOSITORY`, `ORBIT_WEBSOCKET_INSTALL_PATH`, `ORBIT_WEBSOCKET_PORT` | Defaults for the `websocket` Node role, with repository `https://github.com/hardimpactdev/orbit-reverb.git`. See [Realtime events with Reverb](/solutions/realtime-reverb). Each Gateway release pins a server commit for the [live registry](/reference/websocket-apps#the-reverb-server). |
| `TYPESAFE_API_KEY` | Key for the Jev classifier in [Tasks](/reference/tasks). |
| `ORBIT_TASKS_COPIES_MAX` | How many nested-fleet [task copies](/reference/tasks#task-copy) one Node holds at once; default 1, minimum 0. `task:start` counts at admission. A copy of any other Project does not count. |
| `ORBIT_TASKS_COPY_PUBLISH_PROJECTS` | A JSON list of Project ids whose [task copies](/reference/tasks#task-copy) may publish, besides nested-fleet Projects; default `[]`. Any other copy publish fails with `tasks.copy_publish_held`. Malformed JSON or an object holds every Project. |
| `ORBIT_TASKS_PR_MODE` | The [mode](/reference/tasks#task-group-modes) of a task copy's group whose group and Project set none: `review_before_pr` (default) or `pr_when_done`. Any other value fails with `tasks.pr_mode_setting_invalid`. |
| `ORBIT_TASKS_COPY_IMPLEMENTER_PROVIDER`, `ORBIT_TASKS_COPY_REVIEWER_PROVIDER` | The provider of a new implementer or reviewer thread in a [task copy](/reference/tasks#copy-turn-agents) when neither the task nor its Project names one; default `claude`. |
| `ORBIT_TASKS_COPY_IMPLEMENTER_MODEL`, `ORBIT_TASKS_COPY_REVIEWER_MODEL` | The same for the model; default `claude-opus-5-5`. A value that is no model name refuses each new copy thread that falls back to it with `tasks.agent_unsupported`. |
| `ORBIT_TASKS_COPY_IMPLEMENTER_EFFORT`, `ORBIT_TASKS_COPY_REVIEWER_EFFORT` | The same for the effort; defaults `medium` and `high`. A provider and effort that the copy's T3 has no instance for refuse the turn with `tasks.copy_turn_unoffered`. |
| `ORBIT_TASKS_PULL_REQUEST_AUTHORS` | Parsed Gateway configuration for repository/account IDs; Swamp owns merge decisions. |
| `ORBIT_TASKS_RESERVED_TIMEOUT_SECONDS` | Workspace claim lifetime for cleanup, in seconds; default 3600, minimum 60. |
| `ORBIT_TASKS_REVIEW_REQUEST_LOGINS` | GitHub logins requested as reviewers on a task pull request. See [Tasks](/reference/tasks). |

Other `ORBIT_` keys belong to one feature, and the page for that feature documents them.

[Release provenance](/reference/gateway-recovery#release-provenance) documents `ORBIT_CLI_RELEASE_TRUSTED_ROOT`, which replaces the committed Sigstore trust root only during a key-rotation recovery.

`ORBIT_AGENT_DELIVERY` selects the agent delivery manifest, and `ORBIT_AGENT_CONTRACT` selects its exported command contract. The [Node agent](/reference/node-agent#commands) page describes their release and development use. Keep the manifest, contract, and binary from the same build.

[Gateway disk retention](/reference/gateway-recovery#disk-retention) describes the installed host rules and Gateway record cleanup. The [gateway role convergence](/cli/node/role-add) installs the host retention files; releases leave them unchanged. The release run has fixed disk, snapshot and verification limits, as [Environment variables](/reference/environment-variables) describes. Record retention uses `ORBIT_FAILED_JOBS_HOURS`, `ORBIT_JOB_BATCH_HOURS`, and `ORBIT_ACTIVITY_LOG_DAYS`; their defaults are in `config/retention.php`.

The fleet-owned `instances.mailpit` configuration names the main Docker Node Process and SMTP port. It needs no Gateway environment key or contributor setting; [development mail](/reference/development-containers#development-mail) applies only on a Node with that active publication.

The fleet-owned `instances.fleet_projection` configuration names the folder where a Gateway in a release container reads the host's nested-fleet files; it defaults to `/opt/orbit-gateway-environment`. It needs no Gateway environment key. A Gateway on the host reads `/etc/orbit/instance-fleet` and the egress proxy in `/etc/environment` itself. A Gateway in a release container reads the copies `instance-fleet` and `instance-fleet-environment` in this read-only folder instead, and its units load the proxy settings from `instance-fleet-proxy.env` there. `orbit-gateway-environment` on the host writes both, as [Move the Gateway to release containers](/reference/gateway-recovery#move-the-gateway-to-release-containers) describes.

Containers need no environment opt-in or allow list. See [Container-only admission](/reference/development-containers#container-only-admission).

`ORBIT_INSTANCES_NESTED_FLEET_PROJECTS` is the operator-set allow list of nested-fleet Projects, read as `instances.nested_fleet_projects`: a JSON list of positive integer Project IDs, such as `[123]`. It defaults to `[]`, so no Project can enable `nested_fleet` by default. List only the Orbit Project, whose container holds an inner fleet, and disposable trial Projects.

A list that is not a JSON array, such as `{}` or `{"0":123}`, is malformed: enabling then refuses with `project.nested_fleet_projects_invalid`. See [Nested fleet setting](/reference/projects#nested-fleet-setting) for what the flag does. Removing an ID does not change its stored flag and does not prevent turning it off.

`ORBIT_INSTANCES_DEFAULT_DESTROY_PROJECTS` is the operator list of Projects whose container default [`instance:destroy`](/cli/instance/destroy) may remove, read as `instances.default_destroy_projects`: a JSON list of positive integer Project IDs. It defaults to `[]`. Only a default without a copy is removable, with or without an [inner volume](/reference/development-containers#the-inner-volume). The list is the only check that tells a trial default from the first nested-fleet default, so never list that Project. List only a disposable trial Project, and remove it after its default is gone. A malformed list refuses with `instance.default_destroy_projects_invalid`. Apply a change with [Apply an env change](/reference/gateway-recovery#apply-an-env-change).

`ORBIT_INSTANCE_REMOVAL_REAPER` turns the [removal reaper](/reference/instance-removal#the-removal-reaper) on or off, read as `instances.removal_reaper`. It defaults to on. When it is `false`, the scheduled pass that starts failed destroys again does nothing.

`ORBIT_INSTANCES_NESTED_FLEET_REFRESH` turns on the [refresh after a release](/reference/development-containers#refresh-after-a-release), read as `instances.nested_fleet_refresh`. It defaults to off; the live fleet has it on since 13 November 2026. While it is off, the release step prints one line and admits nothing. Turn it on only when the Club key lives off the default's disk and a safety snapshot of the default exists.

`ORBIT_INSTANCES_NESTED_DEFAULT_ROOT_SIZE` is the [root disk size of a nested-fleet default](/reference/development-containers#the-root-disk), read as `instances.nested_default_root_size`. It defaults to `80GiB`. Write `<n>GiB`, at least `40GiB`; any other value refuses with `instance.nested_root_size_invalid`. A default grows to it at its next start; a lowered value shrinks nothing. Apply a change with [Apply an env change](/reference/gateway-recovery#apply-an-env-change).

A Gateway release runs no smoke step. Remove the former `ORBIT_GATEWAY_RELEASE_SMOKE_TIMEOUT` and `ORBIT_GATEWAY_RELEASE_SMOKE_PROJECT` variables from the shared env file with [Apply an env change](/reference/gateway-recovery#apply-an-env-change). The Gateway does not read them.
