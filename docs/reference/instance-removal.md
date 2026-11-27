---
title: "Instance removal"
description: "How Orbit removes development Instances, what --force changes, how owned Routes, Processes, Schedules, and databases are cleaned up, and how to unregister or restore a host checkout."
covers:
  - apps/gateway/app/Actions/{Instances/{ProjectDirectInstanceDestroyAction,StartInstanceDestroyAction,StartInstanceUnregisterAction},DatabaseConnections/PrepareOwnedDatabaseDestructionAction}.php
  - apps/gateway/app/Domain/Instances/{InstanceRemovalStatus.php,InstanceRemovalStep.php,InstanceCreationRecovery.php,DevelopmentInstanceDestroyProjection.php,InstanceDestroyWorktrees.php,ContainerInstanceDestroy.php,ContainerDestroyExecutionCopies.php,DirectInstanceDestroyIntent.php,InstanceRemovalKind.php,InstanceUnregisterProjection.php,Removal/**,InstanceRemovalReaper.php}
  - apps/gateway/app/Console/Commands/ReapFailedInstanceRemovalsCommand.php
  - apps/gateway/app/{Http/Requests/Instances/RemoveInstanceRequest,Models/InstanceRemoval,Models/InstanceRemovalMember}.php
  - apps/gateway/database/migrations/{2026_12_03_000000_allow_cold_recipe_image_rebuilds,2027_03_01_000000_add_kind_to_instance_removals,2027_03_02_000000_allow_ci_only_proof_copy_removals,2027_03_04_000000_add_reaper_retries_to_instance_removals,2027_03_05_000000_add_start_refusal_to_instance_removals}.php
  - apps/cli/app/Commands/Instances/{DestroyInstanceCommand,UnregisterInstanceCommand}.php
  - swamp/{workflows/workflow-operation-instance-destroy.yaml,workflows/workflow-operation-instance-unregister.yaml,ansible/instance-container-destroy.yml}
---

# Instance removal

`instance:destroy` removes a development Instance along with its Route, Processes, Schedules, owned databases, and checkout or container. Production Instances are not removable through `instance:destroy` or `instance:unregister` once managed on `app-prod` (both refuse with `operation.kind_unavailable`, even with `--force`; see [Production containers](/reference/production-containers#prepare-the-appprod-node)).

```bash
orbit instance:destroy <instance> [--yes] [--force] [--idempotency-key=KEY] [--json]
```

The CLI requires confirmation (`--yes` in JSON or noninteractive mode). `--force` allows discarding dirty or unpushed work on host checkouts and is required to destroy a non-default container Instance, but never bypasses worktree-dependent checks, replication-slot checks, or branch-mismatch checks. The API returns HTTP 202 with an Operation; reusing an `Idempotency-Key` recovers the same admission after a lost reply.

## Admission checks

Before stopping Schedules or recording a removal, Orbit runs a dry-run preflight across blockers, owned databases, Routes, and live host worktrees. A refusal leaves the Instance, Schedules, and Route untouched.

While a [Gateway engine run](/reference/gateway-engine#processstart-and-processstop) holds one of the Instance's Processes, the removal refuses `operation.target_busy` before it records anything.

- **Worktree dependents (`instance.worktree_dependents`, 409):** On host checkouts, Orbit checks for live worktrees pointing into the target's Git data (even with `--force`). Unreadable unrelated checkouts emit bounded `instance.checkout_unknown` warnings.
- **Open operations and migrations:** Active `instance:migrate` operations refuse with `instance.lifecycle_busy` (even with `--force`). Destroying a creating clone cancels the create and queues the destroy.
- **Container rules:** Container clones require `--force` (`instance.container_destroy_force_required`, 409). A `default` cannot be destroyed with active clones (`instance.default_has_copies`, 409) or outside the trial list.
- **Other blockers:** Open transfers (`instance.transfer_incomplete`), active clones (`instance.clone_in_progress`), attached Reverb routes (`websocket.route_in_use`), analytics tracking hosts (`analytics.tracking_hosts_exist`), unsafe checkout paths (`instance.checkout_path_unsafe`), unavailable hosts (`instance.host_unavailable`), and policy refusals (`instance.remove_refused`).

## Replication slots

PostgreSQL refuses to drop a database with a replication slot. Before removing an Instance that owns PostgreSQL databases, Orbit checks each Database server (5-second timeout, no retry, never skipped by `--force`):

- Any replication slot on a planned database (including test databases) refuses with HTTP 409 `instance.database_replication_slot` (`details.slots` lists them). Stop the consumer, drop the slot manually, and retry.
- An unreachable server or unreadable check refuses with HTTP 503 `instance.database_slots_unreadable`.

Physical slots do not belong to a database and never block removal. MySQL, Valkey, SQLite, and unowned attached databases skip this check.

## Native order

The [Gateway engine](/reference/gateway-engine#instancedestroy) can remove a forced, non-default development clone with no other teardown in its plan. It first withdraws the clone's private Routes with `route.withdraw_site` and guarded DNS steps. The Instance and its runtime remain recorded while the Node's removal receipt is missing. The same completion and retry rules apply after a receipt arrives.

While a task copy's [sync on the engine](/reference/gateway-engine#taskssync) holds the copy's Instance lock, removal of that copy, including the one `task:cancel` starts, refuses with HTTP 409 `operation.target_busy` until the sync ends.

Removal follows the [`instance:destroy` chart](/cli/instance/destroy):

1. **Schedules and work checks:** Admission removes Schedule triggers. For non-forced host checkouts, Orbit fetches origin and refuses dirty or unpushed work (ignoring Orbit-rendered `.env` and `.env.testing`). `gh_cli` Projects require `--force` on host checkouts.
2. **Route withdrawal:** Orbit withdraws the Route (including a sole `pending` or `failed` Route) without a DNS grace wait and releases the Route publication hold as soon as withdrawal succeeds (`route_withdrawn_at`).
3. **Processes and teardown:** Stops and removes Instance Processes, then runs Project teardown steps on host checkouts (skipped for containers).
4. **Containers and databases:** Force-stops the container, drops owned databases and Valkey users, deletes SQLite files, and deletes the container and inner volume.
5. **Cleanup and verification:** Removes hibernation, mail, executor, and Conn records, detaches tasks or CI places, verifies deletion, and deletes the Instance rows.

Default removals also delete [spare inner volumes](/reference/development-containers#spare-volumes) and `build-<volume>` builders. An open `instance.fleet` wait never blocks destroy or the reaper and is cancelled on admission.

A database held open by an external client fails with `database.in_use`, `database.server_admin_failed`, or `database.destroy_incomplete` without disconnecting the client.

## Retry and ownership

Run budgets are 60 seconds (host) or 600 seconds (container), plus up to 540 seconds for teardown, 330 seconds per withdrawn Route, and 180 seconds for databases.

A failed or cancelled removal keeps its intent and `removing` status. Start a fresh `instance:destroy` with a new idempotency key to converge; retries skip an already-completed Route withdrawal and treat already-deleted parts as done. Failed container steps record up to 4 KiB of redacted stderr in `error.detail`.

If Incus refuses to delete a container or inner volume whose underlying ZFS dataset is already missing, Orbit creates an empty placeholder dataset matching the recorded pool and name so Incus can delete the record (never running `zfs destroy`). If another dataset already occupies that name, Orbit refuses with `instance.removal_dataset_taken` for manual inspection.

### The removal reaper

Every 5 minutes, `orbit:instance-removal-reap` automatically retries `failed` `destroy` removals (not `unregister`, `abandoned`, `completed`, open transfers, or locked Instances) with backoff of 5 minutes, 15 minutes, 1 hour, and then every 6 hours. Disable it with `ORBIT_INSTANCE_REMOVAL_REAPER=false` in the Gateway `.env`.

If admission refuses a reaper retry, Orbit records `start_refusal` and reports `instance.removal_retry_refused` in Activity and Doctor (`instance.removal_retry_failed` for other errors). After 3 failed retries (or immediately for `instance.removal_dataset_taken` or missing saved intent `instance.removal_intent_missing`), Doctor reports `instance.removal_stuck` on the Instance. The reaper never retries `instance.removal_dataset_taken` or missing-intent removals.

## Available placements

`instance:destroy` supports development Instances on active `app-dev` Nodes and proof copies on `ci-runner` Nodes (including failed container defaults with no running create Operation). Owned SQLite, managed server databases, and registered external database records are supported.

## Processes and Schedules

Admission removes native Schedule triggers, and the removal run stops and removes all Instance Processes before dropping databases and source. Database rows stay until the run succeeds.

## Keep unfinished transfer history

Removing an Instance clears its foreign key on closed transfer records so history is preserved. Any open transfer must finish or roll back before removal (`instance.transfer_incomplete`).

## Unregister a host checkout

[`instance:unregister`](/cli/instance/unregister) removes an Instance's Orbit records, Route, Processes, Schedules, and hibernation files while leaving the host checkout folder, Git data, `.env`, and SQLite files untouched on disk (`POST /api/v1/instances/{instance}/unregister`, MCP `instance-unregister`):

```bash
orbit instance:unregister [<instance>] [--yes] [--idempotency-key=KEY] [--json]
```

Orbit refuses with HTTP 409 for container Instances (`instance.unregister_container`) and Instances owning a Database server database (`instance.unregister_owned_database`), and with `operation.kind_unavailable` for production Instances. A failed removal locks its kind (`destroy` vs `unregister`); switching kinds returns `instance.removal_conflict`.

## Removal states and retained history

A removal record is `removing`, `failed`, `completed`, or `abandoned`. Completed removals delete their members; abandoned removals (created only by `instance:restore`) stamp `abandoned_at` on retained members and stay immutable.

## Restore a failed removal

[`instance:restore`](/cli/instance/restore) abandons a failed host-checkout `destroy` and brings the Instance and its Route back to `active` when no destructive step (source, container, Processes, teardown, or SQLite deletion) ran. It uses a 900-second run budget and holds the Instance and Route locks.

Orbit refuses restore before changing anything (`instance.restore_unsupported`, `instance.restore_state_invalid`, `instance.restore_already_removed`, `instance.restore_busy`, `instance.restore_source_changed`) if the removal is still running, timed out unconfirmed, or targets a container or non-PostgreSQL server database. Restore is also refused if the removal had saved Schedules or SQLite files, lacks a single retiring Route, or if the checkout or Route changed.

During restore, Orbit verifies each owned PostgreSQL database exists and accepts connections (`instance.restore_database_closed` if still fenced), republishes the original Route ID and domain, clears matching fence journal rows, and atomically marks the removal `abandoned` and the Instance and Route `active`.
