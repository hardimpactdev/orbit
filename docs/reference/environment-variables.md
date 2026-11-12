---
title: "Instance environment variables"
description: "How the Gateway imports, stores, updates, and synchronizes an Instance .env file without exposing its values."
covers:
  - apps/gateway/app/Domain/Instances/Environment/**
  - apps/gateway/app/Actions/*/{Start,Update,Synchronize,Refresh}*Environment*Action.php
  - apps/gateway/app/Http/Controllers/Api/InstanceEnvironment*Controller.php
  - apps/gateway/app/Infrastructure/Instances/{RemoteInstanceEnvironmentAccess,RemoteInstanceEnvironmentRuntimeRefresher,NativeInstanceEnvironmentOperationLock}.php
  - apps/gateway/{app/Models/{Instance,InstanceEnvironmentValue}.php,database/migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php}
  - apps/cli/app/Commands/Environment/**
  - packages/php-sdk/src/{Requests,Responses}/Environment/**
  - swamp/{workflows/workflow-operation-instance-env-import.yaml,extensions/behaviors/operations/instance-env-import/**}
---

# Instance environment variables

The Gateway owns the environment configuration of every Instance. It stores each key and value encrypted, and it writes the Instance's `.env` file only when you synchronize. A value never appears in a response, an Activity entry, an error, or a log. [`env`](/cli/env) lists the commands.

[Node service target records](/reference/processes-and-schedules#node-service-target-records) reserve explicit host and port key names per Instance. Recording or removing a target leaves both stored environment values and the `.env` file unchanged.

`ORBIT_DOCUMENT_CLEANUP_RUNTIME` selects the private local directory for isolated Project Document cleanup-gate fixtures when `APP_ENV=testing`; other environments ignore this override. Its default is `/run/orbit/project-documents/`. Never point it into `ORBIT_HOME`, a checkout, a web directory, or a backup. Installed Gateway services use the default runtime directory. This setting is not an Instance environment value or deletion authorization. See [the restore-time cleanup gate](/reference/project-documents#restore-time-cleanup-gate).

`ORBIT_TASK_VMS_ENABLED`, `ORBIT_TASK_VMS_DEV_CLUSTER_ID`, `ORBIT_TASK_VMS_WIREGUARD_RANGE`, `ORBIT_TASK_VMS_PI_ARTIFACT_PATH`, `ORBIT_TASK_VMS_PI_ARTIFACT_SHA256`, `ORBIT_TASK_VMS_PI_MODELS`, `ORBIT_TASK_VMS_MEDIUM_PROJECTS`, `ORBIT_TASK_VMS_LARGE_PROJECTS` configure [task VMs](/reference/compute-drivers#configure-task-vms) in the Gateway's own environment. They are not Instance keys. Task VMs are off by default, the reserved WireGuard range defaults to `10.44.0.128/25`, and the other values are unset. `ORBIT_TASK_VMS_PI_MODELS`, `ORBIT_TASK_VMS_MEDIUM_PROJECTS`, and `ORBIT_TASK_VMS_LARGE_PROJECTS` are JSON lists. For groups without a declared topology, `large_projects` takes precedence over `medium_projects`. The Gateway validates these values once, when it first uses them.

Incus hosts are configured through [Node `settings.task_vms`](/reference/node-settings#configure-a-task-vm-host), not an environment list or the `app-dev` role. `ORBIT_TASK_VMS_INCUS_HOSTS` is read only by the [deployment migration](/reference/node-settings#migrate-the-environment-host-list). Keep its current JSON list until migration copies it into the existing host Nodes, verify their settings, and then remove the variable.


`ORBIT_PROOF_RUN_EXECUTION` switches [proof execution](/cli/proof/run#the-execution-switch) on or off in the Gateway's own environment (`proof.execution`). It is not an Instance key. It is `true` by default. Set it to `false` and `orbit proof:run` checks the commit, selects the cases and refuses with `proof.execution_disabled`.

`ORBIT_CHECK_PROCESSES` is an internal lane or suite share passed by `bin/review-check` and `bin/test`. The shared helper derives the total budget from available CPU and memory; it is not an operator or Project setting.

The Gateway's own environment is separate from an Instance's stored configuration. Set `ORBIT_TASKS_IMPLEMENTER_EFFORT` and `ORBIT_TASKS_REVIEWER_EFFORT` in the Gateway's `.env`, not the task workspace's `.env`. See [Tasks configuration](/reference/tasks#configuration) for their defaults and when changes apply.

`APP_VERSION` in the Gateway's own `.env` sets the version that `gateway:status` reports. When unset or empty, the Gateway reports the commit in its release's `REVISION` file, or `dev` outside a release. Leave it unset in the [release layout](/reference/gateway-recovery#release-layout), so every release reports its own commit. `.env.example` does not set it. It is not an Instance key.

`ORBIT_GATEWAY_CHECKOUT` is the Gateway application directory. It defaults to `/home/orbit/orbit/apps/gateway`, which is a link to the current release in the release layout.

`ORBIT_GATEWAY_RELEASES_KEEP` sets how many recent sealed releases the [release run](/reference/gateway-recovery#release-as-a-swamp-run) keeps. It defaults to `3` and has a minimum of `2`. The current and previous releases and the build served by `web/current` also stay. The run needs 1 GiB free beyond the database size and keeps the newest three [database snapshots](/reference/gateway-recovery#migrations-and-the-snapshot), plus the one a pause names. These limits are fixed in the workflow.

The release run verifies `/up` and Gateway status at `https://gateway.orbit`, using the Orbit root CA at `/etc/caddy/orbit-cert-current/root-ca.pem`. The verification origin is fixed in the workflow.

`ORBIT_GATEWAY_QUEUE_CONNECTION` is the slug of the Redis [Database connection](/reference/database-connections) of the [Gateway queue](/reference/gateway-queue), such as `gateway-queue`. Empty, the default, keeps the scheduler running its commands itself and the Gateway creates no `horizon` Process. `ORBIT_GATEWAY_QUEUE_REDIS_DATABASE` is the Redis database index of the queue and Horizon, `1` by default. Neither is an Instance key.

`ORBIT_GATEWAY_RELEASE_BRANCH` and `ORBIT_GATEWAY_RELEASE_CHECK` name the branch and the check run that [automatic releases](/reference/gateway-recovery#automatic-releases) follow. They default to `main` and `Required checks`. The repository is the `origin` of the shared release repository. They are settings in the Gateway's own environment, not Instance keys.

`ORBIT_CLI_RELEASE_REPOSITORY` is a setting in the Gateway's own environment, not an Instance key. It names the repository whose CLI releases make up the [desired fleet state](/reference/self-update#how-the-gateway-resolves-it). The default is `https://github.com/hardimpactdev/orbit`. `ORBIT_CLI_RELEASE_TRUSTED_ROOT` names another Sigstore trust root for [release provenance](/reference/gateway-recovery#release-provenance); set it only to recover from a key rotation. On the machine that runs `orbit self-update`, `ORBIT_SELF_UPDATE_RELEASES` replaces the release download location, `https://github.com/hardimpactdev/orbit/releases/download`, for a mirror you trust. The Gateway never sets it.

`ORBIT_FLEET_ROLLOUT` and `ORBIT_FLEET_ROLLOUT_ORDER` are settings in the Gateway's own environment, not Instance keys. They [turn the fleet rollout on](/reference/gateway-recovery#turn-it-on) and name the Nodes it visits first. The rollout is off by default. `ORBIT_FLEET_REBOOT` turns the [fleet reboot](/reference/gateway-recovery#fleet-reboot) off when set to `false`; it is on by default. `ORBIT_CLI_RELEASE_STATIC_MANIFEST` is for tests only: it replaces Git history and GitHub with a local CLI release manifest on a disposable topology. Never set it on a real Gateway.

`ORBIT_RELEASE_ALERT_WEBHOOK_URL` and `ORBIT_RELEASE_ALERT_WEBHOOK_SECRET` are settings in the Gateway's own environment too, not Instance keys. See [Release alerts](/reference/gateway-recovery#webhook). The Gateway never returns the secret.

Reviewer trust is not an Instance environment setting. The Gateway operator sets `ORBIT_TASKS_GITHUB_REVIEWERS` in the Gateway's `.env`. A task workspace's `.env` or branch cannot grant [GitHub feedback authority](/reference/tasks#trusted-github-feedback).

The logins requested as reviewers on a published task pull request are also Gateway configuration. The operator sets `ORBIT_TASKS_REVIEW_REQUEST_LOGINS` in the Gateway's `.env`. Unset or empty requests no one. See [Tasks configuration](/reference/tasks#configuration).

The Incus harness also reads its own environment. `ORBIT_E2E_INCUS_MEMORY` overrides the memory limit for every guest it creates, for example `2GiB`. When unset, the harness uses the [per-Node defaults](/reference/incus-topologies#capacity). It does not read this setting from an Instance's `.env`. No setting chooses the guests' pool or root disk size: every guest is a copy of the task VM's `node-template` in the pool `topology-seed`.

`ORBIT_GATEWAY_URL` and `ORBIT_CA_PATH` are web development proxy inputs, not Instance settings. A [topology web session](/reference/web-app#run-against-a-topology) pins the URL and trusted CA to its selected topology. Inherited endpoint overrides, including settings in the caller's environment or web development files, must not redirect Gateway, realtime, or metrics traffic to the live fleet. Missing required topology configuration fails startup instead of reading a live profile or another user's credentials.

`ORBIT_DOCS_ROOT` overrides the Docs tooling's documentation directory, normally repository-root `docs/`. `bin/docs-merge-check` sets it to the temporary checkout's `docs/` directory so all lint rules inspect the selected tree. This local tooling setting is not an Instance environment value. See [the contributor checks](/contributor-guide#checks-that-need-no-network).

## Operations

The import and update endpoints accept either a positive numeric Instance ID or an exact Route domain in `{instance}`. A selector that matches no Instance returns HTTP 404. A Route domain that has multiple Instance targets returns HTTP 409 with `env.target_ambiguous`. The Instance's recorded placement owns its environment: the owning Node is the Instance's `node_id`, not a Route. An Instance without a Route can still have its environment synchronized. Changing the Project's [task workspace routing setting](/reference/projects#task-workspace-routing) does not change an existing Instance's environment target.

| Operation | Request | Body | Effect |
| --- | --- | --- | --- |
| Import | `POST /api/v1/instances/{instance}/environment/import` | `{}` or `{"replace": true}` | Reads the Instance's `.env` into stored configuration. |
| Update | `PUT /api/v1/instances/{instance}/environment/{key}` | `{"value": "..."}` | Adds or replaces one stored value. |
| Synchronize | `POST /api/v1/instances/{instance}/environment/sync` | `{}` | Replaces the Instance's `.env` with the complete stored configuration. |

Import and update change stored configuration only. The `.env` file on the Node stays the same until you synchronize. The Gateway refuses unknown, duplicate, or wrongly typed members with 422 before it changes anything.

Update returns `instance_id`, `operation`, `changed`, and `key_count`. Import and synchronization return HTTP 202 with their native Operation. The final import result has the same four fields. The final result includes `instance_id`, `changed`, `environment_changed`, `key_count`, the synchronized key names, testing status and runtime refresh status.

The Instance must be active and have complete placement. The Node must have exactly one active `app-dev` or `app-prod` role; without one, the Gateway returns `instance.placement_unavailable` (409).

Import, update, and synchronization use the Instance's recorded owning Node. A Route is not required for synchronization unless a stored value refers to `{{instance.domain}}`; then the Gateway needs an authoritative Route and returns `env.reference_unavailable` (409) if it cannot resolve one. A Route in an incomplete transition also blocks synchronization. The caller needs an access grant to the Instance's Node. Import and synchronize also need an active Node. Update does not contact the Node, so it works while the Node is unreachable.

## Native environment synchronization

`instance.sync_environment` records one Instance intent and starts one Swamp run on Node 209 ([flow](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/env/sync.mmd)). At admission the Gateway renders the files and records the key names and the sha256 of each file, never a value.

Admission also checks the file on the Node from the [Node agent's](/reference/node-agent#environment-key-names) `env_keys` fact; no run reads the Node. A key that stored configuration lacks and Orbit never wrote refuses with `env.sync_would_drop_keys`, and the details name the keys. A file whose key names the agent has not observed refuses with `env.keys_not_observed`: a 0600 `.env`, a link, a missing file, no report yet, or a silent agent. An agent that can't reach the directory also sees no file, so a missing file proves nothing.

Both refusals are HTTP 409 and start no task. `--force` (`"force": true`) skips both checks and replaces the file. When the agent reports that Git tracks `.env.testing`, the plan writes no `.env.testing`, and the result says `skipped_tracked` when one would have been written. A forced sync without the agent's report writes none either and says `skipped_unobserved`.

When the run starts, it pushes `.env` into the vault key `instance-environment-<operation>` and `.env.testing` into `instance-environment-testing-<operation>`. The steps reach the owning Node from Node 209. The official `@swamp/cfgmgmt/group` behavior creates the `orbit-env` group when the Node lacks it. The official `@swamp/cfgmgmt/file` behavior writes each file from `vault.get` as the Instance account, with mode 0640. Then root runs `chgrp -h orbit-env` on them, which never follows a link. The agent reads only `.env`. An assert compares the sha256 it records with the rendered one. The file behavior's status of each file, `compliant` or `applied`, gives `environment_changed` and `testing_status`. No step calls the Gateway, and no per-Node worker takes part.

The Gateway records the result and the admitted key names from the terminal run. If the stored values change between admission and run start, the run does not start and the task fails with `env.configuration_changed`.

The admission freezes the environment, account and application path from stored placement. Development uses the configured Node account. Production uses the Instance production account. Caller input cannot choose an account or a Node. The file steps run as that account through `sudo -u`; the production runtime refresh runs as root. There is no Gateway child, agent relay, receipt protocol or polled writer.

The foreign-key check refuses a file with keys that neither stored configuration nor Orbit's previous owned-key set includes. A missing file is allowed. The raw file limit remains 1 MiB. Production synchronization refreshes the config cache, PHP-FPM and opted-in Processes after the file writes, in that order. Swamp records each refresh action separately. A failed action fails the Operation with `env.runtime_refresh_failed`; the written files remain in place. Development synchronization only writes the files.

## Parent environment steps

Create seeds encrypted values from its bounded source template before the native environment step. For a Laravel Instance with a Route, create stores `APP_URL` as `https://{{instance.domain}}`, so `.env` names the Instance's own domain, not the template's URL. When create owns a database (a copy of the default one, or one on `--database-server`), its `DB_*` keys replace the template's database keys in that seed. An owned SQL test database also gets `.env.testing`, rendered as `env:sync` renders it.

When creating a development Instance, Orbit writes a captured live whole-value `${KEY}` reference, such as `VITE_APP_NAME="${APP_NAME}"`, unescaped in the new Instance's `.env`. Capture recognizes unquoted and double-quoted references to any key; ordinary template entries keep their stored shape. Orbit resolves nothing: the application's dotenv expands the reference. Mixed values such as `"${APP_URL}/x"`, escaped and single-quoted references, and entries without provenance keep the previous escaped rendering. File keys remain sorted; PHP and Vite keep their own dotenv behavior, including differences for forward references. PHP keeps an undefined `${KEY}` literal, while Vite normally expands it to an empty string.

Refresh each Project once after this release with `orbit project:update PROJECT --source-access=github_app` to capture whole-value references beyond `APP_URL` and `APP_NAME`. Existing Instances stay unchanged: those created under release 1917 keep their stored resolved values until recreated. Template refresh neither flags nor restarts them. Synchronization, transfer and production rendering retain their existing behavior.

A `VITE_` value that references a secret exposes that secret to the browser, as outside Orbit.

The Gateway validates the exact create root, preparation ID, Project, Node and checkout. Clone reads its stored candidate snapshot. These steps run within their parent roots and admit no child Operation. Public synchronization still requires an active Instance.

## Where the file lives

The Instance's recorded [vp runtime and package-manager environment](/reference/tools#project-environments-in-the-vite-store) is separate from its environment variables.

Development create prepares its Vite+ environment after synchronization and before Route publication. This preparation is a step of the parent native root. A Project without Vite+ requirements needs no runtime installation.

The nullable `vp_environment` record holds store versions and prerequisite status, not `.env` keys or values. Preparing or adopting those tools does not import, synchronize, or rewrite the Instance's `.env` file.

The Gateway derives the file location and the user from the Instance's placement. The caller cannot choose the Node, the user, the directory, or the file name.

| Placement | File | User |
| --- | --- | --- |
| `app-dev` | `.env` in the Instance's application directory within the checkout | The Node's managed user |
| `app-prod` | `.env` in the production home | The Instance's production user |

[Swamp dependency sources](/reference/instance-dependency-contracts#separate-monorepo-dependency-sources) describe separate repository paths and ecosystems. Reading these results does not import, store, or synchronize sub-project `.env` files. The Instance environment keeps its existing application-directory scope.

For Laravel, the [application directory](/reference/projects#application-directory) is the effective web root without its trailing `/public`. With root `apps/site/public`, development reads and writes `<checkout>/apps/site/.env`, and `.env.testing` lives beside it. A development default uses the same paths in its checkout, which it serves directly without a `current` link.

On `app-prod`, every release links `.env` in its application directory to the production home's file. With root `apps/site/public`, `<home>/releases/<name>/apps/site/.env` links to `<home>/.env`; no release-root `.env` link is needed. Application data uses separate [shared directories](/reference/deployments#shared-paths); changing those paths does not change the environment file. See [Production release layout](/reference/deployments).

A container Instance keeps its `.env` in its workspace, for `orbit` with group `work`; its create flow writes it. A clone stores the default's `APP_KEY`, because its cloned data is encrypted with it. `env:sync` and `env:import` refuse a container Instance with `operation.kind_unavailable`. See [the default Instance](/reference/development-containers#the-default-instance).

## Import

Import reads the Instance's `.env`. It never reads `.env.example`. A Swamp run on Node 209 checks the file type, the owner, and the size over SSH, then copies the bytes into the Swamp vault key `instance-env-import.content.<operation>`. A missing, unsafe, or oversized file fails the Operation with `env.import_source_missing`. The run returns only the size and sha256, and removes its private temporary copies after success or failure. The Gateway reads the vault key once, checks the sha256, parses and stores the file, and then deletes the key, also after a failure. A size or sha256 mismatch fails with `env.import_unconfirmed` and stores nothing. The [`env:import` chart](/cli/env/import) shows each step.

The Gateway never reads a workload `.env` through the Node agent for an import, and the agent has no command that returns its values. Only synchronization reads the file, through `instance.inspect-environment-sync`.

The Operation declaration names the same exact vault key pattern as the intended Gateway grant policy. The registry check verifies that policy against the declarations before release.

The importer accepts blank lines, comments, quoted and escaped values, multiline quoted values, and expansion of keys from the same file. It does not read the Gateway's own environment. A duplicate key, an unresolved expansion, invalid syntax, or a file over 1 MiB refuses the whole import. Orbit stores keys and values only, without comments or formatting.

Without `replace`, a file key that is already stored fails the Operation with `env.import_conflict`, and nothing is stored. With `replace`, matching keys take the file value, new keys are added, and stored keys that the file lacks stay.

For a Laravel Instance, import stores `APP_URL` as `https://{{instance.domain}}`, so the URL follows the Route. It keeps a non-empty `APP_KEY` as the file has it. When the file contains `APP_KEY` with an empty value, import reuses the Instance's non-empty stored key, or generates a cryptographically random 32-byte key with the `base64:` prefix if no usable stored key exists. A missing `APP_KEY` stays missing; other values stay as the file has them. Non-Laravel imports do not generate keys.

When a Route's domain changes, Orbit updates APP_URL in that application's `.env` and Laravel cached configuration, not in an unrelated file at the repository root. A [Route with a web root](/reference/routes#serve-several-web-roots) writes `APP_URL` into the `.env` of the directory it serves; the stored Instance environment belongs to the Instance's own Route. On production, that file is `<home>/env/<directory>/.env`, which each release links to.

## Update

Update stores one string. `""`, `"false"`, and `"0"` are distinct strings. For a Laravel Instance, `APP_URL` accepts only `https://{{instance.domain}}`.

## Limits

The Gateway checks the complete result before it stores any part of an import or update. A failure returns `env.configuration_invalid` (422). The details name the key, the failed rule, and a leftover placeholder token, but never a value.

| Item | Limit |
| --- | --- |
| Key | 1 to 255 characters. It starts with `A-Z`, `a-z`, or `_`, and can then also hold `0-9`. |
| Value | Valid UTF-8, at most 65,536 bytes, without a NUL byte. |
| Keys | At most 1,024 for each Instance. |
| File | At most 1 MiB after rendering. |
| Placeholders | `{{instance.domain}}` and `{{instance.environment}}`, alone or inside a longer value. Any other `{{...}}` fails. |

## Synchronize

Synchronization takes one snapshot of the Instance, any authoritative Route, and the stored configuration. It resolves `{{instance.domain}}` to the Route's domain when one is present and resolves `{{instance.environment}}` to `development` on `app-dev` or `production` on `app-prod`. Only a stored domain placeholder requires a Route. A leftover `{{` or `}}` after rendering returns `env.reference_unavailable` (409) before the file changes.

The Gateway resolves stored placement and renders the protected values without contacting the Node. It renders every stored key in sorted order, as a quoted value. The runtime user writes the result with mode `0640`, and root gives it group `orbit-env`, so the Node agent can report its key names. A file that already matches stays in place, and the result reports `changed: false`. Stored configuration is the only input. Edits to stored keys disappear.

Admission checks the file's key names from the Node agent's report; the run does not read them.

A key that stored configuration lacks refuses the sync with `env.sync_would_drop_keys`, and the file stays unchanged. Orbit owns, and so may remove, only the keys its last synchronization wrote and the keys a detach removed since. To keep the other keys, store them with `env:update`, or import the file with `replace` when its other keys are already stored. Otherwise remove them from the file. A Route transition on a production Instance skips this check, because deploys already own that file.

When the written file's sha256 differs from the rendered one, the end check fails the Operation with `operation.native_failed` and names the step. Repeat synchronization: it writes the file again.

A stored Database attachment keeps its explicit network choice when synchronization projects environment values. [LAN selection](/reference/database-connections#choose-lan-for-an-instance) requires an active peer and a current managed publication; WireGuard is the default. Transfer and clone reattachment reset the choice to WireGuard.

For an Instance that owns its MySQL or PostgreSQL `DB` database, synchronization also writes `.env.testing` in the same step and mode. The Gateway renders the whole file: the values of `.env`, with `APP_ENV=testing` and the `DB_*` keys of the [test database](/reference/database-connections#test-databases). The write replaces the file; Orbit merges nothing. A file that Git tracks in the checkout stays unchanged. The Operation result records the test database name and testing status: `written`, `unchanged`, `skipped_tracked` or `skipped_unobserved`.

Laravel loads `.env.testing` instead of `.env` when `APP_ENV` is `testing`. So the file holds every key of `.env`, such as `APP_KEY`. Orbit never deletes `.env.testing`. The file stays after the Instance stops owning its `DB` database.

Development synchronization changes only `.env` and `.env.testing`. The official file behavior writes them over SSH as the admitted Node account. No script is copied to the Node, and no agent action runs.

Production synchronization refreshes the runtime on every request, even when `.env` is unchanged. When the current release has `bootstrap/cache/config.php`, it runs `php artisan config:cache` there as the Instance account. An uncached application stays uncached.

It then reloads the Instance's PHP-FPM service, which resets its OPcache, and restarts active Processes whose desired state is running and whose `restart_on_deploy` setting is true. One `Refresh the runtime` step does this on the admitted Node within the same run; there is no separate Process restart kind. Each action is one SSH command, run one at a time in that order. A failed action fails the Operation with `env.runtime_refresh_failed`. A missing current release skips only the config cache.

The Gateway records the native outcome and updates the list of owned keys only after success. A failed root preserves the previous key list and pending-runtime marker. Repeat synchronization after correcting the bounded error.

The Gateway takes one consistent snapshot of the Instance owner, any authoritative Route, and complete stored configuration. It resolves `{{instance.domain}}` from the Route when available and `{{instance.environment}}` to the default Laravel mode for the Instance's Node role (`development` on app-dev or `production` on app-prod). A Route is required only when a stored value uses the domain placeholder; a missing Route or unavailable reference then stops synchronization before replacement. An incomplete Route transition also stops synchronization. The generated dotenv file has stable key order and preserves literal whitespace, newlines, quotes, dollar signs, backslashes, empty strings, and stored application keys.

A running Instance root owns that Instance target. A competing native request returns `operation.target_busy`. Import and update modify stored values; file effects remain owned by the native root.

## Laravel mode

`APP_ENV` and `APP_DEBUG` are ordinary stored keys. The Node role, not these keys, decides the release layout, the Unix user, and the PHP-FPM pool. So a change to `APP_ENV` never moves an Instance between layouts. When `APP_ENV` is absent or is the environment placeholder, Orbit reads it as `development` on `app-dev` and `production` on `app-prod`.

A [clone](/reference/instance-cloning) onto `app-prod` copies the candidate's stored configuration except `DB_*` keys and the keys of its [Reverb app](/reference/websocket-apps#add-an-app-on-an-instance), and then sets `APP_ENV=production` and `APP_DEBUG=false`. For a `symfony-app` it sets `APP_ENV=prod` and `APP_DEBUG=0`, Symfony's production mode. You can change both afterwards. A transfer keeps every stored key.

When the clone seeds a SQLite snapshot, it registers an owned connection on the target Node, attaches it under `DB`, and synchronizes `DB_CONNECTION=sqlite` and `DB_DATABASE=<production_home>/database.sqlite` into the production `.env`. This provisioning step uses the pending clone's placement and Route checks; public environment operations still require an active Instance. An identical retry reuses the connection and attachment. Without a SQLite snapshot, the clone has no candidate `DB_*` values. Other application settings, such as `QUEUE_CONNECTION`, copy unchanged.

While a clone is in progress, its run owns the production `.env`. The `Write .env` step writes the whole file, with `APP_URL` from the pending preview Route, and the release's `.env` links to it.

## Other writers

Other operations also change stored keys, and never the file itself:

- [`instance:database:add` and `instance:database:remove`](/reference/database-connections#add-a-connection-on-an-instance) write or clear the keys of one database prefix.
- [`instance:websocket:add` and `instance:websocket:remove`](/reference/websocket-apps#add-an-app-on-an-instance) write or clear the keys of one Reverb app.

A production [deployment](/reference/deployments) synchronizes the stored configuration before it runs any deploy step. A development default deploys in its checkout, so synchronization writes the files it serves and a deployment does not synchronize.

## Synchronize during a domain change

A development [Instance rename](/cli/instance/rename) updates Laravel `.env` and cached `app.url` through Route convergence, and records the stored APP_URL only after that operation succeeds. If a failed replacement remains, requesting the original domain is not a rollback or a no-op: it returns `route.domain_change_conflict` without another branch or environment mutation. Retry the pending destination to finish the replacement.

A domain change of a production Route rewrites the production `.env` inside the Route operation. It uses the same checks and writer as synchronization. It resolves `{{instance.domain}}` against the new Route, although the public operations refuse an Instance while its Route changes. Stored values stay the same, so a placeholder `APP_URL` changes and a literal `APP_KEY` does not.

When the change fails before the cutover, recovery renders the stored configuration against the old Route and restores `.env` before it removes the new Route. After the cutover, a retry checks the new file and does not revert it. See [Routes](/reference/routes).

## Project update boundary

A Project slug change updates the Laravel `APP_URL` that the Route domain owns. For a development Instance with stored configuration, the Gateway synchronizes `.env` against the replacement Route before the cutover. An Instance without stored configuration skips that synchronization, because there is nothing to render; the update evidence records `environment: skipped` with the reason `env.configuration_missing`. Route convergence then writes the new `APP_URL` into the `.env` and cached configuration of the [application directory](/reference/projects#application-directory) in both cases. For a monorepo Project with root `server/web/public`, that is `server/web/.env`, never a file at the repository root. Run `env:import` to start storing the configuration.

When a step fails before the new slug is published, the Gateway restores the stored configuration, renders the file against the old Route again, and withdraws the replacement Route. After publication, a retry continues forward. See [Update lifecycle](/reference/projects#update-lifecycle).

## Inspect the projection with Doctor

Environment synchronization and deployment own configuration projection and validation. [Doctor](/cli/doctor/doctor#what-each-family-checks) does not read `.env` files or framework caches.

## Storage and recovery

The Gateway encrypts every stored value, placeholders included, with its own application key before it writes the row. Restoring stored configuration needs that key.

Laravel import creates an application's `APP_KEY` only when the source value is empty and no non-empty stored key exists. Swamp development create stores a missing Laravel `APP_KEY` before synchronizing the first `.env`, so a retry keeps the same key. Retained synchronous provisioning writes the non-empty stored key, or generates one for the file only when neither the store nor `.env.example` has a key; import that file to keep its generated key in stored configuration. See [Laravel application URL](/domains/applications#laravel-application-url). Synchronization never generates, rotates, or deletes a stored application key. An import without `replace` still refuses conflicting keys, including `APP_KEY`.

## Errors

Update and the start of import or synchronization return these codes in the Orbit error envelope, with the HTTP status shown. An import or synchronization Operation that fails records its code in `error_code`; those rows show `Operation`. None of the codes contains a value.

| Code | HTTP | Cause |
| --- | --- | --- |
| `env.target_ambiguous` | 409 | The domain reaches more than one Instance. |
| `env.owner_unavailable` | 409 | The Instance is not active or not fully placed, or it lacks exactly one healthy Route. |
| `instance.placement_unavailable` | 409 | The owning Node does not have exactly one active `app-dev` or `app-prod` role. |
| `env.import_conflict` | Operation | Import without `replace` found a key that is already stored. |
| `env.import_source_missing` | Operation | The `.env` file is missing, a link, owned by another user, or over 1 MiB. Fix or restore the file before importing. |
| `env.import_unconfirmed` | Operation | The vault copy does not match the size and sha256 the run reported. Nothing is stored. Repeat the import. |
| `env.configuration_invalid` | 422 | A key, value, placeholder, count, size, or Laravel `APP_URL` rule failed. An import records it on its Operation. |
| `env.reference_unavailable` | 409 | A placeholder is left over after rendering. |
| `operation.target_busy` | 409 | Another operation holds the Instance. |
| `env.configuration_missing` | 409 | Synchronization found no stored configuration. Import or store values first. |
| `env.sync_unconfirmed` | Operation | Swamp cannot confirm the write, or the written file differs from the rendering. Repeat synchronization. |
| `env.configuration_changed` | Operation | Stored values changed between admission and the run start. The run does not start. Repeat synchronization. |
| `operation.kind_unavailable` | 409 | Native synchronization is not registered. No Operation or file change is admitted. |
| `env.runtime_refresh_failed` | Operation | The config cache, the PHP-FPM reload or a Process restart failed. No application output is returned. Repeat synchronization. |
| `env.sync_would_drop_keys` | 409 | The agent reports keys in the file that stored configuration lacks and Orbit never wrote. `details.keys` names them. Import them, or pass `--force`. |
| `env.keys_not_observed` | 409 | The Node agent has not reported the file's key names. Run `env:import`, then `env:sync --force`. For a missing `.env`, `--force` alone. |
| `env.testing_tracking_unknown` | Operation | Git cannot report whether the checkout tracks `.env.testing`. `.env` is written; `.env.testing` stays unchanged. |

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### The Gateway holds the configuration

A `.env` file on a Node holds values for one placement, such as its domain and paths. Another placement cannot rebuild it from the Gateway. So the Gateway stores the configuration, and synchronization writes it for the destination. Treating the Node's file as the source is a rejected alternative.

### Empty Laravel keys are initialized before synchronization

A fresh Laravel checkout often has an empty `APP_KEY`. Storing that empty value would make synchronization erase a key generated only on the Node, and the application could fail after deployment or cloning. Import therefore initializes an empty key in stored configuration before synchronization. It reuses an existing stored key to avoid invalidating sessions or encrypted data on repeated imports. It does not run application code or use the Gateway's own encryption key. Applications with a non-default cipher must supply their own compatible key.

### Every value is encrypted

Orbit does not classify values as secret or not. One missed classification would leave a credential in plain text. So the Gateway encrypts every value.

### Synchronization never merges local edits

A write that pulls edits from the Node would replace stored intent with changes that nobody requested. So synchronization only writes, and import is the explicit way to read a file.

### Synchronization runs no framework

Development synchronization must work before dependencies are installed and before the application runs, so it only writes files. Production FPM disables opcache timestamp validation. Writing `.env` alone cannot make a cached Laravel configuration take effect there. Every production sync therefore rebuilds an existing config cache before resetting opcache.

Swamp runs each PHP and Process action as one SSH command. The Gateway records the final outcome; it does not execute or revalidate runtime work. Environment contents and application output never enter public results.

Production deploy commands receive the rendered environment through private SSH stdin. The Gateway resolves stored placeholders before it creates that execution copy. Swamp writes the durable `.env` separately; no deploy command or environment value travels in an argument list.

### The Node role decides isolation

An operator may set `APP_ENV` to `local` or `staging` on a production Instance. If `APP_ENV` decided the layout or the Unix user, that edit would turn off release layout or user isolation. So the Node role decides them, and `APP_ENV` stays an application setting.

The Instance environment comes from its Node role.

## Development mail

The development renderer projects a parsed `log` mailer to `smtp` only for an eligible new container. Stored values stay unchanged. Existing files use the separate [exact-line mail rule](/reference/development-containers#development-mail), through [`instance:mail:update`](/cli/instance/mail-update).
