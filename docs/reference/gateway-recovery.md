---
title: "Update and recover a Gateway"
description: "Preserve source, state, and keys through a Gateway update, recover from a failed update, find the request logs, receive release alerts, and follow the fleet rollout."
covers:
  - apps/gateway/app/{Domain,Infrastructure}/Releases/**
  - apps/gateway/config/{app,logging,retention}.php
  - apps/gateway/app/{Infrastructure/Logging/**,Infrastructure/Database/**,Providers/Domains/DatabaseServiceProvider.php}
  - apps/gateway/{app/Domain/Gateway/GatewayCacheStore.php,database/migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php,resources/sigstore/trusted_root.json}
  - "{apps/gateway/app/Actions/Fleet/*Rollout*.php,apps/gateway/app/Actions/Fleet/AdmitFleetCliAction.php}"
  - apps/gateway/app/**/GatewayReleases/**
  - apps/gateway/app/**/*GatewayRelease*.php
  - apps/gateway/{app/Domain/Nodes/NodeUpdat*.php,app/Data/Nodes/NodeUpdatingData.php,app/Domain/Fleet/**,app/Infrastructure/Fleet/**,app/Data/Fleet/**,config/fleet.php}
---

# Update and recover a Gateway

This guide helps an operator keep Gateway state safe during a source update and recover when an update fails. It uses the installation layout from the [Quickstart](/quickstart). Test the procedure on a disposable copy before you rely on it for important data.

Node convergence applies its recorded network part after the other footprint parts. A matching network digest and live state leave WireGuard untouched.

## Gateway request logs

Gateway releases use a [Swamp run](#release-as-a-swamp-run).

The Gateway logs an exception at `ERROR` when its response status is 500 or higher. A lower status is a client refusal. The Gateway does not log it, because [Activity](/cli/activity) already records the failed request.

Every log entry written during an HTTP request carries that request's `request_id`. When the request creates an Activity row, its `activity_log.request_id` has the same value. Search the Gateway logs for the Activity row's request ID to find all related request log entries.

A transaction holds the SQLite write lock from its start, because Gateway transactions begin `IMMEDIATE`. Another writer waits up to the 5-second busy timeout, then fails with `database is locked`. That error names only the writer that waited. The holder is logged as `Long SQLite write transaction` once it ends, with `held_ms` and the `origin` file and line that opened it, when it held the lock 2 seconds or longer.

Measurement ends before post-commit callbacks from both nested and outer transactions, so broadcast and native-dispatch network time is excluded. Nested commits only release savepoints; timing continues until the outer commit releases the write lock. Task maintenance commits its terminal projection before admitting the next operation, so that admission’s GitHub token calls also run outside the projection transaction.

`swamp.vault_sync_unavailable` alone does not identify a database lock. Its `Swamp execution copy unavailable` warning records the transport `phase`, `http_status` and safe remote code. A failure at `phase=connect` with `http_status=429` means the WebSocket upgrade was throttled before a vault frame was sent. Pinned Swamp limits upgrades to 50 per source IP in a 60-second window. Correlate SQLite errors and long-write warnings separately; restarting serve does not fix the request pattern.

Gateway log files rotate daily and keep 14 days. `LOG_DAILY_DAYS` changes the count.

### Disk retention

The [gateway role convergence](/cli/node/role-add) installs the host log-retention files: `journald.conf.d/orbit-retention.conf`, `logrotate.d/orbit-gateway` and the hourly `logrotate.timer` drop-in. Releases leave them unchanged. Laravel's daily logs keep their own rotation policy.

The Gateway scheduler runs Laravel's `queue:prune-failed` daily. `ORBIT_FAILED_JOBS_HOURS` defaults to 168 hours. The daily `queue:prune-batches` uses `ORBIT_JOB_BATCH_HOURS`, which defaults to 24 hours, for finished, unfinished, and cancelled batches. The daily `activitylog:clean` deletes [Activity](/cli/activity) records older than `ORBIT_ACTIVITY_LOG_DAYS`, which defaults to 30 days. Task insights keep their own fields, so they do not depend on old Activity. None of these commands vacuums SQLite: SQLite reuses the freed pages, and only a manual `VACUUM` shrinks the file. [Scheduled work](/reference/gateway-queue#scheduled-work) lists all three.

Local recovery archives need an operator retirement step. Copy a complete state set off the Gateway, verify it there, and remove superseded local copies after the maintenance operation succeeds. Do not accumulate whole-state archives inside `ORBIT_HOME`: the next archive would include them again. Normal release retention owns only `backups/pre-*.sqlite`; it does not own manually named backups, `shared/env-backups`, experiment checkouts, or manually saved recovery copies.

The release build gives Caddy access to the release's public files. The shared Gateway `.env` stays private at mode `0600`.

## Preserve a complete state set

The database alone is not a recoverable Gateway backup. Keep these inputs together and store the backup outside the machine, with access limited to administrators.

| Input | Why it matters |
| --- | --- |
| Exact monorepo commit and lock files | Reinstalls the matching CLI, Gateway, and SDK code. |
| Gateway `.env` | Holds configuration. It can hold the encryption key or a custom database path. |
| Complete `ORBIT_HOME` | Holds `gateway.sqlite` and its WAL files, the SSH identity and known hosts, WireGuard keys, the root CA, generated state, and `gateway.app-key` when the `.env` has no key. |
| Machine configuration or VM snapshot | Keeps service, firewall, DNS, network, ownership, and package state when an update changes them. |
| Application backups | Application databases, uploads, and source are separate from Gateway state. |

A nonempty `APP_KEY` in the environment takes precedence over `ORBIT_HOME/gateway.app-key`. Preserve the effective key with its encrypted data. Do not generate a replacement key during an update or restore. A retained public CA certificate cannot replace its private key.

## Back up before an update

Schedule a maintenance window and stop CLI users and automation from changing the fleet. Record the current commit, Gateway status, and the first application's successful HTTPS response. Keep provider-console access available.

On the Gateway, record the source revision as `orbit`:

```bash
cd /home/orbit/orbit
git status --short
git rev-parse HEAD
apps/cli/orbit gateway:status
```

Resolve or preserve local edits before checkout changes. Stop incoming requests and any running Gateway console operations before copying state. For a complete machine recovery point, take a provider or hypervisor snapshot while the machine is shut down. Store the recorded commit and snapshot identifier with the backup.

For a state archive on a quiescent machine, run these commands from a root console. Use a new backup directory for each attempt:

```bash
sudo systemctl stop orbit-runtime-hibernator.timer
sudo systemctl stop orbit-runtime-hibernator.service caddy php8.5-fpm
sudo install -d -m 0700 /root/orbit-backups
test ! -e /root/orbit-backups/gateway-before-update.tar.gz || exit 1
sudo tar --acls --xattrs -czpf /root/orbit-backups/gateway-before-update.tar.gz \
  -C / home/orbit/.orbit home/orbit/orbit/apps/gateway/.env
sudo chmod 0600 /root/orbit-backups/gateway-before-update.tar.gz
sudo tar -tzf /root/orbit-backups/gateway-before-update.tar.gz
```

In the release layout, archive `home/orbit/shared/gateway.env` instead of `home/orbit/orbit/apps/gateway/.env`, which is a link there.

Adjust the paths when `ORBIT_HOME` or `DB_DATABASE` differs. Include an external database path and its `-wal` and `-shm` files, as [SQLite WAL default](/solutions/sqlite-wal-default) explains. Pause Gateway timers and external automation too; stopping the web services alone does not stop console writers. Do not copy a live SQLite file without its journal state. Transfer the archive to protected storage and verify that it can be read before changing source.

The archive contains secrets. Do not attach it to a bug report or commit it to Git. A successful archive listing verifies readability, not restore behavior.

## Release layout

A Gateway in the release layout runs from immutable releases instead of an in-place checkout. Each release is one exact commit, built beside the live one, so preparing a release never changes what the Gateway serves.

The Gateway reads the current link and the release inventory itself, as the Gateway account, without the agent. So status, deploy, and rollback answer also when the Gateway's own agent is older than its code, or the release has no agent manifest. The read validates each prepared release's `REVISION`, reports incomplete directories with their modification times, and orders retained releases by preparation time with the release ID as the tie-breaker. It also reports whether the serving application supersedes the caller's running application. It writes nothing, and a layout entry that another account owns refuses the read.

| Path | Content |
| --- | --- |
| `/home/orbit/releases/<id>/` | One release: a linked Git worktree of one commit, with its own `vendor/` for `apps/cli` and `apps/gateway`, and a `REVISION` file. `<id>` is the first 12 hex digits of the commit. |
| `/home/orbit/orbit` | A link to the current release. `PATH`, systemd units, the PHP-FPM `chdir`, and the Caddy root keep using this path. |
| `/home/orbit/shared/orbit.git` | The bare repository every release is a worktree of. Its `origin` is the Orbit repository. |
| `/home/orbit/shared/gateway.env` | The one Gateway env file. `apps/gateway/.env` in every release links to it. |
| `/home/orbit/shared/gateway-storage/` | The Gateway storage directory. `apps/gateway/storage` in every release links to it, so the file cache, its locks, and the logs stay the same across releases. |
| `ORBIT_HOME` | Gateway state, outside every release, as before. |

The paths come from `ORBIT_GATEWAY_CHECKOUT`, which names `apps/gateway` below the current link. Run release commands through that link, as `php /home/orbit/orbit/apps/gateway/artisan`. A shell that changed into the directory before a switch still runs the release it entered. A sealed release keeps its source unchanged. Its source directories are read-only, so an in-place `git checkout` or `composer install` inside a release fails instead of changing it. Only `apps/gateway/bootstrap/cache` and `apps/cli/storage` stay writable. The build makes the Gateway cache directory private (`0700`); PHP-FPM and release commands use the same service account.

The Gateway reports its version in `gateway:status`. When `APP_VERSION` is unset or empty, the version is the full commit in the release's `REVISION` file. Without either, the version is `dev`. Leave `APP_VERSION` out of the shared env file in the release layout; a set value always wins.

### Release as a Swamp run

`orbit gateway:release:deploy <sha>` and every [automatic release](#automatic-releases) run as one Swamp workflow. The [chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/gateway/release-deploy.mmd) shows the flow.

The Gateway admits the run. It needs the full commit and no other queued or running release. It verifies the commit's [agent pin](#build-and-seal-a-release) and selects its [web build](#web-build) from an [accepted CI run](#accepted-ci-runs). It starts a running record and records the `gateway.release` Operation under `phases.run`. At the run's start it puts one GitHub token, with contents and Actions read on the release repository, in the execution vault.

The long steps run script files from `swamp/scripts/`, which `bin/swamp-shape-gate` lints with shellcheck. Every script comes from the commit being released, so a release never runs the scripts of another. The build step pipes its script out of the fetched commit in `shared/orbit.git`. The web and snapshot steps run theirs from the sealed release, `/home/orbit/releases/<id>/swamp/scripts/`, and so does the activate child. A Swamp-run release or rollback of a commit from before these scripts fails at the build with `gateway.release_script_missing`: switch to such a release with `bin/gateway-rollback`.

The `operation-gateway-release` workflow runs on the swamp role's workers on Node 209 and reaches the Gateway Node over SSH:

| Step | What it does |
| --- | --- |
| Fetch the commit | Fetches the commit into `shared/orbit.git` when it is missing. The read token reaches git only in its environment. |
| Build the release | Refuses a downgrade with `gateway.release_downgrade`, and builds and seals the release. Prints the grants the new code renders. |
| Install the web build | Downloads the artifact, checks its digest, and extracts it with safe names only into `web/releases/<id>`. |
| Snapshot the database | Writes `backups/pre-<id>-<time>-<hex>.sqlite` with `VACUUM INTO`. The newest three stay, and the one a pause names. |
| Migrate | Runs `artisan migrate --force --isolated` from the new release and prints the end of its output with its exit code. |
| Check the migration | Fails the run when the migration's exit code is not 0. The paused record's message ends with the migration's output. |
| Load Swamp on Node 209 | Runs the [load workflow](#load-swamp) as a child, with the grants from the build. |
| Admit VM refreshes | Runs `task-vms:refresh-images --commit <sha>` from the new release: one base refresh per task VM host, beside the hand-over. Best effort. |
| Hand over the release | Runs `gateway-release-activate` as a child, from the definition the load installed. |

The admission step runs for every release, also one that later fails or switches back, and the hand-over waits only for it to end, never for the refresh. A refresh to a commit that never went live is harmless.

The build adds the worktree of the fetched commit, links the shared env and storage, installs the Composer dependencies, writes the agent manifest, and seals the release with `REVISION` and a cached configuration. It reuses a sealed release of the same commit.

The activate child drains Horizon before the switch. The Horizon unit carries the stop policy ([Horizon](/reference/gateway-queue#horizon)), so `systemctl stop` waits until the unit's whole control group is empty, as long as the drain allows. The step refuses a unit without the policy: update the Horizon Process first. A process that a job leaves behind holds the stop; for this reason a command that runs inside Horizon opens its own [SSH connection](/reference/node-provisioning#ssh-connections), and no shared connection master stays in the group.

The child starts Horizon again as a step that runs after every end of the run, so a failed drain, a failed switch or a switch back leaves Horizon running. It then switches with the new release's own [rollback script](#roll-back-without-swamp), which switches both links, reloads PHP-FPM and restarts the running services under the application.

It checks `/up` and the status version at `https://gateway.orbit`, using `/etc/caddy/orbit-cert-current/root-ca.pem`. The status must be `ok` and name the full commit or its 12-digit ID. It then resumes the release cleanups and prunes old releases. When the check fails, the same script from the new release switches back to the previous release, and the cleanups resume on it. The switch-back then fails the run with `gateway.release_switched_back`, so the Gateway reads it. Migrations are expand and contract, so the previous release runs on the new schema.

The scheduler restart pauses each release cleanup, such as Documents cleanup, under a new generation. The switch step reads the generations first with `gateway:release:cleanup`. After verify, `gateway:release:cleanup --before=<generations>` waits up to 30 seconds for the new ones, reconciles and resumes. A cleanup that cannot resume stays paused and fails only its own step; the release stays verified. Resume it with `project-documents:cleanup:reconcile` and `project-documents:cleanup:resume`.

Releases do not converge static runtime files. The [gateway role convergence](/cli/node/role-add) owns the PHP-FPM pool, the hibernator and agent-view units and the host retention files, and [node converge](/cli/node/converge) publishes Caddy sites and installs the Node agent.

The Gateway records the run's terminal result. The Gateway reads the snapshot path from the snapshot step's output. A succeeded run is `verified`, with `phases.verify.version` set, so the [fleet rollout](#fleet-rollout) follows. A step before Migrate fails the record, and the old release stays live.

A run that switched back is `switched_back`: it raises a `release_failed` alert, and the next green commit releases. Any other failure from Migrate on pauses automatic releases with reason `migration_failure` and names the snapshot, such as a failed switch-back. The record carries the failed step's own code when the step printed one, such as `gateway.release_downgrade`; otherwise it carries the step's code from the [chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/gateway/release-deploy.mmd).

A cancelled or interrupted run may have migrated, so it pauses with `gateway.release_run_failed`. The run budget is 3,000 seconds. A run that outlives it pauses with `gateway.release_run_timed_out`, even when Swamp later reports an end. Read the run before you resume.

While the run is active, every release path refuses with `gateway.release_in_progress`: manual releases, automatic releases and rollbacks use the same admission path. Settling dead records skips the run's record. Admission holds the release lock until the run owns its record.

Read release records with `orbit gateway:release:list` and `orbit gateway:release:show <id>`. The CLI follows an admitted release until it ends. Interrupting the CLI stops following; the Swamp run continues.

After verification, pruning keeps the newest `ORBIT_GATEWAY_RELEASES_KEEP` sealed releases, plus the current and previous releases and the build served by `web/current`. The setting defaults to 3 and has a minimum of 2. Pruning also removes incomplete releases older than one hour and web builds with no retained release. A pruning failure leaves the release live; a later release retries.

### Load Swamp

Swamp serve on Node 209 reads `swamp/` from a Git checkout of the Orbit repository at `/home/orbit/apps/orbit-monorepo-staging/checkout`, and runs with `--hot-reload`. A release loads Swamp in one step, after the migration and before the switch. The build renders both grants files from the target release's `swamp/config/gateway-workflow-grants.json` and `swamp/config/serve-grants.json`, before switching code. It freezes their content and Node 209's SSH target in the load intent. This uses the target release, not the running Gateway's older manifests. The release run starts `operation-gateway-swamp-load` as a child with the release commit. That workflow follows its [chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/gateway/swamp-load.mmd) and runs on the `swamp` role on 209:

"Node 209" here is the Swamp Node of the production fleet: the Node that holds the `swamp` role. The Gateway finds it by that role and never by an id, so a Gateway that bootstraps its own machine has the same Swamp Node ([Gateway bootstrap prerequisites](/reference/node-provisioning#gateway-bootstrap-prerequisites)).

1. it fetches the commit and checks it out in the serve checkout; a rollback loads the retained commit;
2. two parallel official `@swamp/cfgmgmt/file` actions write the captured grant content into `swamp/grants/orbit-gateway.yaml` and `swamp/grants/orbit-serve.yaml`, as `orbit:orbit`, mode `0640`, through the admitted SSH identity;
3. it asks serve to reload, as hot reload would, and waits for the acknowledgement;
4. it runs `swamp access reload`, so serve creates, revokes or keeps grants to match the file;
5. it compares the workflows serve lists with the workflows on disk, leaving out the `schedule-<uuid>` workflows that [Schedules](/reference/schedules) write into serve at any time.

A load-input change must release before a workflow requires its new fields. Retire a checkout script mode only after the served workflow has stopped calling it: a load checks out the target before reloading the workflow.

Required checks invoke the Gateway renderer without deployment credentials and validate both grant files with the pinned serve startup parser before the commit can release.

Grant rendering stays in the Gateway; no Node script renders or publishes grants.

The final `load` step prints the commit, the previous commit, and the workflow count. Any failure, or no terminal within the wait, pauses the release with `gateway.release_swamp_load_failed`, and the old release stays live. The release record names its Operation. A failed load can leave the checkout on the new commit; fix forward and resume.

Serve's reload re-reads each extension manifest's `additionalFiles` ([Lab #4282](https://swamp-club.com/lab/4282), fixed in Swamp `20261103.000636.0-sha.db3f6373`), so a newly shipped file reaches workers without a restart. The load never restarts serve or a worker.

A new workflow needs no grant. The Gateway principal `user:orbit-gateway` has one `run` and `read` grant on every workflow (`workflow:*`), so serve accepts its start as soon as the load step has loaded the workflow.

### Build and seal a release

Admission reads the target commit's pin from `apps/gateway/resources/agent/frozen-manifest.json`. It verifies the published manifest, checksums and [provenance](#release-provenance) in PHP. The manifest must match the pin. Changing agent source does not change the delivered observer until a commit moves the pin. An unavailable delivery returns `gateway.release_agent_delivery_unavailable`; a bad proof returns `gateway.release_provenance_invalid`.

The Swamp build step fetches the full commit through the run's GitHub token. It checks ancestry and refuses a downgrade with `gateway.release_downgrade`, unless the run has `force=true`. It then:

1. checks for free space equal to the database size plus 1 GiB;
2. creates `releases/<id>` as a Git worktree of that commit;
3. links the shared env file and storage directory;
4. runs `composer install --no-dev` and `composer check-platform-reqs --no-dev` in `apps/cli` and `apps/gateway`;
5. writes the admitted agent manifest into `apps/agent/delivery/`;
6. sets source permissions and writes `REVISION`;
7. caches the configuration privately, with mode `0600`.

A sealed release of the same commit is reused. Its configuration is cached again from the shared env file. A partial build is rebuilt on the next run of that commit. Building leaves the serving links and running services unchanged. The next step installs the [web build](#web-build).

#### Release provenance

CI signs each release with the workflow's own identity ([CLI binaries: Provenance](/reference/cli-binaries#provenance)). The Gateway checks that proof in two places. Release admission checks it before it starts the Swamp run. The [desired fleet state](/reference/self-update#how-the-gateway-resolves-it) checks it before it names a CLI digest. Both use one step. It downloads `provenance.sigstore.json` from the release without authentication. It then verifies the bundle offline, with PHP's OpenSSL extension and no call to GitHub or Sigstore.

##### What the Gateway checks

The bundle must be a Sigstore bundle v0.3 with one certificate, one DSSE envelope with one signature, and one transparency log entry. The log entry must pass these checks:

1. It is a Rekor v1 `dsse` 0.0.1 entry.
2. Its log ID names an ECDSA P-256 Rekor key of the trust root. The key is valid at the entry's integrated time.
3. The signed entry timestamp verifies with that key. It covers the entry's body, integrated time, log ID, and log index.
4. The RFC 6962 inclusion proof leads from the body to the proof's root hash.
5. The checkpoint holds the same tree size and root hash. Its signature verifies with the same key.
6. The body records the SHA-256 of the envelope payload, the envelope signature, and the bundle certificate.

The certificate and the statement must pass these checks:

1. A Fulcio CA of the trust root signed the certificate.
2. The integrated time lies inside the CA's validity and inside every certificate of its chain.
3. The integrated time also lies inside the certificate's own validity, which Fulcio sets to 10 minutes.
4. The certificate SAN is exactly `https://github.com/<owner>/<repo>/.github/workflows/orbit-cli-release.yml@refs/heads/main`. The repository is the one in `ORBIT_CLI_RELEASE_REPOSITORY`.
5. Its OIDC issuer extension, OID `1.3.6.1.4.1.57264.1.8`, is exactly `https://token.actions.githubusercontent.com`.
6. The DSSE signature over the payload verifies with the certificate key.
7. The payload is an in-toto statement v1 with SLSA provenance v1.
8. The statement names `SHA256SUMS` with the SHA-256 of the bytes the Gateway downloaded. For release admission, it also names `manifest.json` that way.

The checkpoint must also name the entry's log: its first line is the log host, a space, a hyphen, a space, and the tree ID. Every hash of the proof is 32 bytes. The issuer extension must be a minimal DER UTF8String with no trailing bytes.

The verifier does not check the certificate's SCTs, its code-signing key usage, or that it is not a CA certificate. A pinned Fulcio CA issues only leaf certificates for code signing, valid for 10 minutes. The Rekor signature and inclusion proof then show that the signature was logged inside the certificate's validity. A forged certificate would need the Fulcio CA key, which these checks do not guard against anyway.

The binaries chain through those two files as before.

A bundle that fails any check refuses. So does a Rekor v2 entry, an entry without a signed entry timestamp, or a bundle with only RFC 3161 timestamps. A missing bundle refuses too. A bundle that cannot be downloaded because of a network or server error waits, as a missing release does.

A trust root that cannot be read or parsed, or a release repository that is not on github.com, is a fault of the Gateway, not of the release. Admission then refuses with `gateway.release_provenance_unconfigured`, which waits without spending the commit's attempts. The desired fleet state reports `provenance_unconfigured`.

##### Trust root

The trust root is `apps/gateway/resources/sigstore/trusted_root.json`. It is the Sigstore public-good `trusted_root.json` from Sigstore's TUF repository, `https://tuf-repo-cdn.sigstore.dev`. It was taken on 2026-10-26 as the first line of `gh attestation trusted-root` (GitHub CLI 2.89.0), which verifies it through TUF. The verifier uses only its Fulcio CAs and its ECDSA Rekor v1 log. `ORBIT_CLI_RELEASE_TRUSTED_ROOT` names another file. Leave it unset except during the recovery below.

To refresh the trust root, run this command and merge the result:

```bash
gh attestation trusted-root | head -n 1 | jq . > apps/gateway/resources/sigstore/trusted_root.json
```

##### Failures and recovery

| Situation | What happens | Recovery |
| --- | --- | --- |
| A release has no bundle, or a bundle that does not verify | Admission refuses with `gateway.release_provenance_invalid`. The desired fleet state reports `release_unverified` | None for that commit, because its release never changes. Deploy a later commit |
| A commit whose pinned delivery has no provenance | Release admission refuses | Use [Roll back without Swamp](#roll-back-without-swamp) for a retained release when admission cannot verify its delivery |
| Sigstore adds a new Fulcio CA or Rekor key | Sigstore publishes new keys in its TUF root before it uses them | Refresh the trust root and release it while the old keys still sign |
| A release is signed with a key that the trust root lacks | Admission refuses every new release. The desired fleet state stays unavailable | Override the trust root once, as described below |
| The attest action signs with Rekor v2 or with RFC 3161 timestamps only | Every new release refuses | Revert the action's pin change, as described below |
| Sigstore's Fulcio or Rekor is down while the publish job runs | The attest step fails, so the job fails before the release exists. The Gateway waits for the release | Run the release workflow with `workflow_dispatch` for the commit once Sigstore answers ([Publish a missed commit](/reference/cli-binaries#publish-a-missed-commit)) |
| The repository becomes private | The attest action signs with GitHub's own Sigstore, with RFC 3161 timestamps only. Every new release refuses | Make the repository public again. Release provenance needs the public-good Sigstore |
| The trust root path or the repository setting is wrong | Admission refuses with `gateway.release_provenance_unconfigured` and tries again later. The desired fleet state reports `provenance_unconfigured` | Fix the setting and run [`gateway:release:configure`](#apply-an-env-change) |

To override the trust root once:

1. On a trusted machine, take the new root with the refresh command, and copy the file to `ORBIT_HOME` on the Gateway.
2. Set `ORBIT_CLI_RELEASE_TRUSTED_ROOT` to that file in the shared env file, and run [`gateway:release:configure`](#apply-an-env-change).
3. Deploy the commit that refreshes the committed trust root.
4. Remove the setting, and run `gateway:release:configure` again.

The attest action is pinned by commit SHA, so its bundle format changes only when its pin changes. When a new pin signs in a format the verifier refuses, revert the pin. Teach the verifier the new format first and release that change. Change the pin after.

#### Web build

CI uploads `apps/web` as `web-dist-<sha>` and keeps it for 14 days. Release admission selects the newest unexpired artifact for the exact commit from an [accepted CI run](#accepted-ci-runs). The artifact must have a SHA-256 digest and be no larger than 128 MiB. Only the CI run on `main` uploads it; a pull request run does not, and no merge queue run exists.

The Swamp step downloads the artifact with the run's GitHub token, checks its digest and extracts it into a private staging directory. It accepts only regular files and directories with safe relative names. It refuses links, special files, absolute paths, `..`, more than 20,000 entries or more than 512 MiB of files. The build must have `index.html` at its root. The step gives Caddy access and moves the build into `web/releases/<id>` with one rename. A complete build is reused.

The activate child switches `web/current` together with the Gateway code, before verification. A failed check switches both back. Open pages read `version.json` and move to the new build on their next navigation or resume ([Updates to open pages](/reference/web-app#updates-to-open-pages)). Pruning keeps the current web build and every retained release's build.

A missing or expired artifact refuses admission with `gateway.release_web_build_missing`. An invalid artifact returns `gateway.release_web_build_invalid`. A deploy or rollback needs a valid artifact even when it can reuse a sealed release.

CI's `Required checks` needs the Web job, which uploads the artifact before it succeeds. Automatic releases also need `Gateway release inputs`. Every main commit runs both, including Swamp-only changes, because the release also [loads Swamp](#load-swamp).

##### Accepted CI runs

The run must be a run of `.github/workflows/ci.yml` in this repository: a `push` or a `workflow_dispatch` on `main`. There is no merge queue, so a red `main` commit is never released; `main` is fixed forward. A run from a fork, a pull request, another workflow, or another branch does not count. An artifact without a SHA-256 digest is refused.


### Migrations and the snapshot

Before it migrates, the run writes a consistent SQLite copy with `VACUUM INTO` to `ORBIT_HOME/backups/pre-<id>-<time>-<random>.sqlite`, with mode `0600`. Each attempt writes a new file. Retention keeps the newest three snapshots and the one a pause names. Snapshots stay on the Gateway; keep a [complete state set](#preserve-a-complete-state-set) elsewhere.

The new release runs `php artisan migrate --force --isolated` while the old release still serves. Migrations must use expand and contract: both releases must work with the new schema. Before a schema squash, finish the existing migration chain, as the [contributor guide](/contributor-guide#3-implement-and-verify) requires.

A failed snapshot leaves the live database unchanged. A failed migration may have applied some changes. The run then pauses and names its snapshot. Restoring that snapshot is an operator action; follow [Recover a failed update](#recover-a-failed-update).

### Roll back

To return to a retained release, run:

```bash
orbit gateway:release:rollback <id>
```

`<id>` is the first 12 hex digits of its commit. The Gateway resolves that release's full commit and admits a forced `gateway.release` run with trigger `rollback`. Force skips the ancestry check. The build reuses the sealed release and refreshes its configuration. The load step brings back that commit's Swamp workflows. The run snapshots the database, runs migrations, switches both links and verifies the Gateway, as for a forward release.

Rollback needs the target commit's verified agent delivery and a valid web build. Migrations must use expand and contract so the retained code can use the current database. Rollback does not restore data. If you need to restore a snapshot, follow [Recover a failed update](#recover-a-failed-update). A successful rollback pauses automatic releases with reason `rollback`.

### Roll back without Swamp

When Swamp or the Gateway cannot run `gateway:release:rollback`, run the rollback script on the Gateway Node:

```bash
sudo /home/orbit/orbit/bin/gateway-rollback <id>
```

It needs only the release directories. It refuses an id that is not 12 hex digits (exit 64) and a release without `REVISION` (exit 1), and then changes nothing. Otherwise it switches `/home/orbit/orbit` to `releases/<id>` with one rename, as the owner of that link. It switches `web/current` the same way when the release keeps its web build, and leaves the web app as it is otherwise.

It reloads PHP-FPM, so every request runs the release and OPcache starts empty. Then it restarts each running service whose working directory is under the application, such as Horizon, the scheduler and agent-view. A stopped Process stays stopped, Horizon included: after a release run that ended before its Horizon start, run `orbit process:start` for it, or `systemctl start orbit-process-<id>-horizon.service` on the Gateway when Orbit does not answer. The release run switches forward with the same script. A restart of Horizon by the script waits for its running jobs, up to the drain.

It changes no schema and restores no snapshot, so the database advice under [Roll back](#roll-back) holds here too. It also loads no Swamp definitions and writes no release record. When the Gateway answers again, admit a release of the serving commit to load its workflows and record a verified release.

### Apply an env change

Before editing `/home/orbit/shared/gateway.env`, make a private backup with mode `0600`. Keep both the backup and any temporary replacement out of terminal output, logs, and Git. Every release caches its configuration, so an edit changes nothing until you cache it again. As `orbit`, after the edit:

```bash
php /home/orbit/orbit/apps/gateway/artisan gateway:release:configure
```

It runs `config:cache` locally into a temporary file, sets mode `0600`, then renames it over the current release's `bootstrap/cache/config.php`. A request never reads a partial cache. Do not run `php artisan config:cache` in a release: it rewrites the live cache in place. A deploy or rollback caches its target again, so a retained release picks up the edit when it goes current.

## Automatic releases

The Gateway scheduler runs `gateway:release:auto` every five minutes through `GatewaySchedule::command`. PRs merge straight into main, so the merges of those five minutes go out in one release of the newest green commit. With the [Gateway queue](/reference/gateway-queue#scheduled-work) configured, each tick is a queued job. It looks for the newest green commit and admits a Swamp run. Automatic releases are disabled by default. Turn them on after the Gateway runs from the [release layout](#release-layout) and a first manual release succeeds.

Every release also admits the [refresh of the base snapshots](/reference/compute-drivers#refresh-the-base-snapshot) of each task VM host, from its step "Admit VM refreshes", as soon as Swamp has loaded the commit. The refresh runs while the release switches, and it never holds the release: the step is best effort. A refresh that is open on a host keeps running, and the newest commit waits behind it.

```bash
orbit gateway:release:auto:enable
orbit gateway:release:auto:status
orbit gateway:release:auto:disable
```

A Gateway that ran the release unit still has `orbit-gateway-release.timer` until its [gateway role convergence](/cli/node/role-add) runs. Until then the timer runs the same tick every minute, more often than the scheduler; the release lock and the admission check make the second tick harmless. Its `ExecStopPost` names the removed `gateway:release:settle`, so the unit reports a failed stop step. The convergence stops and disables the timer, then removes it and the release services. Converge only while the current release runs the tick from the scheduler: a rollback to an older release then has no tick until a newer release goes live.


### What a tick does

Each tick of `gateway:release:auto` takes these steps and stops at the first that ends it:

1. It stops when automatic releases are disabled or paused.
2. It stops when a release run is active or the release lock is busy.
3. It settles dead records and stops if this creates a pause.
4. It reads the current release and its `REVISION`. An invalid layout stops the tick.
5. It reads the repository origin locally with `git --git-dir <shared>/orbit.git config --get remote.origin.url`.
6. It asks GitHub for the newest commit with passing `Gateway release inputs` and [green checks](/reference/github-app#find-the-newest-green-commit) that descends from the current release.
7. It respects a manual pin and the retry delay, then admits the [Swamp run](#release-as-a-swamp-run) with trigger `auto`.

`ORBIT_GATEWAY_RELEASE_BRANCH` and `ORBIT_GATEWAY_RELEASE_CHECK` name the branch and check, by default `main` and `Required checks`. A retryable failure waits 10 minutes between attempts, up to three attempts. Waiting for agent delivery spends no retry budget.

If the agent delivery is unpublished, the tick returns `waiting_for_agent_delivery` and writes no release record. It does not loop or sleep. The next scheduled tick checks again and selects the newest green commit. This wait counts toward the 30-minute stall alert.

Every tick stores its result and prints one JSON object. It exits 1 for a manual-deploy pause or when an admitted run did not start.

| Result | Meaning |
| --- | --- |
| `disabled`, `paused` | Automatic releases are off or paused |
| `busy` | Another release holds the lock or has an active run; this counts toward a stall |
| `not_adopted`, `no_deployed_release` | The Gateway has no valid current release or readable `REVISION`; repair the release layout |
| `up_to_date` | No newer commit qualifies |
| `waiting_for_agent_delivery` | The candidate's agent delivery is not available; the next tick checks again |
| `backing_off` | A retryable failure is less than 10 minutes old |
| `source_unavailable` | GitHub, the App, or the shared repository's origin could not be read |
| `started` | A Swamp run was admitted; its terminal result ends the record |
| `failed` | Admission failed; the retry policy decides whether the commit is tried again |

### Pause

A pause is durable state with a reason. It holds until `resume` or until a deploy ends `verified`, whatever other release records come after it. The start of every pause raises one `release_paused` alert that names the reason.

| Reason | Cause |
| --- | --- |
| `migration_failure` | Migrate or a later step failed, without a successful switch-back; inspect the run and snapshot |
| `rollback` | A rollback went live. The runner does not release a newer commit over it |
| `manual_deploy` | A manual deploy went live while a newer green commit already existed: a deliberate pin of an older commit. The runner does not undo it |
| `interrupted` | A release died after a snapshot, a migration, or a switch |
| `marker` | Only `ORBIT_HOME/gateway-release.paused` exists, as an older Gateway wrote it |

A manual deploy or rollback still runs during a pause. A manual or automatic release that ends `verified` ends a pause, so a forward fix hands back to automation. A rollback that goes live starts a `rollback` pause instead.

When a manual deploy goes live, the Gateway asks GitHub whether a newer green commit exists. It stores the answer on the record as the `newest_green` step: `superseded` with that commit, `newest`, or `unknown` when GitHub did not answer. `superseded` and `unknown` make the next tick pause with `manual_deploy`, so a pin is never undone on a guess. After a GitHub outage, a manual deploy may need one `resume`. A manual deploy of the newest green commit hands back to automation, and a newer commit that turns green later is released as usual.

After a failure, decide between a forward fix, a [rollback](#roll-back), and a restore from the snapshot. Then clear a pause that is left:

```bash
orbit gateway:release:auto:resume
```

Resume removes the pause and the marker. The next tick releases the newest green commit, also over a manual release from before the resume. Resume refuses with `gateway.release_not_paused` when nothing is paused. A commit that failed is not released again automatically.

### Failure and alerts

A release record that ends `paused`, or that ends `switched_back`, `failed`, or `interrupted` with no retry left, raises one [release alert](#release-alerts). An `interrupted` record that touched live state alerts at once, because it pauses. A pause that a rollback or a manual deploy starts alerts too. So does a release that goes live but leaves document cleanup paused. The receipt is stored on the record, so the record never alerts twice. A retryable failure does not alert by itself.

Two stalls alert once with the kind `release_stalled`. The subject is the deployed commit.

- A GitHub error, a retryable failure, or a busy release lock lasted 30 minutes. The next tick that reaches GitHub ends the stall.
- The branch head stayed unreleased for 6 hours. This check also runs during a pause. A deployed head ends it.

While it is up to date, the runner reads the branch head at most every 5 minutes. The second stall covers a deployed commit that left the branch after a force push, because then no newer commit can qualify.

### Read the state

`orbit gateway:release:auto:status` shows the switch, a pause with its release, error code, and snapshot, the current release, the last tick, the stalls, and the last run result. `orbit gateway:status` shows the current release and a short summary. A last check older than two minutes needs investigation. Check the Gateway scheduler, its logs and, when configured, the queue workers.


## Recover a failed update

Keep requests and automation paused. Save redacted failure output and preserve the failed state separately for diagnosis. Do not use a generic migration rollback to guess compatibility with older code.

If a command failed before any state or machine change, restore the previous source commit and its locked dependencies, then verify status and HTTPS before reopening access. Once migrations or machine changes ran, recover the complete pre-update state set. The safest recovery for this walkthrough is the matching stopped-machine snapshot. Do not connect a restored clone to the original fleet at the same time: it contains the same WireGuard, SSH, and CA identities.

For an archive-based restore, stop all Gateway writers and use a root console. Move the failed `ORBIT_HOME` aside rather than extracting over it, restore the archive's original ownership and permissions, restore the matching source commit, and reinstall its locked CLI and Gateway dependencies. Restore any external database and service configuration from the same recovery point. Clear stale Laravel configuration, then start services and repeat status, Node, Doctor, DNS, and HTTPS checks before reopening access.

Do not migrate the restored database forward while attempting to run the older code. If the effective encryption key, CA private key, database, or required machine state is missing, stop: an apparently healthy process cannot prove recovery. This procedure does not roll back changes already made to other Nodes or restore application data; recover those from their own backups and review their consistency with the Gateway.

## Release alerts

A release command raises an alert when a release fails, when it pauses automatic releases, or when a Node of a fleet rollout fails.

The [automatic deployment tick](/reference/deployments#automatic-deployments) also raises one when an automatic deployment of a production Instance fails, stalls, or pauses. Its target is `instance:<id>`.

The alert leaves an Activity entry and a problem for the [outer loop](/reference/tasks#outer-loop), and it posts a signed webhook when one is configured. Each part is recorded on its own. A failed part never stops the others, and the alert never fails the release command that raised it. A release command raises the alert outside a database transaction, so a rollback cannot discard the records after the webhook went out.

| Kind | When |
| --- | --- |
| `release_failed` | A release failed. Nothing went live, or the previous release serves again |
| `release_paused` | A release failed after its migrations ran, or died after it touched the schema or the current link, and automatic releases are paused |
| `rollout_halted` | A fleet rollout stopped at a Node that failed. Once per Node, commit and error code |
| `release_stalled` | [Automatic releases](#failure-and-alerts) made no progress for 30 minutes, including a wait for agent delivery, or the branch head stayed unreleased for 6 hours |
| `release_cleanup_paused` | A release went live, but [document cleanup](/reference/project-documents#restore-time-cleanup-gate) stayed paused after the release switch |
| `instance_deploy_failed` | An [automatic deployment](/reference/deployments#automatic-deployments) of a production Instance failed. Once per commit |
| `instance_deploy_stalled` | A commit that the automatic deployment tick would deploy to a production Instance, or a head whose check has not finished, waited 30 minutes. Once per commit |
| `instance_deploy_paused` | A manual deployment or a rollback paused automatic deployment of a production Instance |

Every alert names its subject, a summary, and an optional evidence link. The summary passes through the Gateway log redactor and is cut at 1,000 characters. A field outside these limits is a bug in the calling command, which refuses it before it records anything.

| Field | Content |
| --- | --- |
| `target` | What was released: `gateway`, `fleet`, or another lowercase name of at most 64 characters, such as `instance:12` |
| `repository` | The GitHub repository as `owner/name` |
| `sha` | The full commit SHA: 40 or 64 lowercase hexadecimal characters |
| `release_id` | The release record's id, or null. At most 64 characters |
| `evidence_url` | An `http` or `https` link to the evidence, such as a CI run, or null. At most 2,048 characters |

### Activity entry

Each alert writes one Activity entry with the command `release:alert`. `orbit activity:list --command=release:alert` lists them. The entry starts as `running` and keeps a request ID that the webhook body repeats. Its `properties` hold the kind, the subject, the summary, the evidence link, and the outcome of the problem and the webhook parts. An outcome is `done`, `skipped`, or `failed`, with a reason.

| Status | When | Error code |
| --- | --- | --- |
| `succeeded` | No part failed. A skipped part does not fail the entry | none |
| `failed` | Recording the problem failed | `release.alert_problem_failed` |
| `failed` | The problem part did not fail, but the webhook failed | `release.alert_webhook_failed` |

A failed entry is a server-class Activity row. When the webhook keeps failing, the outer loop counts those rows and files that as its own problem.

### Problem

The alert records `release|{kind}|{resource}|{attempt}|{fault}`, with the source `release`. The failing resource, its attempt, and its fault identify the incident. Source SHAs stay in the evidence and never split an incident. Unlike a recurring signal, it is ready on its first occurrence. The next hourly `problems:file` run files it as a Backlog task in the `orbit` Project, ahead of every other ready fingerprint. [Outer loop](/reference/tasks#outer-loop) describes the sample, the brief, the daily cap, and the mute.

| Outcome | Reason | When |
| --- | --- | --- |
| `done` | none | The fingerprint was recorded |
| `skipped` | `tasks_disabled` | The Tasks extension is disabled, so the outer loop does not run |
| `skipped` | `suppressed` | The fingerprint is on the [suppression list](/reference/tasks#suppression) |
| `failed` | `error` | The database write failed. The Gateway log has the exception |

An open incident keeps one linked group. Further observations update its generated brief while the group and its subtasks remain untouched. Operator edits or a move out of Backlog preserve the group. Confirmed recovery or supersession cancels an untouched generated group. A successful stage is held and does not confirm recovery. Filing clears an occurrence episode; that reset is not recovery evidence.

The recipe image build raised alerts that use `recipe-image:<build id>` as the resource, the build attempt as the attempt, and `build_failed` as the fault. Nothing raises one since the build was removed. An alert raised before stays open until its build is superseded or a later promoted build resolves it.

The incident resolves when a later attempt succeeds, the desired recipe identity differs from the build, or the build row is gone. An attempt that is still running or failed does not prove recovery. A recipe revert to the failed build’s identity does not resolve the incident, even at a newer generation.

A migration merges the old buckets based on SHA only when retained alert evidence proves their resource, attempt, and fault. It adopts only groups whose original generated content and lack of operator activity can be confirmed. Ambiguous history stays available for an operator and cannot file a new group automatically.

### Webhook

Set both values in the Gateway's own `.env` to receive alerts:

| Variable | Content |
| --- | --- |
| `ORBIT_RELEASE_ALERT_WEBHOOK_URL` | The receiver's URL |
| `ORBIT_RELEASE_ALERT_WEBHOOK_SECRET` | The HMAC secret |

When either value is unset or empty, the webhook part is `skipped` with the reason `not_configured`, and the Activity entry and the problem are still recorded. The Gateway never returns the secret. It never stores the URL, the secret, or the response body, because a chat webhook URL is itself a credential. Unset both values on a disposable Gateway clone, so its alerts do not reach the operator.

The Gateway posts one JSON object. It signs `{unix timestamp}.{raw body}` with HMAC-SHA256 and sends `X-Orbit-Timestamp`, `X-Orbit-Signature: sha256={hex}`, and `Content-Type: application/json`. The connection timeout is 3 seconds and the whole request has 10 seconds. The Gateway follows no redirect and does not retry.

| Body field | Content |
| --- | --- |
| `event` | `release.alert` |
| `kind` | The kind above |
| `subject` | `target`, `repository`, `sha`, and `release_id` |
| `summary` | The redacted summary |
| `evidence_url` | The evidence link, or null |
| `text` | One line for a chat channel, such as `Release failed for gateway hardimpactdev/orbit@0123456789ab: Verify found an old version.`, followed by the evidence link. It escapes `&`, `<`, and `>` as Slack expects |
| `occurred_at` | When the alert was raised, in ISO 8601 UTC |
| `request_id` | The Activity entry's request ID |
| `activity_id` | The Activity entry's id, or null when it could not be written |
| `problem_fingerprint` | The recorded fingerprint, or null |

| Outcome | Reason | When |
| --- | --- | --- |
| `done` | none | The receiver answered with a 2xx status |
| `skipped` | `not_configured` | The URL or the secret is unset |
| `failed` | `rejected` | The receiver answered with another status. The outcome keeps `http_status` |
| `failed` | `unreachable` | The connection failed or timed out |
| `failed` | `error` | Any other failure |

The body serves two kinds of receivers. A receiver that verifies the signature reads the typed fields, such as an agent's intake webhook. A Slack incoming webhook shows `text` and ignores the signature, because its URL is the credential. The Gateway still needs a secret to send, so set any random value for such a receiver.

## Fleet rollout

After each verified Gateway release, Swamp brings every managed workload Node to the same release, one Node at a time. A fleet failure never rolls the Gateway back.

The rollout keeps no records of its own. Each pass is one `fleet.rollout` Operation and its Swamp run. The Operation's input holds the desired state, and its result holds each Node's outcome. Read them with [`operation:list --kind=fleet.rollout`](/cli/operation/list) and [`operation:show`](/cli/operation/show). Swamp's run history keeps each step.

### Turn it on

The rollout is off until you set `ORBIT_FLEET_ROLLOUT=true` in the Gateway's environment. While it is off, the Gateway admits no rollout, and Doctor reports no `node.release_lag`.

| Variable | Default | Effect |
| --- | --- | --- |
| `ORBIT_FLEET_ROLLOUT` | `false` | Run rollouts |
| `ORBIT_FLEET_ROLLOUT_ORDER` | empty | Node names, comma-separated, that go first, in this order. The other Nodes follow in the default order. It never adds or removes a Node |

### Execution

The Gateway admits one `fleet.rollout` Operation for the [Nodes that need a visit](#which-nodes-a-pass-visits) at two times:

- when it records a release `verified`
- every day at 03:05 Europe/Amsterdam, when the [window](#rollout-set-and-order) of the other Nodes opens

Both queue the same admission job. The daily schedule only queues it. The job waits a minute at a time while the commit has no CLI release yet, or while a rollout runs. CI publishes the CLI release minutes after the release goes live. A plan with no Node to visit admits nothing.

An operator starts a pass with `orbit fleet:rollout` (`POST /api/v1/fleet/rollout`). Without Node IDs, the Gateway picks the Nodes. The Swamp `fleet-maintenance` schedule that admitted a pass each minute was deleted on 2026-11-06.

Admission validates the serving release and captures the desired state: the commit, the CLI release, the agent pin, and each Node's SSH target. One rollout runs at a time; a second admission returns `fleet.rollout_busy` (409). A plan with no Node to visit admits nothing.

The Operation belongs to Node 209, because its steps run there and record the result on it. Swamp owns order, maintenance windows and publication.

A plan from an older release admits no further Node after a newer release becomes current. The Gateway compares release directories rather than versions, so a leftover `APP_VERSION` does not reopen the old rollout.

### Rollout set and order

The rollout visits a Node when all of these hold:

- it is not a disposable task sandbox;
- it is `active` and runs Linux;
- the Gateway manages it over SSH: it has a WireGuard address and a pinned SSH host key;
- it holds at least one active role other than `gateway`;
- it is not the Gateway's own machine: it holds no `gateway` role and is not the serving host;
- its `/usr/local/bin/orbit` is not a CLI that Orbit did not install.

Roleless Nodes, such as operator machines, and macOS Nodes stay out. Their operators run `orbit self-update`. The Gateway's own Node stays out too. Releases leave its agent unchanged; its CLI is the release's own `apps/cli`. A [CLI convergence](/cli/fleet#how-it-works) names why it leaves a Node out: `sandbox`, `inactive`, `platform`, `unmanaged`, `gateway`, or `roleless`.

A task sandbox is an `app-dev` Node that a [task VM](/reference/compute-drivers#task-vms) owns, on an Incus host. The rollout leaves out the Node of every task VM that is not `destroyed`, with the reason `sandbox`. The Gateway creates a task VM for one task group and destroys it when the group ends. Provisioning links its Node to the task VM before any convergence, so the Node never gets the Orbit CLI.

The rollout never visits a task sandbox, provisioning installs no Orbit CLI on it, and Doctor reports no `node.release_lag` for it. The `sandbox` reason comes first, so a sandbox shows it in every state.

The order goes lowest risk first. Each role has a group. A Node with several roles goes in the latest group of its roles, so it waits until every lower-risk Node is done. Inside a group, the Node ID decides.

| Group | Roles |
| --- | --- |
| 1 | `app-dev` |
| 2 | `ci-runner`, `metrics`, `analytics` |
| 3 | `database`, `websocket`, `vpn`, `router` |
| 4 | `app-prod`, `ingress` |

Orbit has no `agent` or `s3` role yet. The order already places `agent` in group 1 and `s3` in group 2.

`fleet.rollout` starts `operation-fleet-rollout`, one native Swamp DAG for the admitted Nodes. Each Node has a placed publication step. Native concurrency is one; each step requires successful prior Node outputs before acting. There are no child Operations, polled claims, target holds or execution receipts. `fleet.cli_converge` uses the same publication behavior in its own native DAG and updates only the CLI.

Control steps run on Node 209. No step asks orbit-agent to act.

App-dev-only Nodes may start at any hour. Other Nodes use 03:00 inclusive to 06:00 exclusive Europe/Amsterdam. Swamp checks the admitted role policy before CLI publication and again before observer publication. An effect already running may finish after the window closes. Closed windows and a busy binary-publication lock defer that Node to a later pass.

### Desired state

Admission resolves the desired state of the commit the Gateway serves: the CLI release `cli-v0.N.0` with the SHA-256 of each binary, and the agent pin with the SHA-256 of each binary. It stores the state in the Operation's input.

A Gateway in the release layout rolls out only a commit whose release record is `verified`. A pass during a release, before the record exists, does nothing (`release_unverified`). When the configured layout cannot be read, or its link names no complete release, the pass rolls out nothing (`release_layout_unreadable`). An in-place Gateway, whose current path is a checkout, has no release records, and its running commit is the desired state. A Gateway version that is not a commit, such as `dev`, rolls out nothing.

The Gateway can release before CI publishes that commit's CLI: admission verifies the commit's pinned agent delivery. The rollout waits until the CLI release exists (`waiting`).

When the commit's release is still missing after 30 minutes, the desired state [falls back](/reference/self-update#fallback-to-an-ancestor-release) to the newest published release of an ancestor commit with the same CLI code. When the commit's own release appears later, a Node's recorded CLI version differs from the desired one, so the next pass visits it again.

### One Node

Each Node runs the `fleet-release-node` child, and every step runs on Node 209 over SSH with the Node's stored host keys. Its `gate` step checks that every earlier Node succeeded, that the task is not cancelled, and that the Node's window is open. The `cli` step runs the native [`node-cli-install`](/reference/node-provisioning#orbit-cli) child. A newer release or a commit build stays in place.

A rollout then publishes the observer with official resources. `@swamp/cfgmgmt/fetch` writes the pinned agent to `/usr/local/bin/orbit-agent` only when its SHA-256 differs. One SSH read prints the running service's binary digest. The agent restarts only when the file changed or the running digest differs from the pin. The same read then checks that the pinned digest runs on an active unit. A failed fetch records `agent.download_failed`, a failed restart `agent.restart_failed`, and a failed check `agent.observer_unhealthy`. The rollout never reads the agent secret or changes observer configuration.

Full Node footprint convergence remains the Nodes family's operation. Release rollout changes only the CLI, its public connection profile and the observer binary. It does not apply Caddy, DNS, PHP or role configuration.

A lost or unknown command result leaves the Operation unresolved; it is not reported as success.

### A failed Node

At the first failed Node, native dependencies leave later Nodes untouched. The run fails with that Node's error code, and the Operation's result names the Node. The Gateway raises [`rollout_halted`](#release-alerts) for that Node.

Fix the cause. The next pass, at least 5 minutes after the failed run, starts again at that Node, because it is the first Node in risk order that the commit has not converged. That pass comes with the next verified release, the daily window pass, or `orbit fleet:rollout`. Until it converges, later Nodes wait. A deferred Node does not hold back later Nodes, just as it does not inside a run. A retry raises no new alert for the same Node, commit and error code, and the alert's problem resolves once a later pass converges the Node.

Native `swamp workflow resume` does not replace the retry. A failed rollout Operation is final at the Gateway, and the pinned Swamp release drops the inline SSH arguments of a resumed run. A new pass is the retry.

### Which Nodes a pass visits

The Gateway's plan reads the newest result of each Node in the terminal `fleet.rollout` Operations of the desired commit. It visits a Node of the rollout set when one of these holds:

| Node | Why the pass visits it |
| --- | --- |
| No result for this commit | It was not visited yet, or it joined the rollout set since |
| `deferred`, `failed`, `cancelled` or `skipped` | Its last visit did not converge it |
| `converged` or `unchanged`, but drifted | Its observer reports another version, or its recorded CLI version differs from the desired release |

A Node waits at least 5 minutes after its last visit ended. While a failed or cancelled Node waits, the plan names no Node after it. So a Node that was offline is converged by the first pass after it returns, inside its window.

Doctor reports a lagging Node of the rollout set as `node.release_lag` while the rollout is on. A Node outside the set, such as a task sandbox, never gets it. `observed` says why: `no visit yet`, the Node's outcome, or `drifted`. Doctor reads only the desired state that a pass already resolved; it never asks Git or GitHub.

### Nodes being updated

Each Node in `GET /api/v1/nodes` and `GET /api/v1/nodes/{node}` has an `updating` field. It is null unless a [release record](/cli/gateway/release-show) is `running`; then the Node with the active `gateway` role has `kind` `gateway_release`, `since` the record's `created_at`, and `release`, the record id. A `queued` release record does not count, because nothing changed yet.

The Gateway reads a running release's result from its Swamp run. An interrupted run pauses releases, as [Release as a Swamp run](#release-as-a-swamp-run) describes. The Gateway broadcasts [`node.updated`](/reference/events#node) with the new value when a release record starts running and ends. The [web app](/reference/web-app#live-node-and-process-state) shows the Node as `updating`.

A fleet rollout visit does not set `updating`. Its progress is in the Operation and its Swamp run.

## Limits

Automatic releases and the fleet rollout have these limits.

### Release limits

A release needs an existing [release layout](#release-layout), the verified agent delivery, a valid CI web build and access to Swamp. There is no in-place update or layout-adoption command.

Migrations must use expand and contract. A failed health check switches back; another failure from Migrate on pauses automation for an operator. Snapshots stay on the Gateway host. The run needs the database size plus 1 GiB free and keeps the newest three snapshots plus the snapshot a pause names.

The parent workflow runs the definition loaded when it started. The activate child runs the target commit's definition after Load Swamp. Releases reload PHP-FPM and restart running application services. The [gateway role convergence](/cli/node/role-add) owns the static Gateway runtime files, and node converge the Gateway Node's agent.

### Rollout limits

The rollout updates only the Nodes of the [rollout set](#rollout-set-and-order). An operator updates an operator machine or a macOS Node with `orbit self-update`, and the CLI [tells the operator](/reference/self-update#the-newer-release-notice) about a newer release.

The rollout visits one Node at a time, so it takes longer as the fleet grows. A failed Node blocks every later Node until it converges. There is no way to skip it.

The rollout publishes only the CLI, its connection profile and the Node agent. It does not converge the [Orbit footprint](/reference/node-provisioning#converge-the-orbit-footprint), such as the Caddyfile or the `tmpfiles` rule of an `app-dev` Node; `node:converge` does. Caddy, cAdvisor, the FPM exporter, Prometheus, Grafana, Plausible, and Reverb keep their own update paths.

### Not built

Each of these needs its own decision:

- copies of the pre-migration snapshots off the Gateway host;
- `orbit self-update` that applies the rendered footprint on the Node, so the Gateway stops pushing configuration over SSH;
- automatic updates on operator Macs;
- a macOS Node agent;
- a self-update that an agent signal triggers instead of SSH ([Typed commands over SSH](/reference/node-agent#typed-commands-over-ssh)).

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### One small verifier for one bundle shape

The Gateway is the fleet's trust anchor, so it alone proves where a release came from, and it does so before any Node sees the bytes. Asking GitHub's attestation API at runtime was rejected, because it needs an authenticated call. A signing key held by the workflow was rejected too, because it needs a secret and a rotation.

No PHP Sigstore library exists in the locks, and a general one would carry far more code than the one bundle shape that `actions/attest-build-provenance` writes. A pinned external verifier, such as `cosign` or `sigstore-go` offline with a trusted root, or `gh attestation verify --bundle --custom-trusted-root`, was rejected too.

It would be one more binary to pin, deliver, and update on the Gateway, beside the agent. Admission would depend on a process outside PHP before the release starts. Its output would be one more contract for PHP to parse. So the verifier accepts only that shape, a Rekor v1 `dsse` entry with a signed entry timestamp, and refuses the rest. A format change then stops releases instead of weakening the check.

The cost is a trust root to keep current and a verifier to teach a new format before the attest action changes it. Both changes reach the Gateway through a normal release, as long as they ship before the old format or keys stop.

### The Gateway pulls from GitHub

The Gateway is private, so GitHub cannot reach it, and the GitHub App has [no webhooks](/reference/github-app#no-webhooks). A CI job that pushes to a deploy endpoint with a GitHub OIDC identity was rejected: it needs a new trusted path into the Gateway and new credential handling, and an earlier attempt stalled on exactly that.

A CI deploy job that connects to the Gateway over SSH was rejected too: the CI runners are kept off the Gateway on purpose, so CI jobs cannot reach the control plane ([Self-hosted Gateway runner](/reference/implementation-loop#self-hosted-gateway-runner)). Pulling through the existing GitHub App adds no network path, credential, or Node grant.

### Code decides what ships

Whether a commit passed its checks, and whether a release serves, are deterministic questions. `Required checks` and verify answer them, not an agent's judgment. A deploy bot was planned for this duty and rejected for that reason. Judgment is needed only after a pause, and the operator gives it. Each release is one Swamp run whose steps and result an operator can inspect.

### Immutable releases behind one link

An update in place serves a half-changed tree while `git checkout` and `composer install` run, because the Gateway's PHP-FPM pool checks every script for changes on each request. It also needs a maintenance window. A release that is built beside the live one and switched with one rename never serves a mix of two commits. The in-place procedure is gone, not kept beside the release commands, so there is one way to update a Gateway.

### Releases install no development packages

A release runs the Gateway, its artisan commands, and its own CLI. None of them needs a development package. The Gateway and the CLI register Boost only when its classes exist, and the CLI binary already ships from a no-dev install. Leaving those packages out cut the two `vendor/` directories from about 277 MB to 115 MB and the install from about 8 s to 5 s on a warm Composer cache, measured on a build host on 8 Oct 2026. A release cannot run `composer test` or `artisan boost:*`; use a checkout for those.

Copying the current release's `vendor/` into the next one when `composer.lock` is unchanged was measured and rejected. The copy, the write bit it needs back, and the `composer install` that still runs to rebuild the autoloader took 6.6 to 7.2 s against 4.6 to 5.1 s for a fresh no-dev install. Hard links are not an option, because Composer rewrites autoload files in place.

### Reload and verify the release

The switch reloads PHP-FPM so new requests use the new code and OPcache. It restarts running application services so they follow the stable release path. Draining Horizon first lets jobs finish on the release that started them.

### Expand and contract

The old release serves while the new release migrates. Both must work with the resulting schema. This also lets a failed health check switch back without restoring the database. A snapshot protects operator recovery; it does not replace compatible migrations.

### Laravel owns migrations

Laravel owns migration discovery, including vendor migrations. The run uses `migrate --force --isolated` rather than duplicating that discovery in a release guard. A migration failure or another unresolved failure after it pauses automation and names the snapshot for the operator.

### The Gateway is not an Orbit Instance

The Instance pipeline deploys to Nodes over SSH and runs one PHP-FPM pool per Instance. The Gateway is the control plane on its own host. Its release cannot depend on a healthy Gateway to run it.

### The rollout runs one command on each Node

`orbit self-update` is the one node-local updater, on a Node and on an operator's Mac. A rollout in which the Gateway runs each update step over SSH would work, but it keeps the update logic in the Gateway. With one node-local command, a later move to another trigger changes only the trigger.

### One Node at a time, and no rollout records

Converging Nodes in parallel is faster, but a bad release would break many Nodes at once. A failed Node stops the run, because the next Node would most likely fail the same way, and one broken Node is easier to repair than a fleet. A fleet failure never rolls the Gateway back, because the Gateway already verified and serves.

Each pass starts again at the first Node in risk order that the commit has not converged. So a retry visits the failed Node first, and later Nodes stay untouched until it converges. That keeps the halt without a halt record, and the Operations and Swamp's run history are the only record of the rollout.

A closed window or a busy publication lock defers a Node instead of failing it. A missing CLI release is the normal state for a few minutes after each deploy, so it waits instead of failing. The rollout is off by default, so a Gateway release does not reach Nodes before an operator prepared them.
