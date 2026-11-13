---
title: "Instance removal"
description: "How Orbit removes an Instance, what --force changes for development source, how owned Processes and Schedules go with it, and how to retry an interrupted removal."
covers:
  - apps/gateway/app/Actions/{Instances/{ProjectDirectInstanceDestroyAction,StartInstanceDestroyAction},DatabaseConnections/PrepareOwnedDatabaseDestructionAction}.php
  - apps/gateway/app/Domain/Instances/{InstanceRemovalStatus.php,InstanceRemovalStep.php,InstanceCreationRecovery.php,InstanceRouteRemovalAdmission.php,ProductionRemovalIntent.php,DevelopmentInstanceDestroyProjection.php,InstanceDestroyWorktrees.php,ContainerInstanceDestroy.php,ContainerDestroyExecutionCopies.php,DirectInstanceDestroyIntent.php,Removal/**}
  - apps/gateway/app/{Http/Requests/Instances/RemoveInstanceRequest,Models/InstanceRemoval,Models/InstanceRemovalMember}.php
  - apps/gateway/database/migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php
  - apps/cli/app/Commands/Instances/DestroyInstanceCommand.php
  - swamp/{workflows/workflow-operation-instance-destroy.yaml,ansible/instance-container-destroy.yml}
---

# Instance removal

`instance:destroy` admits one Operation and one native Swamp run. Before any removal side effect or Operation write, host checkout admission uses the existing SSH remote-program reader to inspect live Git dependencies. It refuses unreadable Git in the target and never relies on `source_layout`. It reads only a host checkout with a nonempty stored path, a non-reserved source state and an active Node; containers skip it. A reserved source that was never created needs no read. An active checkout’s disk state can be known only on its Node; a missing path returns no dependencies.

The Gateway then records the selected Instance, owned resources and desired removal state. All effects run from control Node209 through official SSH with its approved identity and the targets' full stored host pins. The Gateway projects the admitted records after the bound native terminal result; workflow steps do not read intent or send phase callbacks to the Gateway. The PHP provisioner, source-removal executor, phase checkpoint methods and callback runner are deleted. Shared source classification and native source inspection remain pure inputs to the native workflows.

Every caller first tries the same pure preparation that admission uses: stored blockers, database ownership and attachments, members, runtime, Routes, publication participants, SSH connections, and Caddy, PHP and DNS rendering. This dry run records no intent or status, stages no execution copy and takes no Route hold. Its result is discarded. A refusal here leaves Schedules, the Instance and its Route active, with no Operation.

After Schedule removal, the existing admission transaction runs the preparation again and records only this fresh result. No fact or result from the dry run feeds admission. A Schedule removal failure still leaves the Instance and Route active and records no removal Operation.

If a fact changes after the dry run, admission uses its current value and may refuse; Schedules may already be stopped.

```bash
orbit instance:destroy <instance> [--yes] [--force] [--json]
```

The CLI requires consent. `--yes` confirms without a prompt and is required for JSON or noninteractive calls. `--force` permits dirty or unpublished development source; it never permits another checkout, owner or Node. The API returns HTTP 202. Follow the Operation to its terminal status. Reusing an idempotency key recovers the same admission after a lost reply or target deletion.

`instance.worktree_dependents` refuses with HTTP 409 when another live worktree keeps Git data inside the target checkout, including with `--force`. It reports visible dependent Instances across every Project on the Node, an `other_dependents` count and `unowned_paths`. No Schedule, Route or Operation is changed by this refusal. The check reads recorded checkout paths to find renamed dependents. Deleted registrations with no surviving recorded checkout do not block removal. It does not walk the checkout tree; dependency folders and their size do not affect the read.

An unreadable recorded checkout elsewhere does not block unrelated removal. After a Git error or its three-second timeout, Orbit reads only bounded regular pointer files, without Git or a tree walk. Absolute and relative `gitdir` and `commondir` pointers into the target prove a dependent. Existing owner registrations for live paths also block without reading Git in those checkouts. A directory with its own Git store is unrelated unless its `objects/info/alternates` points into the target; the same bounded check follows alternates without Git.

Missing or unreadable candidate paths, Git errors/timeouts and unreadable or malformed pointers proceed with typed `instance.checkout_unknown` warnings naming the path, recorded Instance IDs and cause. This includes mode-000 markers, FIFOs, symlink loops, unreadable commondir/alternates, oversized pointers and gitdir paths through regular files. Pointer files are opened non-blocking, must be regular and are capped at 4 KiB. Symlinks resolve before comparison.

Only a confirmed dependency or a target read failure refuses. The target’s directory, pointer or Git failure names its path and the unreadable or timed_out cause. Candidate Git reads share one three-second allowance; later candidates use pointer reads once it is spent. No read is retried. Admission warnings survive in the Operation result. As in transfer, human output names the warning code, path, Instance IDs and read cause; JSON keeps the typed warning. The Gateway inventory excludes containers and empty or relative paths; malformed candidate rows are bounded warnings, never a whole-call failure.

## Replication slots

A replication slot keeps PostgreSQL from dropping its database. Without a check, the drop would fail after the fence closed the database to new clients, and the removal would stop half way. So admission reads the slots first.

For each Database server with an owned PostgreSQL database, the Gateway runs one read on the server's Node. The read uses the drop's own admin client: `sudo -n docker exec -i` into the server container, as `postgres`. The server's admin password is the first line of protected stdin. The read names every database that the drop plans, test databases included, through the drop's identifier check. Connect and read stay within 5 seconds per server. There is no retry.

- A slot on a planned database refuses with HTTP 409 `instance.database_replication_slot`. Any slot type counts, active or not.
- The message names the database, the server and the slot. `details.slots` lists each slot.
- A read that cannot be made refuses with HTTP 503 `instance.database_slots_unreadable`.
- The read fails on an unreachable Node, a timeout, a client failure or output that Orbit cannot read.
- Orbit does not close or drop a database that it could not check.

Both refusals come before any effect. No Schedule trigger is removed, and no Operation or removal is recorded. The Instance keeps its status, the Route stays published and no Node hold is taken. `--force` does not skip the check. A retry of a failed removal runs it again.

Orbit never drops a slot. Stop the tool that owns the slot, drop the slot by hand on the server, then run the removal again. A physical slot has no database, so it never blocks. Orbit reads nothing for MySQL, Valkey and SQLite databases. It also reads nothing for an attached database that the Instance does not own.

A running migration or an unsettled migration runtime refuses removal with `instance.lifecycle_busy`, including forced removal. Orbit checks migration ownership before cleanup and again under the Instance admission lock.

A failed migration that keeps its runtime reservation blocks destroy, even with `--force`, with `instance.lifecycle_busy`; let any open migration expire, then rerun `instance:migrate`.

## Native order

The run follows the [destroy flow](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/destroy.mmd). Each Node action is one `@swamp/ssh exec` command, an official `@swamp/cfgmgmt/file` step or, for the Route withdrawal, an Ansible phase; nothing is copied to the Node. Admission already removed the Instance's native Schedules. A normal removal first writes the read token as a 0600 credential file, fetches origin in each checkout and refuses dirty or unpushed work (`Check work is pushed`); the next step removes the credential file. The `.env` and `.env.testing` that Orbit renders, at any depth, do not count as work: a stock Laravel `.gitignore` covers `.env` but not `.env.testing`.

The run then withdraws the captured Route publications, so the site goes down first.

The same `route-withdraw` workflow takes back the Route of a create that failed. Its `side` input is `forward` for a removal and `restore` for a failed create, which applies the captured publication in reverse. Both parents resolve this child only when withdrawal runs, so a skipped withdrawal does not scan its behavior definitions during parent preflight.

Each Node that serves the Route, answers its private DNS or holds a withdrawn certificate scope gets one Ansible read, one composition and one Ansible apply, and the Nodes run in parallel. A failure on one Node does not stop the others, so a rerun finishes what is left. Before it writes, the apply phase checks that each file still has the checksum the read found, and refuses otherwise.

The checks run as one `sha256sum` command and the certificate folders go in one `rm -rf` command, because each remote task costs about 0.4 s. The run then stops and removes each Process through the canonical Process child.

It runs the Project teardown commands, passed as run inputs, one command per step as the managed account. It removes owned SQLite files with the file behavior and drops owned databases and users on each Database server through the `operation-database-destroy` workflow that `database:destroy` uses. Source removal is unchanged from main before the worktree guard: the existing SSH command attempts Git worktree removal for a .git file and falls back to recursive deletion. There is no dependency check or new refusal after admission. The run checks that every checkout is gone; the Gateway deletes owned records only after native success.

Known limits: Git data of nested or bare repositories inside the checkout with outside dependents is not scanned. A moved worktree behind an unreadable parent is unknown, so admission proceeds with its warning. No lock excludes external Git writers. A worktree created after admission is not caught: the window includes the queue wait and the entire run until source removal. An admitted retry uses the saved intent. Dispatch never checks dependencies again, even if Git facts changed. Transfer uses the same admission-only boundary in its separate PR #3451.

A sole pending or failed Route always runs withdrawal before its record goes with the Instance. A first publication can install a site, certificates or DNS and then fail its TLS probe while `sites_published` stays false. Status and that flag therefore never justify skipping withdrawal. Admission captures the possible publication and its absence; the shared withdrawal removes either partial publication or nothing, while keeping unrelated sites and DNS.

The Route must belong to the Project, target exactly this Instance and have safe placement. Multiple owning Routes refuse. These checks follow the worktree admission check and precede Schedule or record changes.

Three cases skip work:

- An Instance without a Route skips the Route withdrawal and its DNS records.
- An Instance removal waits no DNS grace: the name goes away, so no client has a new target to move to.
- An Instance removal applies each published Caddyfile and DNS state once: the Route withdrawal composes the captured states in order against what the Node holds and writes the result once.

The Route child allows its own steps to fail, so destroy reads its ready result. A withdrawal that did not hold, such as a PHP pool file that changed on the Node, skips every later step and fails the Operation with `route.withdrawal_failed` at `Withdraw the Route`. The Instance, its source and its record stay; fix the Node and rerun. The end check `Check it is gone` always runs, so a source that stayed for any reason fails the run.

Publication captures include the full site catalogs so unrelated Routes, PHP pools and DNS records remain present. Each native action has its own run and iteration identity. The Gateway does not supply an execution lock or recapture publication intent during the run.

A container Instance follows the [container destroy flow](/reference/development-containers#destroy). A non-default container Instance, cloned or migrated, requires `--force`, skips work checks and teardown, and deletes the container with its Processes and owned network state. It deletes only its own UFW rules, as [Destroy](/reference/development-containers#destroy) describes. That step runs one Ansible playbook with the shipped `ansible.cfg` over one pipelined SSH master connection. Without the master, each of its 11 remote tasks opened six SSH logins, and the step took 27 s against 1.5 s for `incus delete --force` itself. The Instance named `default` stays unavailable. Conn removes its environment and pairing records after the T3 server is gone.

Destroy also deletes migration execution copies, including the remote-read credential, and the create copies of the owned database and of the clone's own Valkey user, which hold their server and owner logins. The clone's Valkey user is deleted with its owned databases; the default's Valkey user stays. The runtime row stays until success, so its address and port stay reserved during a failed removal.

Destroy removes the Instance's folder for pending mail activation from the Node, `/var/lib/orbit/instance-mail/<container>`, and its [asleep marker](/reference/development-containers#sleep-and-wake), so no later Instance or domain finds it. A sleeping container is deleted as it is, without a wake. Its shared bridge endpoint stays for ordinary host reconciliation, as database endpoints do.

## Retry and ownership

The Gateway captures the run budget at admission from the teardown timeouts, the Routes it withdraws and its owned databases; see [Instance setup](/reference/instance-setup#run-setup). Before it removes the source, the run removes each member's [hibernation](/reference/app-dev-runtime-hibernation) files: its awake marker, cold marker and Caddy access log. A failed or cancelled run retains its removal intent. Start a fresh normal `instance:destroy` Operation with a new idempotency key to converge the same admitted removal. Repeating the old key returns the old Operation. This workflow does not depend on `workflow resume`: pinned e459 loses inline official SSH global arguments when resuming a failed run.

A retry repeats every step; each converges, and a checkout that is already gone counts as removed. If a step fails after the Route withdrawal, the site stays down until a rerun finishes the removal.

The Gateway records the member set as Removing in the same admission transaction that captures database ownership. Database saves refuse additions, ownership changes and attachments involving a Removing owner. An owned database targeted by another Instance refuses removal. Only owned resources go with an Instance; unrelated checkouts and database targets remain untouched. Registered external databases lose their records without a backend absence claim.

## Available placements

This parent supports development Instances on managed app-dev Nodes, including their native Process, Schedule and Route withdrawal children. Owned SQLite, managed server databases and registered external database records are supported. Each server child reads the server's admin login from the server's own vault key; the database intent records no password. Production Instances and TaskVM-owned workspaces return `operation.kind_unavailable` before admission. TaskVM workspace readiness belongs to the shared `task-vm-workspace` child and its owning provision parent; an ordinary development checkout has no fabricated TaskVM identity.

Repository reads use a fresh scoped GitHub App credential or normal anonymous access. Anonymous access requires GitHub to confirm the repository is public; otherwise credential preparation fails with the [source credential error](/reference/github-app#instance-source-credentials) naming the App and repository. A non-forced removal that requires `gh_cli` source access refuses before admission; forced removal needs no fetch or GitHub mint. The Gateway mints the read token when the run starts and pushes it to the vault; no step calls the Gateway for it.

## Processes and Schedules

Admission removes each admitted Schedule's native workflow, and the run removes each Process before database and source removal. Their records remain until the parent reports success. 

## Keep unfinished transfer history

Removing an Instance preserves closed transfer history by clearing its Instance association after native success. An unfinished transfer must be settled by its owning workflow first. The Gateway does not infer cleanup from a missing Instance or start a child removal Operation.

## Removal states and retained history

A removal is `removing`, `failed`, `completed` or `abandoned`. Completed removal requires the recorded members to be deleted. Abandoned removal retains every member and its source inventory; it never records a deletion that did not occur.

The schema permits abandonment only from failed removal while its members still belong to removing Instances. Each member receives `abandoned_at`. The terminal header requires every retained member to have that timestamp and requires the current step, failed step and error code to be cleared. Abandoned history is immutable.

Live members have neither `row_deleted_at` nor `abandoned_at`. Destroy admission, the Instance relation, Instance JSON and site rendering use this one rule. A later destroy captures a new removal identity and inventory.

`instance:restore` is the one command that abandons a removal; see [restore a failed removal](#restore-a-failed-removal). Existing removing, failed and completed records keep their meaning. The migration preserves existing rows and triggers. It is forward-only: rollback refuses because the previous schema cannot represent abandoned history.

## Restore a failed removal

[`instance:restore`](/cli/instance/restore) undoes a failed removal of a host development Instance when nothing of it was deleted. A removal that withdrew the Route and then failed at its databases leaves this state: the Instance `removing`, the Route `retiring`, the checkout and databases intact.

The Gateway refuses before any change in these cases:

- a removal run has not ended. A timed-out run with no proven end refuses too; restore never settles it.
- a step that deletes data ran. These are the source, container, Processes, teardown and SQLite steps.
- a run has no record that it skipped such a step that the removal planned.
- the removal saved a Schedule or a SQLite file.
- the Route, its target or the reported checkout changed.

The run checks that each planned database exists and accepts connections. Restore settles no fence: a database that the removal closed and nobody opened again refuses with `instance.restore_database_closed`. The run then republishes the same Route through the Route create workflow, with the same Route ID and domain. Last, it deletes the fence journal rows whose OID and name match a planned database, in one server transaction that checks again that each is open. The Gateway then records, in one transaction, the members and removal `abandoned`, the Instance `active` and the Route `active`.

A restore holds the Instance's Operation lock and the Route's publication Nodes like Route create. `instance:destroy` refuses while a restore holds the Instance, and a restore refuses while a destroy runs. A failed restore leaves the failed removal as it was.

