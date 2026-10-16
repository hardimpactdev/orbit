---
title: "Docker CE migration"
description: "Planned-window migration of Beast and Shark to Ubuntu docker.io."
covers:
  - apps/gateway/app/Console/Commands/MigrateDockerCommand.php
  - apps/gateway/app/Actions/Fleet/MigrateDockerAction.php
  - apps/gateway/resources/machine-software/migrate-docker.py
---

# Docker CE migration

Every Linux Node uses the recipe's Ubuntu `docker.io`. Recipe convergence refuses Docker CE with `node.recipe_docker_provider` and reason `docker-provider:docker-ce`. Nightly and fleet convergence never migrate the provider. The operator runs this internal Gateway command for one Node during a maintenance window. Its output is JSON; it has no interactive prompts.

## Beast and Shark window

Nick runs this procedure. This task does not change either Node. Beast has CUDA containers using the nvidia runtime and non-Orbit containers, including diction-whisper, ofide, mailpit, ohdear and dngdmt. Shark has prometheus, grafana, pantry-sync-redis and agent-evals. Confirm the inventory on the day: these names are not a manifest.

1. Schedule downtime for every container. Verify independent backups of databases, named volumes and bind mounts. Pause deployments and scheduled fleet maintenance.
2. On the Gateway checkout for the migration, run `php artisan node:migrate-docker Beast --dry-run`. Repeat for Shark. Review container names, package changes, source backups and fallback paths.
3. Resolve any refusal before the window. Dry-run reads the Node and checks registry access but writes no files, stops nothing and does not run apt update.
4. Run `php artisan node:migrate-docker Beast --yes`. Migrate only one Node at a time. Run the Shark command after Beast passes its checks.
5. Check every container's final state in the JSON report. Verify application traffic, database contents, monitoring and DNS.
6. On Beast, verify a real CUDA request and the nvidia runtime. On Shark, verify Grafana dashboards and Prometheus ingestion.
7. Run `php artisan node:converge-recipe Beast --dry-run`, then convergence and Doctor. Repeat for Shark. Restore scheduled maintenance after both Nodes pass.

## Safety and recovery

The command records complete Docker inspect documents for every container, image, network and volume in `/var/lib/orbit/docker-migration/inventory.json`, with a root-only directory and files. This includes environment secrets: never attach the journal to a task or copy it into logs. The public report exposes names, state and Process IDs, not inspect data. The journal pins the recipe identity; resume using the same checkout. Run the same command with `--yes` after a failure. It retains the original inventory and checks the current state rather than taking a new baseline. Do not delete the journal or run Docker prune.

The transaction replaces `docker-ce`, `docker-ce-cli`, `containerd.io`, `docker-buildx-plugin`, `docker-compose-plugin` and any `docker-ce-rootless-extras` with `docker.io`, `containerd`, `docker-buildx` and `docker-compose-v2`. It pins Docker to the recipe and containerd to `2.2.2-0ubuntu1.1`, using authenticated recipe snapshot indexes. An apt simulation refuses removal of unrelated packages. Orbit removes Docker repository entries after writing byte-for-byte backups directly in `/etc/apt`, using the same helper as recipe source retirement. Mixed or unrecognized Docker source entries refuse. `/etc/docker/daemon.json` stays byte-for-byte unchanged, including the nvidia runtime.

After the swap, the command checks that the older engine reads all recorded image IDs, container IDs and volumes. Engine startup failure or unreadable state selects a fresh Docker data root under the journal directory and a systemd drop-in. The original Docker and containerd directories are not renamed or deleted. The fresh daemon manages its own containerd rather than reading the newer system containerd state.

Named volumes in the fresh engine use local bind-backed volumes pointing to their original mountpoints; bind mounts keep their original source. No volume data is deleted or copied. Keep the journal and original data directories for as long as these volumes are used.

Fallback gives each custom bridge network its original Linux bridge name, including Docker's generated `br-<original-ID>` name. Docker can leave that bridge and its connected routes behind after shutdown. Reusing it avoids a second bridge competing for the same subnet. Resume refuses a network whose configuration differs from inventory; it does not delete bridges or routes to force recovery.

In fallback, non-Orbit containers are recreated from the recorded configuration. Images are pulled by recorded repository digest, not a moving tag, and their IDs must match inventory. The original image tags are restored locally.

Orbit Processes are reconciled from the Gateway's stored specs. Containers that were stopped stay stopped; desired-running Orbit Processes restart. A partial recreate resumes by checking the already-created artifact against its inventory configuration. All original container names appear in the final report, with state and health. The report waits up to two minutes for starting health checks. Missing containers, incorrect running states, failed Process reconciliation or failed health checks fail the command.

The command checks registry access before stopping. It refuses writable-layer changes, images without pullable digests, foreign image platforms, unsupported mounts, Swarm, namespace sharing, links and auto-remove. It also refuses plugin volumes or logging, volume driver options and non-bridge custom networks.

Inventory uses the CE engine's latest API. Nondefault settings outside the Ubuntu engine's API 1.52 contract refuse rather than disappearing during recreation. Preserve writable-layer data in a volume or image before migration.

Orbit containers need matching stored Process specs. Every data mount must appear in those specs, including image-declared anonymous volumes. Give those volumes explicit names in the specs before migration. A changed spec or runtime refuses before the package swap.

Custom Docker data roots or daemon host/containerd settings require a separate reviewed migration. This command does not rewrite those settings. Resolve a refusal rather than bypassing it.

## Why it works this way

Docker 29.7/29.8 and containerd 2.3 are newer than the recipe's Docker 29.1 and containerd 2.2. A successful package install does not prove on-disk compatibility. The command inventories before stopping, verifies old state explicitly, and has a clean-engine recovery path that does not consume newer metadata. Downtime and a possible image re-pull are preferable to silently assuming downgrade compatibility. Migration remains separate from convergence so a nightly run cannot stop unmanaged services.
