---
title: "Gateway environment"
description: "Where the Gateway reads its own .env settings, how to apply a change, and what each key in the example file does."
covers:
  - apps/gateway/.env.example
  - bin/env-docs
  - apps/gateway/app/Actions/Tasks/*SwampReaderCredentialAction.php
  - apps/gateway/app/Console/Commands/*SwampReaderCredentialCommand.php
  - apps/gateway/app/Domain/Tasks/SwampReaderCredential.php
  - apps/gateway/app/Domain/Tasks/SwampReaderAssistance.php
  - apps/gateway/app/Infrastructure/Tasks/SwampReaderEnvironment.php
---

# Gateway environment

The Gateway is a Laravel application, so it reads its own settings from a `.env` file. This page covers that file. [Instance environment variables](/reference/environment-variables) covers the `.env` files that the Gateway manages for Instances.

## Swamp task output

The Gateway reads [Swamp run output](/reference/tasks#swamp-run-output) with these optional deployment settings. Without a reader token, the task panel shows unavailable.

| Variable | Meaning |
| --- | --- |
| `ORBIT_SWAMP_READ_TOKEN` | Dedicated Swamp token with workflow and output-data read grants only. Store it in the Gateway environment; never expose it to the browser. |
| `ORBIT_SWAMP_ADDRESS` | Swamp server address, default `swamp.orbit`, on port 443. Set its WireGuard address when Gateway DNS cannot resolve that name. TLS always verifies `swamp.orbit`. |
| `ORBIT_SWAMP_READ_TOKEN_NAME` | Installed token name, written by `gateway:swamp-reader:install`. |
| `ORBIT_SWAMP_READ_TOKEN_EXPIRES_AT` | Installed UTC expiry, written by the installer and checked hourly. |
| `ORBIT_SWAMP_CA_PATH` | Orbit root CA file. Default `<ORBIT_HOME>/ca/root.pem`, with `ORBIT_HOME` defaulting to `$HOME/.orbit`. |

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
     "orbit@$swamp_host" /home/orbit/.local/bin/swamp \
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

   Read a known group's `/api/v1/task-groups/{group}/swamp-runs` endpoint and require `availability: available` with a fresh `observed_at`. HTTP 200 alone does not prove an upstream read. The installer verifies the candidate before publication; it does not refresh the serving configuration or revoke credentials.

4. Revoke the old token only after that read succeeds.

   On Swamp, run `swamp access token revoke <old-name>`. The installer clears the old rotation assistance. Complete its task after serving verification and revocation. If configuration application or serving verification fails, keep the old token valid, restore the protected backup, apply configuration again, and revoke the unused replacement.

`ORBIT_SWAMP_READ_TOKEN_NAME` and `ORBIT_SWAMP_READ_TOKEN_EXPIRES_AT` record the installed credential.

For an existing installation without these fields, the first hourly check requests assistance. When the name and expiry are already known, back up the env file privately, then add or replace only these two metadata keys in a private editor. Leave `ORBIT_SWAMP_READ_TOKEN` unchanged. Apply configuration with `gateway:release:configure`, then run `gateway:swamp-reader:check`. This records metadata without revealing the token. If assistance was already raised, resolve that request after a fresh serving read confirms the existing credential.

Run `gateway:swamp-reader:check` to check now. A due request stays visible after expiry; a token with no reader configured is skipped. Missing the `orbit` Project or disabling Tasks makes a due check fail visibly. The request is an operator action and starts no agent or workflow.

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
| `PHP_CLI_SERVER_WORKERS` | Number of workers for `php artisan serve` in local development. |
| `BCRYPT_ROUNDS` | Laravel password hashing cost. |
| `LOG_CHANNEL`, `LOG_STACK`, `LOG_DAILY_DAYS`, `LOG_DEPRECATIONS_CHANNEL`, `LOG_LEVEL` | Laravel logging. [Gateway request logs](/reference/gateway-recovery#gateway-request-logs) describes the files. |
| `DB_CONNECTION` | Laravel database connection. The Gateway uses SQLite. |
| `SESSION_DRIVER`, `SESSION_LIFETIME`, `SESSION_ENCRYPT`, `SESSION_PATH`, `SESSION_DOMAIN` | Laravel sessions. The API is stateless, so the example uses the `array` driver. |
| `FILESYSTEM_DISK`, `QUEUE_CONNECTION`, `CACHE_STORE` | Laravel storage, queue, and cache drivers. |
| `ORBIT_GATEWAY_QUEUE_CONNECTION`, `ORBIT_GATEWAY_QUEUE_REDIS_DATABASE` | The Redis Database connection and database index of the [Gateway queue](/reference/gateway-queue), which Laravel Horizon works. Empty, the scheduler runs its commands itself. Tests set `ORBIT_GATEWAY_QUEUE_DRIVER=database` to keep the queue in SQLite. |
| `ORBIT_WEBSOCKET_REPOSITORY`, `ORBIT_WEBSOCKET_INSTALL_PATH`, `ORBIT_WEBSOCKET_PORT` | Defaults for the `websocket` Node role, with repository `https://github.com/hardimpactdev/orbit-reverb.git`. See [Realtime events with Reverb](/solutions/realtime-reverb). Each Gateway release pins a server commit for the [live registry](/reference/websocket-apps#the-reverb-server). |
| `TYPESAFE_API_KEY` | Key for the Jev classifier in [Tasks](/reference/tasks). |
| `ORBIT_TASKS_REVIEW_REQUEST_LOGINS` | GitHub logins requested as reviewers on a task pull request. See [Tasks](/reference/tasks). |

Other `ORBIT_` keys belong to one feature, and the page for that feature documents them.

[Release provenance](/reference/gateway-recovery#release-provenance) documents `ORBIT_CLI_RELEASE_TRUSTED_ROOT`, which replaces the committed Sigstore trust root only during a key-rotation recovery.

`ORBIT_AGENT_DELIVERY` selects the agent delivery manifest, and `ORBIT_AGENT_CONTRACT` selects its exported command contract. The [Node agent](/reference/node-agent#commands) page describes their release and development use. Keep the manifest, contract, and binary from the same build.

[Gateway disk retention](/reference/gateway-recovery#disk-retention) documents `ORBIT_JOURNAL_MAX_MB`, `ORBIT_JOURNAL_KEEP_FREE_MB`, `ORBIT_JOURNAL_RUNTIME_MAX_MB`, `ORBIT_JOURNAL_DAYS`, `ORBIT_LOG_ROTATE_MB`, and `ORBIT_LOG_ROTATE_KEEP`. Queue record retention uses `ORBIT_FAILED_JOBS_HOURS` and `ORBIT_JOB_BATCH_HOURS`. Their defaults are in `config/retention.php`.

`ORBIT_TASK_VMS_SWAMP_HOST` names the one private HTTPS site task VM workers may reach, such as `swamp.orbit`. Set it for the managed Swamp Route on the `websocket` Node. After applying the environment change, rebuild Caddy on that Node; Doctor reports `role.caddy_build_drift` until then. See [Refuse task VMs in Caddy](/reference/compute-drivers#refuse-task-vms-in-caddy).

Task VM hosts use [Node `settings.task_vms`](/reference/node-settings#configure-a-task-vm-host), not an environment list. Configure and verify each host's stored settings before removing the former `ORBIT_TASK_VMS_INCUS_HOSTS` variable with [Apply an env change](/reference/gateway-recovery#apply-an-env-change). The Gateway does not read the environment list, including when configuration is cached.

## Drift check

`bin/env-docs` runs in CI. It fails when:

- Gateway, CLI, or PHP SDK code reads an `ORBIT_` key that no docs page mentions.
- `apps/gateway/.env.example` lists a key that this page does not mention.
- `apps/gateway/.env.example` lists a key that neither Gateway code nor Laravel reads.

Gateway code includes each extension under `apps/gateway/extensions`, such as the documents extension's `config/documents.php`.

The check finds keys read through `env()`, including `env(key: ...)`, and through `getenv()`, `Env::get()`, `$_SERVER`, and `$_ENV`. ADRs do not count as documentation. Keys without the `ORBIT_` prefix belong to Laravel, PHP, or the operating system, and their own docs cover them.

The shared task VM [package cache](/reference/compute-drivers#shared-package-cache) reads its byte budget from `config/package_cache.php` (`package_cache.max_bytes`, 20 GiB by default). Its host list comes only through `TaskVmSettings`; the package cache has no environment host list.
