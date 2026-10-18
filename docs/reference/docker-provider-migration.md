---
title: "Docker CE migration"
description: "Planned-window migration of Beast and Shark to Ubuntu docker.io."
covers:
  - apps/gateway/app/Console/Commands/MigrateDockerCommand.php
  - apps/gateway/app/Actions/Fleet/MigrateDockerAction.php
  - apps/gateway/resources/remote/docker/migrate.py
  - apps/gateway/resources/remote/lib/recipe.py
---

# Docker CE migration

Every Linux Node uses the recipe's Ubuntu `docker.io`. Recipe convergence refuses Docker CE with `node.recipe_docker_provider` and reason `docker-provider:docker-ce`. Nightly and fleet convergence never migrate the provider. Fleet convergence records the Node as skipped with reason `docker-provider:docker-ce` and continues to other Nodes. The operator runs this internal Gateway command for one Node during a maintenance window. Its output is JSON; it has no interactive prompts.

## Beast and Shark window

Nick runs this procedure. This task does not change either Node. Beast has CUDA containers using the nvidia runtime and non-Orbit containers, including diction-whisper, ofide, mailpit, ohdear and dngdmt. Shark has prometheus, grafana, pantry-sync-redis and agent-evals. Confirm the inventory on the day: these names are not a manifest.

1. Schedule downtime for every container. Verify independent backups of databases, named volumes and bind mounts. Pause deployments and scheduled fleet maintenance.
2. On the Gateway checkout for the migration, run `php artisan node:migrate-docker Beast --dry-run`. Repeat for Shark. Review container names, package changes, source backups, fallback paths and `space`.
3. Resolve any refusal before the window. Dry-run reads the Node and checks registry access but writes no files, stops nothing and does not run apt update.
4. Run `php artisan node:migrate-docker Beast --yes`. Migrate only one Node at a time. Run the Shark command after Beast passes its checks.
5. Check every container's final state in the JSON report. Verify application traffic, database contents, monitoring and DNS.
6. On Beast, verify a real CUDA request and the nvidia runtime. On Shark, verify Grafana dashboards and Prometheus ingestion.
7. Run `php artisan node:converge-recipe Beast --dry-run`, then convergence and Doctor. Repeat for Shark. Restore scheduled maintenance after both Nodes pass.

## Safety and recovery

The dry-run `space` report shows required archive/commit bytes, the 2 GiB apt margin, available bytes and whether they fit on the journal filesystem. Free enough space before starting. Migration refuses before disabling restart policies or stopping containers if the estimate does not fit.

The command records complete Docker inspect documents for every container, image, network and volume in `/var/lib/orbit/docker-migration/inventory.json`, with a root-only directory and files. This includes environment secrets: never attach the journal to a task or copy it into logs. The public report exposes names, state and Process IDs, not inspect data. The journal pins the recipe identity; resume using the same checkout. Run the same command with `--yes` after a failure.

The prepare phase runs in a transient systemd unit, independent of the SSH session. Its `RuntimeMaxSec=2400` limit sends termination to the whole unit, including apt/dpkg, even while Python ignores termination signals during the swap. A long download or package transaction can therefore strand the Node with no engine.

`TimeoutStopSec=900` and `SendSIGKILL=no` avoid a forced kill but do not protect apt from that first termination signal; a unit may also linger. Check the `orbit-docker-migration-prepare-*` unit with `systemctl` and `journalctl` after a timeout; do not assume the SSH result means the transaction completed. Wait for the old worker to exit and release its journal lock before resuming. Resume with the same recipe and command to repair dpkg before retrying.

The journal records each stage, including `swapping` before apt removes anything. Resume repairs interrupted dpkg configuration and dependencies before retrying the swap. Only loaded Docker and containerd units are stopped. Apt uses the normal locks, configuration and logs with recipe snapshot sources. It retains the original inventory and checks the current state rather than taking a new baseline. Do not delete the journal or run Docker prune.

The transaction replaces `docker-ce`, `docker-ce-cli`, `containerd.io`, `docker-buildx-plugin`, `docker-compose-plugin` and any `docker-ce-rootless-extras` with `docker.io`, `containerd`, `docker-buildx` and `docker-compose-v2`. It pins Docker and containerd to the recipe, using authenticated recipe snapshot indexes. It also removes `docker-model-plugin` when installed and refuses unrelated package removals or downgrades. An apt simulation refuses removal of unrelated packages. Orbit removes Docker repository entries after writing byte-for-byte backups directly in `/etc/apt`, using the same helper as recipe source retirement. Mixed or unrecognized Docker source entries refuse. `/etc/docker/daemon.json` stays byte-for-byte unchanged, including the nvidia runtime.

Before the swap, the journal records the storage driver and effective storage roots. Docker's `/info` `DockerRootDir` is authoritative, including a custom `data-root` such as `/fast/docker`. The system containerd root comes from `containerd config dump`; its persistent data is normally in `/var/lib/containerd`, separate from its ephemeral `/run/containerd` state.

The offline copy reads Docker container, image, volume and network metadata from the recorded Docker root and containerd metadata databases from the recorded containerd root while both daemons are stopped. `metadata_roots` in `inventory.json` maps the stable `metadata/docker` and `metadata/containerd` backup directories to their original restore destinations.

After the swap, bounded daemon startup retries precede checks of the recorded Docker root and every image, container and volume. The command mounts each container layer with the changes API and starts and stops one recorded container with its restart policy disabled. All restart policies remain disabled until verification passes.

Engine startup failure or unreadable state refuses until the operator repeats the command with `--fallback`. This explicit choice selects a fresh Docker data root under the journal directory and a systemd drop-in. The journal stays at `/var/lib/orbit/docker-migration` so recovery can find it without a working engine. The drop-in uses `--data-root=<journal>/engine` and a private config copy in the journal with only `data-root` removed, avoiding a duplicate flag/config setting. The original daemon.json, Docker root and containerd directories are not renamed or deleted.

Fallback stops and persistently masks `containerd.service` so the fresh daemon manages its own containerd. Stopping alone is insufficient: the vendor Docker unit wants that service, and a drop-in cannot clear this dependency. The mask prevents startup through that dependency or after reboot. Keep it while the fallback engine is in use; manual CE restore removes it after metadata restoration.

Named volumes in the fresh engine use local bind-backed volumes pointing to their original mountpoints; bind mounts keep their original source. No volume data is deleted or copied. Keep the journal and original data directories for as long as these volumes are used.

Fallback gives each custom bridge network its original Linux bridge name, including Docker's generated `br-<original-ID>` name. Docker can leave that bridge and its connected routes behind after shutdown. Reusing it avoids a second bridge competing for the same subnet. Resume refuses a network whose configuration differs from inventory; it does not delete bridges or routes to force recovery.

In fallback, non-Orbit containers are recreated from the recorded configuration. Pullable images use recorded repository digests, not moving tags, and their IDs must match inventory. Only images used by recorded containers and without a verified pullable digest are saved, once per image ID, in root-only journal archives. Dangling and intermediate images are not archived.

Changed non-Orbit writable layers are committed and saved once after a clean stop, including shutdown writes; a running layer is captured only if stopping fails. Before stopping, the space estimate covers the selected image sizes, full committed-image archives, writable-layer commit storage and a 2 GiB apt margin. This is a point-in-time estimate, not a disk reservation: pause other disk writers and allow extra headroom for shutdown growth.

Fallback loads these archives before recreating containers. Orbit writable layers are disposable, but their readability is still checked with `/changes` after downgrade; their stored specs and data mounts are the recovery contract. The original image tags are restored locally.

Orbit Processes are reconciled from the Gateway's stored specs. Containers that were stopped stay stopped; desired-running Orbit Processes restart. A partial recreate resumes by checking the already-created artifact against its inventory configuration. All original container names appear in the final report, with state and health. The report waits up to two minutes for starting health checks. Missing containers, incorrect running states, failed Process reconciliation or failed health checks fail the command.

The command checks registry access before stopping when a pullable digest is available. It refuses foreign image platforms, unsupported mounts, Swarm, namespace sharing, links and auto-remove. Bind, named volume and tmpfs mounts are accepted. A refusal after stopping but before the swap restarts only containers that were running at inventory time, except an archive/commit failure: containers stay stopped so resuming can finish the same shutdown snapshot without committing it twice. It also refuses plugin volumes or logging, volume driver options and non-bridge custom networks.

Inventory uses the CE engine's latest API. Nondefault settings outside the Ubuntu engine's API 1.52 contract refuse rather than disappearing during recreation. Fallback preserves non-Orbit writable layers in committed images; keep application data in volumes for independent backup. Final container states are reported on failure as well as success. Resume compares effective daemon defaults instead of treating omitted fields as drift.

Orbit containers need matching stored Process specs. Every data mount must appear in those specs, including image-declared anonymous volumes. Give those volumes explicit names in the specs before migration. A changed spec or runtime refuses before the package swap.

A custom Docker `data-root` in daemon.json is supported in place: Ubuntu docker.io reads the same file, and the command verifies that its effective root stays the same. Daemon `hosts` and `containerd` overrides, foreign Docker or containerd systemd drop-ins, and service unit overrides outside the vendor directories refuse with `docker-migration:custom-daemon`. An invalid or mismatched data root refuses with `docker-migration:storage-root`; an unreadable containerd root refuses with `docker-migration:containerd-root`. Resolve a refusal rather than bypassing it.

The NVIDIA runtime registration, container `Runtime` and `DeviceRequests`, and toolkit configuration stay unchanged. NVIDIA's runtime wraps an OCI runtime, so the provider swap does not require a new NVIDIA configuration. The package guard refuses removal of the toolkit or other unrelated packages. The fallback config preserves the NVIDIA registration as well as logging and storage settings. A real CUDA request remains a required maintenance-window check; metadata checks do not prove GPU execution.

## Why it works this way

Docker 29.7/29.8 and containerd 2.3 are newer than the recipe's Docker 29.1 and containerd 2.2. A successful package install does not prove on-disk compatibility. The command inventories before stopping, verifies old state explicitly, and has a clean-engine recovery path that does not consume newer metadata. Downtime and a possible image re-pull are preferable to silently assuming downgrade compatibility. Migration remains separate from convergence so a nightly run cannot stop unmanaged services.

## Rollback and no-engine recovery

There is no automatic engine rollback. Before the window, retain the installed CE package versions and obtain their deb files and dependencies independently. Keep the apt source backups, the metadata backup under the journal, and independent data backups. A metadata copy is not a backup of volume contents.

If apt fails after removing CE, leave containers stopped and rerun the same recipe command with `--yes`. The `swapping` stage runs `dpkg --configure -a` and a simulated, guarded `apt-get -f install` before retrying the pinned install. This path does not need a working Docker socket. Do not remove the journal or restart services by hand during verification.

If the downgraded engine cannot mount the recorded layers, review the failure states and run `php artisan node:migrate-docker Beast --yes --fallback` (or Shark). This starts a fresh engine and loads saved local images and committed layers. It never deletes original metadata or volume data.

If recovery cannot proceed, stop docker.service, docker.socket and containerd.service. Before reinstalling CE, block package scripts from starting services: preserve any existing `/usr/sbin/policy-rc.d` and temporarily put an executable script there containing `#!/bin/sh` followed by `exit 101`. Keep this policy in place throughout package and metadata restoration.

Restore the retained CE packages and original daemon configuration and remove only the migration-owned systemd drop-in after checking its contents. The original daemon.json selects the original custom root again; never substitute `/var/lib/docker`. Restore Docker repository entries from their `/etc/apt/*.orbit-docker-backup` files only if CE repository access is needed.

If the old engine has modified metadata, restore the offline metadata copy before starting CE, using the destinations in the journal's `metadata_roots`; preserve the original directories and get a reviewed recovery plan rather than overwriting uncertain state.

If fallback masked `containerd.service`, check that the journal records fallback and `/etc/systemd/system/containerd.service` is the mask symlink to `/dev/null`, then run `systemctl unmask containerd.service`. Run `systemctl daemon-reload`, restore the previous startup policy (or remove only the temporary script if none existed), then start CE and verify every container and application before ending the window.

The journal contains credentials, image archives and volume backings. Never delete it while fallback volumes use paths beneath it. To clean up after a native-engine success, stop migration activity, archive the root-only journal to protected backup storage, check that no Docker data root, systemd drop-in or volume device references it, then remove the journal. After fallback, first migrate bind-backed volumes and the fresh engine to separately reviewed permanent storage; only then perform that cleanup.
