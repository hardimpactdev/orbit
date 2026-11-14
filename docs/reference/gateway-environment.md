---
title: "Gateway environment"
description: "Where the Gateway reads its own .env settings, how to apply a change, and what each key in the example file does."
covers:
  - apps/gateway/.env.example
  - apps/gateway/app/Domain/Nodes/GatewayRuntimeFiles.php
  - apps/gateway/app/Actions/Tasks/*SwampReaderCredentialAction.php
  - apps/gateway/app/Console/Commands/*SwampReaderCredentialCommand.php
  - apps/gateway/app/Console/Commands/InstallNestedFleetClubKeyCommand.php
  - apps/gateway/app/Domain/Tasks/SwampReaderCredential.php
  - apps/gateway/app/Domain/Tasks/SwampReaderAssistance.php
  - apps/gateway/app/Infrastructure/Tasks/SwampReaderEnvironment.php
---

# Gateway environment

The Gateway is a Laravel application, so it reads its own settings from a `.env` file. This page covers that file. [Instance environment variables](/reference/environment-variables) covers the `.env` files that the Gateway manages for Instances.

## Settings no code reads

A setting that no code reads any more leaves the file through the `gateway` role's convergence, never by hand. `GatewayRuntimeFiles::RetiredSettings` lists them, and the role's runtime phase removes each key from `shared/gateway.env`. Every release links that file as its `.env`. It now lists these settings:

- `ORBIT_TASK_VMS_UPCLOUD_TOKEN_FILE`
- `ORBIT_TASK_VMS_UPCLOUD_MAX_VMS`
- `ORBIT_TASK_VMS_UPCLOUD_PLAN`
- `ORBIT_TASK_VMS_UPCLOUD_DISK_GB`
- `ORBIT_SWAMP_DIRECT_WRITERS`

The four UpCloud task VM settings are unread since #3181 removed UpCloud. `ORBIT_SWAMP_DIRECT_WRITERS` chose a writer per Operation kind; every kind now has one writer.

## Services dependency detection

The orchestrator enables read-token requests for one Services peer during deployment.

| Variable | Meaning |
| --- | --- |
| `ORBIT_DEPENDENCY_DETECTION_SERVICES_NODE_ID` | Gateway-owned Services Node ID, exposed as `dependency_detection.services_node_id`. Unset or invalid disables the dependency read-token route. The route ships disabled until the orchestrator follows the activation steps below. |

Deploy Gateway first. Verify Services with `orbit node:show 17 --json`. On Gateway as `orbit`, back up `/home/orbit/shared/gateway.env` with mode `0600`, then set its single `ORBIT_DEPENDENCY_DETECTION_SERVICES_NODE_ID=17` entry without changing other settings. Run `php /home/orbit/orbit/apps/gateway/artisan gateway:release:configure`; never run `config:cache` in a release. From Services, verify `POST /api/v1/projects/20/dependency-read-token` succeeds without printing its token, then deploy Services. Follow the [release-safe env procedure](/reference/gateway-recovery#apply-an-env-change) and the exact backup/route-check commands in the [GitHub App](/reference/github-app#services-dependency-credentials) page. The orchestrator applies this setting during deployment; a merge does not enable it.

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
| `ORBIT_SWAMP_READ_TOKEN_NAME` | Installed token name, written by `gateway:swamp-reader:install`. |
| `ORBIT_SWAMP_READ_TOKEN_EXPIRES_AT` | Installed UTC expiry, written by the installer and checked hourly. |
| `ORBIT_SWAMP_CA_PATH` | Orbit root CA file. Default `<ORBIT_HOME>/ca/root.pem`, with `ORBIT_HOME` defaulting to `$HOME/.orbit`. |
| `ORBIT_SWAMP_PROOF_CLUB_API_KEY` | Dedicated club credential for disposable proof topologies. Keep its value in the Gateway environment. |
| `ORBIT_NESTED_FLEET_CLUB_API_KEY` | The Swamp Club key of nested fleets' Gateways, separate from the proof key. Written by `gateway:nested-fleet-club-key:install`; each run start reads this file. Empty: a nested-fleet container starts without it. |

Two provisions receive a names-only execution copy: Project 62 (`swamp-proof`), and the task group that a `proof:run` creates. Every other task, in the Orbit Project too, gets none.

Proof provision writes the credential to `/home/orbit/.config/swamp/club-api-key` inside the owned Task VM, mode `0600` for `orbit`, in a `0700` directory. Three pinned official `@swamp/ssh exec` argument lists run with sudo: `install -d` makes the directory, `rm -f` drops a previous file or link, and `install … /dev/stdin` writes the token. The native parent sends the token only on SSH stdin. Run history records that the step had stdin, never the value; neither the Operation input nor its result contains it. Each step has a 10-second dispatch budget and runs under `timeout 30`. A missing credential refuses proof placement. Normal task cancellation destroys the VM and its private filesystem.

### Install the nested-fleet Club key

`gateway:nested-fleet-club-key:install` writes `ORBIT_NESTED_FLEET_CLUB_API_KEY` from stdin. Run it on Gateway as `orbit`. It reads one token of at least 16 printable characters without spaces or quotes; one final newline is allowed. It holds the Gateway release lock and uses the same protected env writer as the reader installer: a private mode-`0600` backup, then one atomic rename that keeps every other setting. It never prints the value or takes it as an argument.

```bash
set +x
<the key on stdin> | php /home/orbit/orbit/apps/gateway/artisan gateway:nested-fleet-club-key:install
```

No `gateway:release:configure` and no worker reload is needed. The queue workers that start runs keep the configuration they loaded at boot, so each run start reads this one setting from the env file itself. The next start of each nested-fleet container delivers it ([Club key of the nested Gateway](/reference/development-containers#the-club-key-of-the-nested-gateway)). The backup holds the old env, so keep backups under the Gateway operator's retention policy.

### Rotate the Swamp reader credential

The Gateway operator owns rotation. Swamp requires admin authority to mint or revoke tokens. The Gateway keeps only workflow and data read grants, so it does not renew itself. A reader token lasts 30 days. While a reader is configured, Orbit checks the shared env file each hour and requests assistance in the `orbit` Project seven days before expiry. Missing name or invalid expiry metadata requests assistance at once. Repeated checks reuse the request for that credential. With Tasks disabled, the check returns a failure instead of silently skipping a due request.

`gateway:swamp-reader:install` is the protected env writer. Run this internal Artisan command on Gateway as `orbit`. It reads at most 16 KiB of official `swamp access token reveal --yes --json` output from stdin. Pass the replacement's `expiresAt` from the mint result with `--expires-at`. The command requires a new token name, a non-expired reveal, and an expiry more than seven days away. It never prints the token or passes it in command arguments.

1. On the Swamp server, mint a replacement under a new name.

   Run as the service account in the Swamp repository. Keep the existing `user:orbit-gateway-read` principal with only `read` grants on `workflow:*` and `data:*`.

   ```bash
   replacement="orbit-gateway-read-$(date -u +%Y%m%dT%H%M%SZ)"
   swamp access token mint "$replacement" \
     --principal user:orbit-gateway-read --duration 30d --json
   ```

2. On Gateway, pull the reveal over its managed SSH connection to Swamp.

   Disable shell tracing. Set `replacement` to the minted name, `expires_at` to the exact non-secret expiry in the mint result, and `swamp_host` to the Swamp Node's WireGuard address. Use Gateway's managed key and pinned host identity. The example uses the installed Swamp repository path; run against the repository that serves Swamp. Do not save reveal output to a file or a shell variable.

   ```bash
   set +x
   set -o pipefail
   ssh -i /home/orbit/.orbit/ssh/id_ed25519 \
     -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes \
     -o UserKnownHostsFile=/home/orbit/.orbit/ssh/known_hosts \
     "orbit@$swamp_host" /usr/local/bin/swamp \
       access token reveal "$replacement" \
       --repo-dir /home/orbit/apps/orbit-swamp/default --yes --json | \
     php /home/orbit/orbit/apps/gateway/artisan \
       gateway:swamp-reader:install --expires-at="$expires_at"
   ```

   The command holds the Gateway release lock, verifies a fresh workflow-history read with the candidate credential, and creates a private mode-`0600` backup before changing the env file. It preserves other settings and replaces the token, its name, and its expiry in one atomic rename. Duplicate definitions, invalid env input, and unsafe files refuse before mutation.

   After the verified replacement is installed, the command clears the previous credential's own rotation assistance. A failed live read or env write leaves that assistance open. A newer operator direction or unrelated assistance stays unchanged. Successful output contains only the token name, expiry, and backup path.

3. Apply the env change and verify the serving Gateway.

   ```bash
   php /home/orbit/orbit/apps/gateway/artisan gateway:release:configure
   ```

   Read a known group's `/api/v1/tasks/{task}/swamp-runs` endpoint and require `availability: available` with a fresh `observed_at`. HTTP 200 alone does not prove an upstream read. The installer verifies the candidate before publication; it does not refresh the serving configuration or revoke credentials.

4. Revoke the old token only after that read succeeds.

   On Swamp, run `swamp access token revoke <old-name>`. The installer clears the old rotation assistance. Complete its task after serving verification and revocation. If configuration application or serving verification fails, keep the old token valid, restore the protected backup, apply configuration again, and revoke the unused replacement.

`ORBIT_SWAMP_READ_TOKEN_NAME` and `ORBIT_SWAMP_READ_TOKEN_EXPIRES_AT` record the installed credential.

For an existing installation without these fields, the first hourly check requests assistance. When the name and expiry are already known, back up the env file privately, then add or replace only these two metadata keys in a private editor. Leave `ORBIT_SWAMP_READ_TOKEN` unchanged. Apply configuration with `gateway:release:configure`, then run `gateway:swamp-reader:check`. This records metadata without revealing the token. If assistance was already raised, resolve that request after a fresh serving read confirms the existing credential.

Run `gateway:swamp-reader:check` to check now. A due request stays visible after expiry; a token with no reader configured is skipped. Missing the `orbit` Project or disabling Tasks makes a due check fail visibly, and the failure names its error code or class. A release holds the release lock for its whole run. A check that meets that lock ends without error and reads the token again at the next hourly run. The request is an operator action and starts no agent or workflow.

Minting under a new name keeps the old credential valid until the replacement works. `swamp access token rotate <name>` revokes and replaces that name immediately, so it is not used for this handoff. Keep private backups under the Gateway operator's retention policy.

Swamp stores the authoritative secret encrypted in its control-plane vault. `reveal` emits plaintext on stdout inside the private pipe. The writer's temporary file, the installed env file, protected backups, and Laravel's configuration cache contain plaintext and require mode `0600`. The [Swamp access commands](https://swamp-club.com/manual/reference/swamp-serve/access-commands) define minting, reveal, and revocation.

#### Why rotation needs an operator

Automatic minting would give the Gateway admin authority over Swamp access. The reader stays read-only. Orbit supplies a tested installer and a scheduled assistance request so renewal has an executable path and a visible deadline.

## Where the file lives

The file's path depends on how the Gateway runs.

| Gateway | File |
| --- | --- |
| Installed Gateway | `/home/orbit/shared/gateway.env`. Every release links to it. |
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
| `ORBIT_HIBERNATION_IDLE_SECONDS`, `ORBIT_HIBERNATION_SWEEP_SECONDS`, `ORBIT_HIBERNATION_WAKE_TIMEOUT_SECONDS` | Development runtime hibernation timing. See [App-dev runtime hibernation](/reference/app-dev-runtime-hibernation). |
| `ORBIT_HIBERNATION_CONTAINER_SLEEP`, `ORBIT_HIBERNATION_CONTAINER_NEVER_SLEEP` | The idle sleep of development containers, off by default, and the Instance ids it never puts to sleep. See [Sleep and wake](/reference/development-containers#sleep-and-wake). |
| `PHP_CLI_SERVER_WORKERS` | Number of workers for `php artisan serve` in local development. |
| `BCRYPT_ROUNDS` | Laravel password hashing cost. |
| `LOG_CHANNEL`, `LOG_STACK`, `LOG_DAILY_DAYS`, `LOG_DEPRECATIONS_CHANNEL`, `LOG_LEVEL` | Laravel logging. [Gateway request logs](/reference/gateway-recovery#gateway-request-logs) describes the files. |
| `DB_CONNECTION` | Laravel database connection. The Gateway uses SQLite. |
| `DB_DATABASE` | Path of the SQLite database. Empty means `ORBIT_HOME/gateway.sqlite`. A bootstrapped Gateway names that path, because the waits and the start of a nested fleet read it here. |
| `SESSION_DRIVER`, `SESSION_LIFETIME`, `SESSION_ENCRYPT`, `SESSION_PATH`, `SESSION_DOMAIN` | Laravel sessions. The API is stateless, so the example uses the `array` driver. |
| `FILESYSTEM_DISK`, `QUEUE_CONNECTION`, `CACHE_STORE` | Laravel storage, queue, and cache drivers. |
| `ORBIT_GATEWAY_QUEUE_CONNECTION`, `ORBIT_GATEWAY_QUEUE_REDIS_DATABASE` | The Redis Database connection and database index of the [Gateway queue](/reference/gateway-queue), which Laravel Horizon works. Empty, the scheduler runs its commands itself. Tests set `ORBIT_GATEWAY_QUEUE_DRIVER=database` to keep the queue in SQLite. |
| `ORBIT_WEBSOCKET_REPOSITORY`, `ORBIT_WEBSOCKET_INSTALL_PATH`, `ORBIT_WEBSOCKET_PORT` | Defaults for the `websocket` Node role, with repository `https://github.com/hardimpactdev/orbit-reverb.git`. See [Realtime events with Reverb](/solutions/realtime-reverb). Each Gateway release pins a server commit for the [live registry](/reference/websocket-apps#the-reverb-server). |
| `TYPESAFE_API_KEY` | Key for the Jev classifier in [Tasks](/reference/tasks). |
| `ORBIT_TASKS_COPIES_MAX` | How many nested-fleet [task copies](/reference/tasks#task-copy) one Node holds at once; default 1, minimum 0. `task:start` counts at admission. A copy of any other Project does not count. |
| `ORBIT_TASKS_COPY_PUBLISH_PROJECTS` | A JSON list of Project ids whose [task copies](/reference/tasks#task-copy) may publish, besides nested-fleet trial Projects; default `[]`. Any other copy publish fails with `tasks.copy_publish_held`. Malformed JSON or an object holds every Project. |
| `ORBIT_TASKS_COPY_TURN_MODEL` | The T3 provider and model of a [turn in a task copy](/cli/task/turn#a-task-that-runs-in-a-copy), as `PROVIDER/MODEL`; default `claudeAgent/claude-opus-5-5`. A value of another form refuses each copy turn with `tasks.copy_turn_model_invalid`. |
| `ORBIT_TASKS_PULL_REQUEST_AUTHORS` | Parsed Gateway configuration for repository/account IDs; Swamp owns merge decisions. |
| `ORBIT_TASKS_RESERVED_TIMEOUT_SECONDS` | Workspace claim lifetime for cleanup, in seconds; default 3600, minimum 60. |
| `ORBIT_TASKS_REVIEW_REQUEST_LOGINS` | GitHub logins requested as reviewers on a task pull request. See [Tasks](/reference/tasks). |

Other `ORBIT_` keys belong to one feature, and the page for that feature documents them.

[Release provenance](/reference/gateway-recovery#release-provenance) documents `ORBIT_CLI_RELEASE_TRUSTED_ROOT`, which replaces the committed Sigstore trust root only during a key-rotation recovery.

`ORBIT_AGENT_DELIVERY` selects the agent delivery manifest, and `ORBIT_AGENT_CONTRACT` selects its exported command contract. The [Node agent](/reference/node-agent#commands) page describes their release and development use. Keep the manifest, contract, and binary from the same build.

[Gateway disk retention](/reference/gateway-recovery#disk-retention) describes the installed host rules and Gateway record cleanup. The [gateway role convergence](/cli/node/role-add) installs the host retention files; releases leave them unchanged. The release run has fixed disk, snapshot and verification limits, as [Environment variables](/reference/environment-variables) describes. Record retention uses `ORBIT_FAILED_JOBS_HOURS`, `ORBIT_JOB_BATCH_HOURS`, and `ORBIT_ACTIVITY_LOG_DAYS`; their defaults are in `config/retention.php`.

`ORBIT_TASK_VMS_SWAMP_HOST` names the one private HTTPS site task VM workers may reach, such as `swamp.orbit`. Set it for the managed Swamp Route on the `swamp` Node. After applying the environment change, rebuild Caddy on that Node; Doctor reports `role.caddy_build_drift` until then. See [Refuse task VMs in Caddy](/reference/compute-drivers#refuse-task-vms-in-caddy).

Task VMs run only on Incus. Provider API credentials are not part of Gateway configuration. Task publication deletes the VM; no environment setting delays release for review. The operator sets the separate task and CI pool sizes on each host Node.

`ORBIT_TASK_VMS_CONTAINER_PLACEMENT` lets normal placement take the task container places of hosts. It is `false` by default, and nothing turns it on: tasks move into [Instance containers](/reference/development-containers#tasks-in-an-instance-container), and [the plan](/solutions/development-container-runtime#pr-17-remove-task-vms-the-pi-server-and-the-task-container-setting) removes this setting. See [Configure task VMs](/reference/compute-drivers#configure-task-vms).

The fleet-owned `instances.mailpit` configuration names the main Docker Node Process and SMTP port. It needs no Gateway environment key or contributor setting; [development mail](/reference/development-containers#development-mail) applies only on a Node with that active publication.

`ORBIT_INSTANCES_CONTAINER_NODES` is the build list of [Instance containers](/reference/development-containers#the-build-list): a JSON list of the Node ids that run container Instances. It is empty by default.

`ORBIT_INSTANCES_NESTED_FLEET_PROJECTS` is the operator-set trial allow list, read as `instances.nested_fleet_projects`: a JSON list of positive integer Project IDs, such as `[123]`. It defaults to `[]`, so no Project can enable `nested_fleet` by default. Only list disposable trial Projects; do not enable the flag on a real Project.

A list that is not a JSON array, such as `{}` or `{"0":123}`, is malformed: enabling then refuses with `project.nested_fleet_projects_invalid`. See [Nested fleet setting](/reference/projects#nested-fleet-setting) for what the flag does. Removing an ID does not change its stored flag and does not prevent turning it off.

`ORBIT_INSTANCES_NESTED_FLEET_REFRESH` turns on the [refresh after a release](/reference/development-containers#refresh-after-a-release), read as `instances.nested_fleet_refresh`. It defaults to off; the live fleet has it on since 13 November 2026. While it is off, the release step prints one line and admits nothing. Turn it on only when the Club key lives off the default's disk and a safety snapshot of the default exists.

Task VM hosts use [Node `settings.task_vms`](/reference/node-settings#configure-a-task-vm-host), not an environment list. Configure and verify each host's stored settings before removing the former `ORBIT_TASK_VMS_INCUS_HOSTS` variable with [Apply an env change](/reference/gateway-recovery#apply-an-env-change). The Gateway does not read the environment list, including when configuration is cached.

A Gateway release runs no smoke step. Remove the former `ORBIT_GATEWAY_RELEASE_SMOKE_TIMEOUT` and `ORBIT_GATEWAY_RELEASE_SMOKE_PROJECT` variables from the shared env file with [Apply an env change](/reference/gateway-recovery#apply-an-env-change). The Gateway does not read them.
