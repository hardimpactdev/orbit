---
title: "Instance removal"
description: "How Orbit removes an Instance, what --force changes for development source, how owned Processes and Schedules go with it, and how to retry an interrupted removal."
covers:
  - apps/gateway/app/Actions/{Instances/{ProjectDirectInstanceDestroyAction,StartInstanceDestroyAction},DatabaseConnections/PrepareOwnedDatabaseDestructionAction}.php
  - apps/gateway/app/Domain/Instances/{InstanceRemovalStatus.php,InstanceRemovalStep.php,InstanceCreationRecovery.php,InstanceRouteRemovalAdmission.php,ProductionRemovalIntent.php,Removal/**}
  - apps/gateway/app/Infrastructure/{*/RecordedProduction*ContentRetention,Instances/RecordedProductionInstanceSourceRemoval}.php
  - apps/gateway/app/Http/Requests/Instances/RemoveInstanceRequest.php
  - apps/gateway/app/Models/{InstanceRemoval,InstanceRemovalMember}.php
  - apps/gateway/database/migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php
  - apps/cli/app/Commands/Instances/DestroyInstanceCommand.php
---

# Instance removal

`instance:destroy` admits one Operation and one native Swamp run. The Gateway records the selected Instance, owned resources and desired removal state. All effects run from control Node209 through official SSH with its approved identity and the targets' full stored host pins. The Gateway projects the admitted records after the bound native terminal result; workflow steps do not read intent or send phase callbacks to the Gateway. The PHP provisioner, source-removal executor, phase checkpoint methods and callback runner are deleted. Shared source classification and native source inspection remain pure inputs to the native workflows.

```bash
orbit instance:destroy <instance> [--yes] [--force] [--json]
```

The CLI requires consent. `--yes` confirms without a prompt and is required for JSON or noninteractive calls. `--force` permits dirty or unpublished development source; it never permits another checkout, owner or Node. The API returns HTTP 202. Follow the Operation to its terminal status. Reusing an idempotency key recovers the same admission after a lost reply or target deletion.

## Native order

The parent captures source ownership, repository identity, branch and the complete linked-worktree inventory before removing anything. It verifies each source, stops its Schedules and Processes through their canonical children, and runs the admitted Project teardown commands as the managed account. It then removes owned SQLite files, withdraws the complete captured Route publications through the canonical Route children, and finalizes linked worktrees before their owning checkout. The Gateway deletes owned records only after native success.

Publication captures include the full site catalogs so unrelated Routes, PHP pools and DNS records remain present. Each native action has its own run and iteration identity. The Gateway does not supply an execution lock or recapture publication intent during the run.

## Retry and ownership

A failed or cancelled run retains its removal intent, public source capture and local removal receipts. Start a fresh normal `instance:destroy` Operation with a new idempotency key to converge the same admitted removal. Repeating the old key returns the old Operation. This workflow does not depend on `workflow resume`: pinned e459 loses inline official SSH global arguments when resuming a failed run.

A fresh retry keeps the original source digest and inode even after the checkout moves to quarantine. Verification accepts a missing original path only when its exact admitted receipt matches. It refuses symlink replacements and foreign receipts. An originally absent source is an explicit observation, not a backend success claim inferred from a missing result.

The Gateway records the member set as Removing in the same admission transaction that captures database ownership. Database saves refuse additions, ownership changes and attachments involving a Removing owner. An owned database targeted by another Instance refuses removal. Only owned resources go with an Instance; unrelated checkouts and database targets remain untouched. Registered external databases lose their records without a backend absence claim.

## Available placements

This parent supports development Instances on managed app-dev Nodes, including their native Process, Schedule and Route withdrawal children. Owned SQLite and registered external database records are supported. Production Instances, managed server databases and TaskVM-owned workspaces return `operation.kind_unavailable` before admission. TaskVM workspace readiness belongs to the shared `task-vm-workspace` child and its owning provision parent; an ordinary development checkout has no fabricated TaskVM identity.

Repository reads use a fresh scoped GitHub App credential or normal anonymous access. A configured App without an installation supplies no credential. A non-forced removal that requires `gh_cli` source access refuses before admission; forced removal needs no fetch or GitHub mint.

## Processes and Schedules

The native run stops each admitted Schedule and Process before database and source removal. Their records remain until the parent reports success. 

## Keep unfinished transfer history

Removing an Instance preserves closed transfer history by clearing its Instance association after native success. An unfinished transfer must be settled by its owning workflow first. The Gateway does not infer cleanup from a missing Instance or start a child removal Operation.
