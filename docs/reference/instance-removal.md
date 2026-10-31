---
title: "Instance removal"
description: "How Orbit removes an Instance, what --force changes for source, how owned Processes and Schedules go with it, and how an interrupted removal resumes."
covers:
  - apps/gateway/app/Actions/{Instances/{ProjectDirectInstanceDestroyAction,StartInstanceDestroyAction},DatabaseConnections/PrepareOwnedDatabaseDestructionAction}.php
  - apps/gateway/app/Domain/Instances/{InstanceRemovalStatus.php,InstanceRemovalStep.php,InstanceCreationRecovery.php,InstanceDestroyDisposition.php,ProductionRemovalIntent.php,Removal/**}
  - apps/gateway/app/Infrastructure/{*/RecordedProduction*ContentRetention,Instances/RemoteDevelopmentInstanceSourceRemoval}.php
  - apps/gateway/app/Http/Requests/Instances/RemoveInstanceRequest.php
  - apps/gateway/app/Models/{InstanceRemoval,InstanceRemovalMember}.php
  - apps/gateway/database/migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php
  - apps/cli/app/Commands/Instances/DestroyInstanceCommand.php
---

# Instance removal

`instance:destroy` admits one Operation and one native Swamp workflow. The Gateway records the selected Instance, owned resources and desired removal state. Swamp owns source checks, ordering, effects and retries. Accepted removal refuses cancellation; a failed run resumes through a new destroy admission. The Gateway projects the admitted records after the bound native run succeeds; it does not verify execution receipts or run commands.

```bash
orbit instance:destroy <instance> [--yes] [--force] [--json]
```

The CLI requires consent. `--yes` confirms without a prompt and is required for JSON or noninteractive calls. `--force` permits dirty or unpublished source; it never permits another checkout, owner or Node. The API returns HTTP 202. Follow the Operation to its terminal status. Reusing the idempotency key recovers the same admission after a lost reply or target deletion.

## Native order

Swamp executes these stages in order within the bound native run.

| Stage | Action |
| --- | --- |
| 1 | Capture source facts on the admitted workload Node. |
| 2 | Verify that each non-forced source commit is published. |
| 3 | Stop Schedules, then Processes. |
| 4 | Run admitted Project teardown as the configured Node account for development or the dedicated `orbit-app-<project-id>` account for production. |
| 5 | Withdraw whole-Node PHP, the dedicated production PHP runtime, Caddy, private DNS and ingress firewall rules. |
| 6 | Drop owned databases on each recorded Node. |
| 7 | Remove captured certificate and LAN scopes. |
| 8 | Finalize the checkout or worktree, or the exact production home and account. |
| 9 | Project the admitted records atomically. |

The Gateway supplies full non-secret intent when it starts the run. Each placed command performs one action. The native run resolves execution copies from vault key names in its step environment. Steps do not read intent or report results through Gateway HTTP. Terminal projection reads the bound native history once. Every effect uses exact `orbit-node-N` placement and the matching string Node label. Publication steps retain the Route owner’s whole-Node freshness rule.

## Retry and ownership

The Gateway marks the member set Removing in the same SQLite IMMEDIATE transaction that captures database ownership. Database saves refuse additions, ownership changes and attachments involving a Removing owner. An owned database targeted by another Instance refuses removal.

A failed native run records `failed_step` and retains its removal intent. A retry reuses the accepted source origin, worktree inventory and database artifacts. It does not infer success from an absent result or delete rows before native success. A forced retry may reconcile an already absent checkout only after the local command proves that the exact path is absent and has no symlink replacement.

Only owned resources go with an Instance. Registered external databases lose their records without a backend absence claim. Deleting one Instance never removes another Instance's database targets or an unrelated checkout.

## Available placements

The native workflow composes owned MySQL, PostgreSQL and Valkey removal on each recorded server Node, local SQLite removal and registered external record cleanup. It uses one encrypted aggregate database intent for the admitted member set. Each SQL Node receives only its own server passwords.

### Production source

Production removal requires the exclusive recorded `orbit-app-<project-id>` account and `/home/<account>` release layout. Swamp captures the home and selected release inode, account IDs, Git origin and worktree inventory before effects. Production releases are deploy artifacts; their managed shared-path links do not count as dirty source. Missing accounts and homes, and prepared homes without a current release, are valid captured states. Teardown requires both a captured release and admitted scripts.

The saved native capture binds source finalization to the removal. Native inspection verifies the Orbit marker, system account, nologin shell and primary group. Separate command steps quarantine the exact home, remove its contents, delete the account and remove the captured marker directory. Linked worktrees finish before their primary checkout.

Native resume and a fresh destroy admission reuse the original capture. Current source dispositions are separate outputs, so a missing or quarantined source does not rerun teardown. Existing capture values and array membership stay immutable; native inspection may add missing filesystem metadata after proving the original identity. A replacement home, release or account refuses further effects. Foreign publication participants receive only their admitted plans and vault references.

The synchronous `DestroyInstanceAction` and its executor-Node HTTP fork are removed. Task workspace ending requires its own native parent DAG; it cannot call a synchronous Instance remover, start a child Operation, or admit removal and pretend cleanup has completed.

## Processes and schedules

The native run stops each admitted Schedule and Process before database removal. The Gateway keeps their records until the native parent reports success. A failed parent retains its admitted removal state for recovery.

## Keep unfinished transfer history

Removing an Instance preserves closed transfer history by clearing its Instance association after native success. An unfinished transfer must be settled by its owning workflow before Instance removal can proceed. The Gateway does not infer cleanup from a missing Instance or admit a child removal.
