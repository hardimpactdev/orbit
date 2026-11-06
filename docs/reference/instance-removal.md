---
title: "Instance removal"
description: "How Orbit removes an Instance, what --force changes for development source, how owned Processes and Schedules go with it, and how to retry an interrupted removal."
covers:
  - apps/gateway/app/Actions/{Instances/{ProjectDirectInstanceDestroyAction,StartInstanceDestroyAction},DatabaseConnections/PrepareOwnedDatabaseDestructionAction}.php
  - apps/gateway/app/Domain/Instances/{InstanceRemovalStatus.php,InstanceRemovalStep.php,InstanceCreationRecovery.php,InstanceRouteRemovalAdmission.php,ProductionRemovalIntent.php,DevelopmentInstanceDestroyProjection.php,Removal/**}
  - apps/gateway/app/Http/Requests/Instances/RemoveInstanceRequest.php
  - apps/gateway/app/Models/{InstanceRemoval,InstanceRemovalMember}.php
  - apps/gateway/database/migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php
  - apps/cli/app/Commands/Instances/DestroyInstanceCommand.php
  - swamp/workflows/workflow-operation-instance-destroy.yaml
---

# Instance removal

`instance:destroy` admits one Operation and one native Swamp run. The Gateway records the selected Instance, owned resources and desired removal state. All effects run from control Node209 through official SSH with its approved identity and the targets' full stored host pins. The Gateway projects the admitted records after the bound native terminal result; workflow steps do not read intent or send phase callbacks to the Gateway. The PHP provisioner, source-removal executor, phase checkpoint methods and callback runner are deleted. Shared source classification and native source inspection remain pure inputs to the native workflows.

```bash
orbit instance:destroy <instance> [--yes] [--force] [--json]
```

The CLI requires consent. `--yes` confirms without a prompt and is required for JSON or noninteractive calls. `--force` permits dirty or unpublished development source; it never permits another checkout, owner or Node. The API returns HTTP 202. Follow the Operation to its terminal status. Reusing an idempotency key recovers the same admission after a lost reply or target deletion.

## Native order

The run follows the [destroy flow](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/destroy.mmd). Each Node action is one `@swamp/ssh exec` command or an official `@swamp/cfgmgmt/file` step; nothing is copied to the Node. Admission already removed the Instance's native Schedules. A normal removal first writes the read token as a 0600 credential file, fetches origin in each checkout and refuses dirty or unpushed work (`Check work is pushed`); the next step removes the credential file. The `.env` and `.env.testing` that Orbit renders, at any depth, do not count as work: a stock Laravel `.gitignore` covers `.env` but not `.env.testing`.

The run then withdraws the captured Route publications through the shared Route mutation, so the site goes down first. It stops and removes each Process through the canonical Process child, and runs the Project teardown commands, passed as run inputs, one command per step as the managed account. It removes owned SQLite files with the file behavior and drops owned databases and users on each Database server through the `operation-database-destroy` workflow that `database:destroy` uses. It removes each checkout, a linked worktree through `git worktree remove`, then checks that every checkout is gone. The Gateway deletes owned records only after native success.

Two cases skip work:

- An Instance without a Route skips the Route withdrawal and its DNS records.
- An Instance removal waits no DNS grace: the name goes away, so no client has a new target to move to.

The Route child allows its own steps to fail, so destroy reads its ready result. A withdrawal that did not hold, such as a PHP pool file that changed on the Node, skips every later step and fails the Operation with `route.withdrawal_failed` at `Withdraw the Route`. The Instance, its source and its record stay; fix the Node and rerun. The end check `Check it is gone` always runs, so a source that stayed for any reason fails the run.

Publication captures include the full site catalogs so unrelated Routes, PHP pools and DNS records remain present. Each native action has its own run and iteration identity. The Gateway does not supply an execution lock or recapture publication intent during the run.

## Retry and ownership

The Gateway captures the run budget at admission from the teardown timeouts, the Routes it withdraws and its owned databases; see [Instance setup](/reference/instance-setup#run-setup). Before it removes the source, the run removes each member's [hibernation](/reference/app-dev-runtime-hibernation) files: its awake marker, cold marker and Caddy access log. A failed or cancelled run retains its removal intent. Start a fresh normal `instance:destroy` Operation with a new idempotency key to converge the same admitted removal. Repeating the old key returns the old Operation. This workflow does not depend on `workflow resume`: pinned e459 loses inline official SSH global arguments when resuming a failed run.

A retry repeats every step; each converges, and a checkout that is already gone counts as removed. If a step fails after the Route withdrawal, the site stays down until a rerun finishes the removal.

The Gateway records the member set as Removing in the same admission transaction that captures database ownership. Database saves refuse additions, ownership changes and attachments involving a Removing owner. An owned database targeted by another Instance refuses removal. Only owned resources go with an Instance; unrelated checkouts and database targets remain untouched. Registered external databases lose their records without a backend absence claim.

## Available placements

This parent supports development Instances on managed app-dev Nodes, including their native Process, Schedule and Route withdrawal children. Owned SQLite, managed server databases and registered external database records are supported. Each server child reads the server's admin login from the server's own vault key; the database intent records no password. Production Instances and TaskVM-owned workspaces return `operation.kind_unavailable` before admission. TaskVM workspace readiness belongs to the shared `task-vm-workspace` child and its owning provision parent; an ordinary development checkout has no fabricated TaskVM identity.

Repository reads use a fresh scoped GitHub App credential or normal anonymous access. A configured App without an installation supplies no credential. A non-forced removal that requires `gh_cli` source access refuses before admission; forced removal needs no fetch or GitHub mint. The Gateway mints the read token when the run starts and pushes it to the vault; no step calls the Gateway for it.

## Processes and Schedules

Admission removes each admitted Schedule's native workflow, and the run removes each Process before database and source removal. Their records remain until the parent reports success. 

## Keep unfinished transfer history

Removing an Instance preserves closed transfer history by clearing its Instance association after native success. An unfinished transfer must be settled by its owning workflow first. The Gateway does not infer cleanup from a missing Instance or start a child removal Operation.
