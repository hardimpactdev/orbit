---
title: "Instance removal"
description: "How Orbit removes an Instance, what --force changes for development source, how owned Processes and Schedules go with it, and how an interrupted removal resumes."
covers:
  - apps/gateway/app/Actions/{Instances/{ProjectDirectInstanceDestroyAction,StartInstanceDestroyAction},DatabaseConnections/PrepareOwnedDatabaseDestructionAction}.php
  - apps/gateway/app/Domain/Instances/{InstanceRemovalStatus.php,InstanceRemovalStep.php,InstanceCreationRecovery.php,InstanceRouteRemovalAdmission.php,ProductionRemovalIntent.php,Removal/**}
  - apps/gateway/app/Infrastructure/{*/RecordedProduction*ContentRetention,Instances/NativeInstanceRemovalProjector,Instances/RecordedProductionInstanceSourceRemoval,Instances/RemoteDevelopmentInstanceSourceRemoval}.php
  - apps/agent/{src/commands/{instances,instances_tests,source}.rs,contract/fixtures/repository-identity.json}
  - apps/gateway/app/Http/Requests/Instances/RemoveInstanceRequest.php
  - apps/gateway/app/Models/{InstanceRemoval,InstanceRemovalMember}.php
  - apps/gateway/database/migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php
  - apps/cli/app/Commands/Instances/DestroyInstanceCommand.php
---

# Instance removal

`instance:destroy` admits one Operation and one native Swamp workflow. The Gateway records the selected Instance, owned resources and desired removal state. Swamp owns source checks, ordering, effects, retries and cancellation. The Gateway projects the admitted records after the bound native run succeeds; it does not verify execution receipts or run commands.

```bash
orbit instance:destroy <instance> [--yes] [--force] [--json]
```

The CLI requires consent. `--yes` confirms without a prompt and is required for JSON or noninteractive calls. `--force` permits dirty or unpublished development source; it never permits another checkout, owner or Node. The API returns HTTP 202. Follow the Operation to its terminal status. Reusing the idempotency key recovers the same admission after a lost reply or target deletion.

## Native order

1. Capture the source facts on the admitted workload Node.
2. Prepare the source removal journal.
3. Stop Schedules, then Processes.
4. Run the admitted Project teardown steps as the configured development Node account.
5. Withdraw whole-Node PHP, Caddy and private DNS.
6. Drop owned databases.
7. Remove captured certificate and LAN scopes.
8. Finalize the checkout or worktree.
9. Project the admitted Route, runtime, database and Instance records atomically.

The native model `route-publication-node-<node-id>` serializes a publication method. A publisher reads fresh authenticated intent after acquiring that model lock. It never publishes a pre-lock render. PHP material stays private. Every effect uses exact `orbit-node-N` placement and the matching string Node label.

## Retry and ownership

The Gateway marks the member set Removing in the same SQLite IMMEDIATE transaction that captures database ownership. Database saves refuse additions, ownership changes and attachments involving a Removing owner. An owned database targeted by another Instance refuses removal.

A failed native run records `failed_step` and retains its removal intent. A retry reuses the accepted source origin, worktree inventory and database artifacts. It does not infer success from an absent result or delete rows before native success. A forced retry may reconcile an already absent checkout only after the local command proves that the exact path is absent and has no symlink replacement.

Only owned resources go with an Instance. Registered external databases lose their records without a backend absence claim. Deleting one Instance never removes another Instance's database targets or an unrelated checkout.

## Available placements

The current native workflow supports local development removal with local SQLite or registered external database records. Managed SQL and production removal are unported and return `operation.kind_unavailable` before admission. Foreign Route publication participants also require the shared participant-public-view contract before admission. These combinations have no polled or synchronous fallback.

The synchronous `DestroyInstanceAction` and its executor-Node HTTP fork are removed. Task workspace ending requires its own native parent DAG; it cannot call a synchronous Instance remover, start a child Operation, or admit removal and pretend cleanup has completed.
