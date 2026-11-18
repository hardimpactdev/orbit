---
title: "Instance removal"
description: "How Orbit removes an Instance, what --force changes for development source, how owned Processes and Schedules go with it, how to retry an interrupted removal, and how to unregister a host checkout and keep its folder."
covers:
  - apps/gateway/app/Actions/{Instances/{ProjectDirectInstanceDestroyAction,StartInstanceDestroyAction,StartInstanceUnregisterAction},DatabaseConnections/PrepareOwnedDatabaseDestructionAction}.php
  - apps/gateway/app/Domain/Instances/{InstanceRemovalStatus.php,InstanceRemovalStep.php,InstanceCreationRecovery.php,DevelopmentInstanceDestroyProjection.php,InstanceDestroyWorktrees.php,ContainerInstanceDestroy.php,ContainerDestroyExecutionCopies.php,DirectInstanceDestroyIntent.php,InstanceRemovalKind.php,InstanceUnregisterProjection.php,Removal/**,InstanceRemovalReaper.php}
  - apps/gateway/app/Console/Commands/ReapFailedInstanceRemovalsCommand.php
  - apps/gateway/app/{Http/Requests/Instances/RemoveInstanceRequest,Models/InstanceRemoval,Models/InstanceRemovalMember}.php
  - apps/gateway/database/migrations/{2026_12_03_000000_allow_cold_recipe_image_rebuilds,2027_03_01_000000_add_kind_to_instance_removals,2027_03_02_000000_allow_ci_only_proof_copy_removals,2027_03_04_000000_add_reaper_retries_to_instance_removals,2027_03_05_000000_add_start_refusal_to_instance_removals}.php
  - apps/cli/app/Commands/Instances/{DestroyInstanceCommand,UnregisterInstanceCommand}.php
  - swamp/{workflows/workflow-operation-instance-destroy.yaml,workflows/workflow-operation-instance-unregister.yaml,ansible/instance-container-destroy.yml}
  - swamp/ansible/instance/destroy-git.py
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

Only a confirmed dependency or a target read failure refuses. The target’s directory, pointer or Git failure names its path and the unreadable or timed_out cause. Git discovery stops at the checkout's parent, so an unreadable nested checkout cannot borrow the enclosing repository's Git identity.

Candidate Git reads share one three-second allowance; later candidates use pointer reads once it is spent. No read is retried. Admission warnings survive in the Operation result. As in transfer, human output names the warning code, path, Instance IDs and read cause; JSON keeps the typed warning. The Gateway inventory excludes containers and empty or relative paths; malformed candidate rows are bounded warnings, never a whole-call failure.

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

A container Instance follows the [container destroy flow](/reference/development-containers#destroy). A non-default container Instance, cloned or migrated, requires `--force`, skips work checks and teardown, and deletes the container with its Processes and owned network state, and its [inner volume](/reference/development-containers#the-inner-volume) when it has one. It deletes only its own UFW rules, as [Destroy](/reference/development-containers#destroy) describes.

The steps of a container removal take one of 64 names per Node: `<family>-<node>-<slot>`, where the slot is the Instance's ID modulo 64. The families are `id-input`, `route-hold-release-withdrawn`, `id-delete-the-container`, `id-read-container-removal` and `id-clean-container-removal-files`, and the hibernation files take `instance-destroy-hibernation-<node>-<slot>-<index>`, one name each, so they go side by side. A Node has at most 512 of these names. The steps that drop its owned server databases take `database-destroy-ssh-<database node>-<node>-<slot>`; a `database:destroy` of its own keeps `database-destroy-ssh-<database node>`.

Swamp fails a step that waits 60 seconds for a name another run holds, and "Delete the container" holds its name for 20 to 60 seconds under load. So the Gateway starts a removal only when no older removal of its slot on its Node is open ([One Route write per Node](/reference/operations#one-route-write-per-node)). Clones made together have consecutive IDs and take different slots.

Both removal kinds, `instance.destroy` and `instance.unregister`, use the slot. An unregister names its `route-hold-release-withdrawn` and `instance-destroy-hibernation` steps as destroy does, so an unregister and a destroy of one slot on one Node wait for each other with `instance_slot`, in either order.

The container removal step runs one Ansible playbook with the shipped `ansible.cfg` over one pipelined SSH master connection. Without the master, each of its 11 remote tasks opened six SSH logins, and the step took 27 s against 1.5 s for `incus delete --force` itself. The Instance named `default` stays unavailable, except a nested-fleet trial default that [Destroy](/reference/development-containers#destroy) admits. Conn removes its environment and pairing records after the T3 server is gone.

A removal from Conn while a clone's [completion](/cli/instance/complete) runs records the completion's cancel and is admitted `waiting`; it starts when the completion has ended ([Remove a clone whose completion runs](/reference/conn#remove-a-clone-whose-completion-runs)). A removal from the CLI or the core API refuses with `operation.target_busy` while the completion holds the Instance. A completion that is filling the clone's database takes the cancel only when the fill has ended, so the removal does not drop a database that a copy still writes ([the cases not covered](/reference/conn#remove-a-clone-whose-completion-runs)).

A removal while the Instance's create runs is accepted from every caller: it records the create's cancel and is admitted `waiting` behind it ([Remove a clone whose create runs](/reference/conn#remove-a-clone-whose-create-runs)).

Destroy also deletes each create's execution copies. A clone's create has two: the clone copy, with its credential line and model environment, and the one-line launch copy, with the release scripts beside the same private text. The destroy also deletes the bare token and model key copies that release 2084 wrote.

Destroy also deletes migration execution copies, including the remote-read credential, and the create copies of the owned database and of the clone's own Valkey user, which hold their server and owner logins. It deletes the copies of each [completion](/cli/instance/complete) too: its Process files and its Route material.

Destroy also deletes the [execution copies](/reference/deployments#execution-copies) of each deploy of the Instance, as each deploy does when it ends. For a task copy, destroy also deletes the rendered `.env` that each [publish](/reference/tasks#task-copy) of the copy received. For a proof copy, it deletes the development deploy step commands that each [`proof:run`](/cli/proof/run#the-run) run in the copy received. The clone's Valkey user is deleted with its owned databases; the default's Valkey user stays. The runtime row stays until success, so its address and port stay reserved during a failed removal.

Destroy removes the Instance's folder for pending mail activation from the Node, `/var/lib/orbit/instance-mail/<container>`, its [asleep marker](/reference/development-containers#sleep-and-wake), so no later Instance or domain finds it, and, through the power helper's `discard`, its plan, limits record, target descriptor and fast wake files ([Lifecycle](/reference/prepared-restore#lifecycle)). The discard waits for a helper call still at work on the Instance. It also removes a task copy's base scratch, `/var/tmp/orbit-task-base-<id>`, the bundle a cancelled [`task:sync`](/reference/tasks#bring-main-into-a-copy) may leave; it holds no credential. A sleeping container is deleted as it is, without a wake. Its shared bridge endpoint stays for ordinary host reconciliation, as database endpoints do.

A proof copy's destroy then releases its CI place, once the container is gone: when no other hold is left on the Node, the runners that the holds stopped start again ([Proof places on a CI Node](/reference/node-provisioning#proof-places-on-a-ci-node)).

## Retry and ownership

The Gateway captures the run budget at admission from the teardown timeouts, the Routes it withdraws and its owned databases; see [Instance setup](/reference/instance-setup#run-setup). Each withdrawn Route adds 330 seconds, so the run outlasts the 300-second [Node transaction](/reference/routes#coordinate-publication) of its withdrawal.

Before it removes the source, the run removes each member's [hibernation](/reference/app-dev-runtime-hibernation) files: its awake marker, cold marker and Caddy access log. A failed or cancelled run retains its removal intent. Start a fresh normal `instance:destroy` Operation with a new idempotency key to converge the same admitted removal. Repeating the old key returns the old Operation. This workflow does not depend on `workflow resume`: pinned e459 loses inline official SSH global arguments when resuming a failed run.

A retry repeats every step; each converges, and a checkout that is already gone counts as removed. If a step fails after the Route withdrawal, the site stays down until a rerun finishes the removal. A withdrawal that held frees the Route publication hold at once and records `route_withdrawn_at` on the removal ([One Route write per Node](/reference/operations#one-route-write-per-node)). A retry after that skips the withdrawal and takes no hold.

Each container removal step treats a part that is already gone as done. A retry then finishes a removal whose earlier run, or a person, already removed some of it:

- The step asks Incus to delete the container, then the inner volume. A normal removal needs nothing more.
- Incus refuses to delete a container or inner volume record whose ZFS dataset is gone. Instance 3427 stayed `removing` for 8 hours that way.
- After a refusal, the step makes an empty dataset and empty snapshots with the record's own names. Incus can then delete the record.
- It builds each name only from the removal's recorded pool, Incus project and container or volume name. It never runs `zfs destroy`.
- Right before it makes them, it reads each missing name again with its children.
- A dataset that holds the name by then refuses the removal with `instance.removal_dataset_taken`. Nothing is made or deleted, so the step never adopts another dataset.
- The Operation and the removal record that code, and the reaper leaves the removal to a person.

After a refusal, the step deletes the container again, and the inner volume by name: each of its snapshots, then the volume. A refusal for any other cause fails the second deletion, with Incus's stderr. A copy's inner volume can be a clone of a snapshot of a volume that Incus already deleted. Incus keeps that origin under `deleted/` for the clone. The removal never names the origin or another Instance's volume. A missing ACL, firewall rule, mail folder, marker or mirror needs no action.

The end check `Check the container and volume are gone` stays strict: it reads the container, its root volume and the inner volume by this Instance's names.

A container removal stops the container before its databases drop: `Stop the container` runs `incus stop --force` before `Drop owned databases`. Its workers and every other client in it then hold no session on a planned database. A container that is stopped or gone already counts as stopped. The step takes the deletion's slot name, so a Node keeps its 512 names.

A session or lock on an owned server database still makes its drop refuse before any `DROP`, so the end check `Check they are gone` finds every name. Such a client is outside the container, such as a person's database tool. The removal then fails with the database child's own code at the child's step: `database.in_use`, `database.server_admin_failed` or `database.destroy_incomplete`, not `instance.removal_incomplete`. Orbit never disconnects the clients. The [removal reaper](#the-removal-reaper) runs the removal again once they are gone.

Known limit: an Instance with databases on several servers runs one database child per server, and the run data keys their steps by name only. The removal's code then comes from the last server's child, so a refusal on one server can be reported as `database.destroy_incomplete` of another.

A container removal step that fails writes the failed task, its message and the end of its stderr into its result file. When the stderr is longer than the room, it keeps whole lines only: the text up to the first newline of the kept end goes, so no half line, such as a cut secret, escapes redaction. Swamp keeps no stderr for a failed command step, so the Gateway takes this text instead. It records the text in `error.detail` with `error.stderr_recorded: true`, redacted and cut to the last 4 KiB like the stderr of other Operations ([Operations](/reference/operations)).

When the removed Instance is a [task copy](/reference/tasks#task-copy), the successful removal also releases its task before the row goes: a task that still runs is `cancelled` with its open subtasks, and an ended task only lets go of the copy. A failed removal leaves the task bound to the copy.

The Gateway records the member set as Removing in the same admission transaction that captures database ownership. Database saves refuse additions, ownership changes and attachments involving a Removing owner. An owned database targeted by another Instance refuses removal. Only owned resources go with an Instance; unrelated checkouts and database targets remain untouched. Registered external databases lose their records without a backend absence claim.

### The removal reaper

A failed removal does not wait for a person. Every five minutes, without overlap, the Gateway's `orbit:instance-removal-reap` finds each `failed` removal of kind `destroy`. It starts that removal again through the same admission as `instance:destroy`, with the same removal and the same `--force`. The Operation records `triggered_by: orbit:instance-removal-reap` and the idempotency key `removal-reaper:<removal>:<count>`, so two passes never start two runs.

The reaper waits after each failure: 5 minutes, 15 minutes, 1 hour, then every 6 hours. It counts from the removal record's last change, which a failed run and a refused start both make. A failed removal that no Operation carries is retried too, counted from the same change. The removal record keeps the count in `retry_count`. A start that admission refuses counts as a failed retry, so a removal that admission refuses for good becomes stuck too.

A refused start is never only in the Gateway log. The removal record keeps the refusal's error code in `start_refusal`. The Gateway records one Activity entry `instance.removal_retry_refused` with that error code, and Doctor reports `instance.removal_retry_refused` on the Instance with the code. A start that fails with another error counts and shows the same way, with the code `instance.removal_retry_failed`. The next admitted start clears the code, and the issue goes.

A removal that holds no saved intent, such as an older one from before the intent was kept on every removal, cannot start again: admission replays the saved intent and refuses with `instance.removal_conflict`. The reaper never starts such a removal. On its first pass it records `instance.removal_intent_missing` and reports the removal stuck at once.

The operator turns the reaper off with `ORBIT_INSTANCE_REMOVAL_REAPER=false` in the Gateway's environment; its scheduled pass then does nothing. It is on by default. A HOLD on Operations is a convention between people, not a Gateway setting: use this switch to stop new reaper starts during one.

The reaper starts nothing when:

- no removal was ordered, or the Instance is not `removing`;
- the removal is an unregister, or is `abandoned` or `completed`;
- the Instance has an open transfer;
- an Operation of the Instance runs, or one holds the Instance lock, such as a create.

After 3 failed reaper starts, Doctor reports `instance.removal_stuck` on the Instance in the `instance` family. It reports it at once for a removal that failed with `instance.removal_dataset_taken` or that holds no saved intent. The issue names the failed step and the error code, and the last refused start's code if there is one. The Gateway also records one Activity entry `instance.removal_stuck`. The reaper keeps trying every 6 hours, except a removal with `instance.removal_dataset_taken`: it never starts that one again, because a person must look at the dataset first. The issue goes when the Instance is gone.

## Available placements

This parent supports development Instances on managed app-dev Nodes, including their native Process, Schedule and Route withdrawal children. Owned SQLite, managed server databases and registered external database records are supported. Each server child reads the server's admin login from the server's own vault key; the database intent records no password. Production Instances and TaskVM-owned workspaces return `operation.kind_unavailable` before admission. An ordinary development checkout has no fabricated TaskVM identity.

A [proof copy on a CI-only Node](/reference/node-provisioning#proof-copies-on-a-ci-node) is a development Instance too, so its removal takes the same path. The database's removal contract follows the same placement rule: it admits a development removal on a Node with an `app-dev` role, or on a Node with a `ci-runner` role that is not being removed and no app role.

Repository reads use a fresh scoped GitHub App credential or normal anonymous access. Anonymous access requires GitHub to confirm the repository is public; otherwise credential preparation fails with the [source credential error](/reference/github-app#instance-source-credentials) naming the App and repository. A non-forced removal that requires `gh_cli` source access refuses before admission; forced removal needs no fetch or GitHub mint. The Gateway mints the read token when the run starts and pushes it to the vault; no step calls the Gateway for it.

## Processes and Schedules

Admission removes each admitted Schedule's native workflow, and the run removes each Process before database and source removal. Their records remain until the parent reports success. 

## Keep unfinished transfer history

Removing an Instance preserves closed transfer history by clearing its Instance association after native success. An unfinished transfer must be settled by its owning workflow first. The Gateway does not infer cleanup from a missing Instance or start a child removal Operation.

## Unregister a host checkout

[`instance:unregister`](/cli/instance/unregister) is the record-only inverse of `instance:register`. It removes the same records as `instance:destroy` and the Node effects that would otherwise stay behind without an owner. It has no source effect: the checkout folder and every file in it stay, Git data and `.env` included.

It is its own Operation kind, `instance.unregister`, with its own workflow, `operation-instance-unregister`. No existing workflow reads a new intent key. `POST /api/v1/instances/{instance}/unregister` admits it and answers HTTP 202 with the Operation. The MCP tool is `instance-unregister`, marked destructive.

The Gateway refuses before any change, with HTTP 409:

- a container Instance, with `instance.unregister_container`. Its container is its source, so only `instance:destroy` removes it.
- an Instance that owns a database on a Database server, with `instance.unregister_owned_database`. Unregister must neither drop it nor leave it without an owner.

A registered external database and a SQLite file do not refuse. Orbit deletes their records, and a SQLite file stays in the folder.

A production Instance refuses with `operation.kind_unavailable`, as for destroy. The other admission checks are those of destroy that concern records, the Route and the runtime: the access grant, an active Instance, no other lifecycle Operation, open transfer or migration, Reverb attachments, analytics tracking hosts and the Route checks.

Destroy's source checks do not apply: there is no worktree dependency read and no GitHub source access check. The recorded source layout and checkout path checks stay, because admission shares destroy's snapshot; they read records only. Destroy has no rule for the default Instance of a host checkout, so a default unregisters like any other.

Admission removes the Schedule triggers, then records the removal, marked as an unregister, with the Instance `removing` and the Route `retiring`. It holds the Route's Nodes as destroy does.

The run reuses destroy's steps and their children:

1. `Withdraw the Route`: the `route-withdraw` child, side `forward`. An Instance without a Route skips it.
2. `Release the Route Nodes`: only after the withdrawal held.
3. `Stop, remove Processes`: the `operation-process-destroy` child for each Process.
4. `Remove hibernation files`: the awake and cold markers and the Caddy access log.
5. `Check units gone`: each removed unit file and hibernation file is absent. This is the end check.

`Check units gone` uses the per-Node name `instance-destroy-ssh-<node>` of destroy's SSH steps, not a slot name. It can therefore wait behind a host destroy on the same Node, and Swamp fails it after 60 seconds. The removal then fails with `instance.removal_incomplete`; a rerun of `instance:unregister` completes it.

The run holds no source path, teardown step, SQLite path, database, git credential or token. Its intent names the Route withdrawal, the Processes, the hibernation files and `check_paths`: the unit and hibernation files that the end check finds absent. There is no record-only path without a run: destroy has none either, and every unregister runs the workflow. The run budget is destroy's: 60 seconds, 330 seconds for each withdrawn Route, and 180 seconds when the Instance has database records.

The workflow declares the intent as an opaque object, as destroy does. Swamp refuses null in an input property that the schema declares, and the intent holds null for an Instance without a Route and a Process without Vite; with an opaque intent those runs start.

On success the Gateway deletes the Instance through the same record projection as destroy: the Instance with its Route, Processes, Schedules, auto-deploy, environment and database records. A failed run keeps every record and marks the removal failed. A fresh `instance:unregister` converges and skips a withdrawal that held. The removal records its kind, `destroy` or `unregister`, in `instance_removals.kind`. A removal keeps its kind: `instance:destroy` and `instance:restore` refuse an Instance whose unregister failed, and `instance:unregister` refuses one whose destroy failed, each with `instance.removal_conflict`.

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
