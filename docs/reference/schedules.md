---
title: "Schedules"
description: "How the Gateway stores a Schedule as its own Swamp workflow with a cron trigger, records each fire as a task, and removes it."
covers:
  - apps/gateway/{app/{Actions,Domain}/Schedules/**,tests/Feature/Domain/ScheduleValidationTest.php}
  - apps/gateway/app/Infrastructure/Schedules/**
  - apps/gateway/app/Data/Schedules/**
  - apps/gateway/app/Http/{Controllers/Api/Schedule*,Requests/Schedules/*}.php
  - apps/gateway/{app/Models/Schedule.php,database/migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php,database/migrations/2027_01_10_120000_add_native_trigger_to_schedules.php}
  - apps/gateway/app/{Infrastructure/Swamp/SwampScheduleWorkflows.php,Jobs/Operations/ProjectSwampScheduleFire.php}
  - packages/php-sdk/src/{Requests,Responses}/Schedules/**
  - swamp/workflows/workflow-task-command-run.yaml
---

# Schedules

[`schedule:logs`](/cli/schedule/logs) returns retained journal lines. `--follow` refuses until the fixed Agent release is pinned; see [Schedule logs](/cli/schedule/logs).

A Schedule runs one command on a calendar for one Node or one Instance. The Gateway stores the Schedule. Swamp serve on Node 209 holds one small workflow per Schedule, `schedule-<uuid>`, with the Schedule's cron trigger and its intent fixed inside. Each fire runs the shared `task-command-run` workflow, which reaches the Node over SSH. Nothing is installed on the Node (ADR 0244). macOS is not a Schedule target. A Schedule mutation there returns `schedule.platform_unsupported` (HTTP 422).

Per-minute schedulers on production Instances, such as `laravel-scheduler` and `powersync-health`, keep a systemd timer on their Node until the serve load and outage test passes. The calendar's form picks the path: a cron expression makes a native Schedule, and a systemd calendar keeps the [Node timer](#node-timers). Production preparation copies each Project definition this way; a native copy starts active and disabled, and its workflow is written when it is first enabled. [`schedule`](/cli/schedule) lists the commands. [Processes and schedules](/reference/processes-and-schedules) describes Project Schedule definitions and their Instance copies.

An Orbit Schedule is separate from the Gateway's Laravel schedule. Swamp owns [observation schedules](/reference/gateway-queue#scheduled-work), including Doctor collection, Horizon health and operation recovery. The Gateway scheduler keeps its own self-maintenance: Activity finalization and cleaning, Horizon metrics snapshots, batch and failed-job pruning, and the minute [reconciler](/reference/swamp-command-steps#reconciler) that settles Operations with their Swamp runs. Hourly `problems:file` stays in the Gateway scheduler with its lock.

The shared native `automatic-production-deploy` producer schedules [automatic deployments](/reference/deployments#automatic-development-defaults) at second 30 of each minute, subject to its enabled and paused policy setting. The PHP `orbit:auto-deploy` command and schedule are removed. This producer installs no timer on an app-dev Node. Every five minutes Swamp also reads the Gateway endpoint that resolves the [desired fleet state](/reference/self-update#how-the-gateway-resolves-it) so the CLI release check never waits in a request.

While task VMs are enabled, the Gateway queues the [task VM host sweep](/reference/compute-drivers#sweep-task-vm-hosts) every 30 minutes. It releases stale Incus starts through `task_vm.slot_release`. Every five minutes it also queues the [idle release](/reference/tasks#release-an-idle-task-vm) of task VMs.

The Gateway also queues the [refresh of the base snapshots](/reference/compute-drivers#refresh-the-base-snapshot) of the Linux task VM hosts every day at 03:20 while task VMs are enabled. A host with a refresh open returns it.

While the [fleet rollout](/reference/gateway-recovery#fleet-rollout) is on, the Gateway queues its admission every day at 03:05 Europe/Amsterdam, when the window of the non-daytime Nodes opens. A verified release queues the same admission. The job waits a minute at a time while the commit has no CLI release yet or a rollout runs.

Every five minutes the Gateway also frees the target of a timed-out Operation that nothing has proved ended for 60 minutes. See [Operations](/reference/operations).

No schedule or release step builds a recipe image: the [base snapshot refresh](/reference/compute-drivers#refresh-the-base-snapshot) is the only way a host gets a new base.

The Gateway also attempts `project-documents:probes:reconcile` once per minute without overlap. The [documents extension](/reference/extensions) registers this entry and the cleanup worker below from its provider, and both run whether or not its switch is on. It processes a finite batch from the private probe journal and permanently retains each cleanup record for late PUT recovery. This is an internal attempt schedule, not a promise of object lifetime or a Schedule on a Node. [Reserved probe recovery](/reference/project-documents#recover-reserved-probes) defines its bounds, backoff, repair, and separation from the gate for document-body cleanup.

The Gateway runs `orbit:vault-sync` every minute without overlap while the `vault` [extension](/reference/extensions#the-runtime-switch) is on. It does nothing until [Vaultwarden](/reference/vaultwarden#sync) is set up, and it contacts Vaultwarden only when a credential changed or the hourly check is due.

The Gateway attempts `project-documents:cleanup:work` every five minutes without overlap. Each run processes at most 100 due document-body cleanup records, and only while the restore-time gate is running. See [Project Documents](/reference/project-documents#publish-and-recover) for authorization, durable claims, retry bounds, and permanent abandoned-upload fences. This worker is separate from reserved-probe reconciliation.

The Gateway prunes failed queue jobs, batch metadata, and Activity older than 30 days daily. [Gateway disk retention](/reference/gateway-recovery#disk-retention) describes the configurable cutoffs.

## Execution

Retained Schedule log reads and Node-timer account resolution read the Node's stored [`accounts` fact](/reference/node-agent#facts) while its agent is online. An absent or non-current fact, an unlisted user, or an invalid account entry returns `schedule.target_unavailable`. Account resolution contacts no Node.

Create, enable, disable and destroy touch no Node. The Gateway changes the Schedule's workflow or trigger on serve, waits for serve to confirm, and answers at once: HTTP 202 with an Operation that has already succeeded (R1). When serve does not confirm, the request fails with `swamp.schedule_publish_unavailable` (503) and records no Operation; repeat the same request. A manual run starts `task-command-run` and returns its running Operation with HTTP 202. An Idempotency-Key replays the original Operation.

An [Instance transfer](/reference/instance-transfer) moves the Instance's Schedules. It removes their triggers when its run starts. When the run ends, the Gateway rewrites each published workflow for the recorded Node and sets the trigger again if `desired_timer_state` is `enabled`.

The SDK exposes the Operation through `ScheduleMutationResponse.result`; the CLI follows the HTTP 202 Operation, which has already ended for create, enable and destroy. Schedules stay immutable. `schedule:update` changes only a Project definition in Gateway state.

## Fields

The Schedule UUID is its public identity. It is also the only value that names the host artifacts.

| Field | Contract |
| --- | --- |
| `id` | An immutable UUID. |
| `target_type`, `target_id` | Exactly one `node` or `instance` target. |
| `name` | Unique within the target. 1 through 63 lowercase ASCII letters or digits, with hyphens only between them. |
| `calendar` | A cron expression that Swamp accepts, such as `0 3 * * *`, `20 * * * * *` or `@daily`. |
| `command` | One non-empty UTF-8 line of at most 4,096 bytes, without NUL, carriage return, or line feed. |
| `timeout_seconds` | 1 through 86,400. The default is 3,600. |
| `desired_timer_state` | `enabled` or `disabled`: whether the Schedule's trigger is set. |
| `status` | `provisioning`, `active`, `failed`, or `removing`. `failed_step` and `error_code` name a failure. |
| `last_run_at`, `last_run_status` | The end of the latest fire or manual run, and `success` or `error`. Both are null before the first run. |

The calendar has 5 fields, or 6 with seconds first, or is a shorthand such as `@hourly`. It fires in serve's time zone (UTC), because a trigger carries no time zone: serve drops a `timezone` key and refuses a `CRON_TZ=` or `TZ=` prefix, and the Gateway answers that refusal with `schedule.calendar_invalid` (422). A [Node timer](#node-timers) Schedule keeps a systemd calendar.

A Schedule has no edit operation. An identical create returns the existing Schedule and keeps its desired timer state. A different specification with the same target and name returns `schedule.retry_conflict` (409). To change a Schedule, destroy it and create it again.

## API

Every endpoint is under `/api/v1/schedules` and needs an access grant to the Node that owns the target. For an Instance target, that is the Instance's Node.

| Operation | Request | Result |
| --- | --- | --- |
| List | `GET /api/v1/schedules` | The Schedules whose target Node the caller may address, ordered by name, without `command`. |
| Create | `POST /api/v1/schedules` | Records one Schedule, writes its workflow and trigger, and returns its succeeded Operation with HTTP 202. |
| Show | `GET /api/v1/schedules/{uuid}` | One Schedule, with `command`. |
| Run | `POST /api/v1/schedules/{uuid}/run` | Starts one `task-command-run` and returns its Operation with HTTP 202. The trigger does not change. |
| Logs | `GET /api/v1/schedules/{uuid}/logs?lines=N` | The newest observed Schedule journal lines. |
| Enable | `POST /api/v1/schedules/{uuid}/activate` | Sets the trigger of a Schedule and returns its succeeded Operation with HTTP 202. |
| Disable | `POST /api/v1/schedules/{uuid}/disable` | Removes the trigger of a native Schedule and returns its succeeded Operation with HTTP 202. |
| Destroy | `DELETE /api/v1/schedules/{uuid}` | Removes the trigger and the workflow, deletes the record, and returns its succeeded Operation with HTTP 202. |
| Complete | `POST /api/v1/schedules/{uuid}/complete` | A Node timer reports a run result. Returns 204. |

Create takes `target_type`, `target_id`, `name`, `calendar`, and `command`, and optionally `timeout_seconds` and `start`. `start` defaults to `true`. A Node or Instance target can start disabled, without a trigger. Run, enable, and destroy take an empty body. The Gateway refuses unknown, duplicate, or wrongly typed members with 422 before it changes anything.

Each Schedule API request except Complete records one operator Activity entry. The entry names the Schedule and its target, including a destroy request that admits an Operation. It never holds the command, calendar, journal lines, paths, users, or unit text. Executor requests have their own worker audit entries.

## Execution context

The caller picks only the target. The Gateway derives the host Node, the user, the working directory, and the shell from the target's placement.

| Target | User | Working directory | Shell |
| --- | --- | --- | --- |
| Node | The Node's managed user | That user's home | The user's login shell, with login mode and protected stdin |
| Instance on `app-dev` | The Node's managed user | The Instance's application directory in its checkout | The user's login shell, with login mode and protected stdin |
| Instance in a development container | The Node’s managed user, `orbit` or `agent` | The application directory under `/srv/workspace` | `/bin/bash`, with login mode and protected stdin |
| Instance on `app-prod` | The Instance's production user | The Instance's application directory under `<production-home>/current` | `/bin/bash -s`, without a login |

For Laravel, the [application directory](/reference/projects#application-directory) is the effective web root without its trailing `/public`. With root `apps/site/public`, a Schedule runs in `<checkout>/apps/site` on `app-dev` or `<production-home>/current/apps/site` on `app-prod`, so `php artisan schedule:run` finds that app's `artisan` and `.env`. Root `public` keeps the checkout or release root. Node Schedule working directories do not change. Instances that are not Laravel apps keep their checkout or release root.

The target Node must be an active Linux Node with a WireGuard address. An Instance target must be active. A production Schedule resolves `current` each time it runs, so a new release changes later runs. A production Instance needs a selected release before it can take a Schedule, even one that starts disabled.

A Schedule does not follow its target to another Node. While a Schedule exists, the Gateway refuses to remove its target Node or host Node with `schedule.target_in_use` (409). [Instance transfer](/reference/instance-transfer) does not check Schedules. After a transfer, list and show still work, and an identical create returns `schedule.target_in_use`. Run, logs, enable, and destroy fail with `schedule.target_unavailable`, because the Instance runs on another Node than the host Node. Destroy the Instance's Schedules before a transfer. Instance removal removes the Instance's Schedules itself.

## Checkout migration

[`instance:migrate`](/cli/instance/migrate) carries registered Node-timer Schedules in the same Operation as the Instance. The timer stays on the Node, with its UUID and calendar. Its replacement service triggers the existing Schedule run path, which enters the Instance container and runs under the same supported user. Working directories and command paths inside the checkout become `/srv/workspace`. Timeout, enabled or paused intent, latest-run fields and existing Operations remain. `schedule:logs` keeps reading the Node journal under the same Schedule identity; later command output is recorded there too.

Before downtime, Orbit refuses a Schedule that cannot run in the container, naming its UUID, name and reason. A native Swamp Schedule has a fixed workflow and must be removed before migration. Node-timer files must belong to their Schedule. Unsupported accounts, shells, external working directories and command paths refuse. Orbit resolves the working directory and literal command paths before checking containment. Symlinked paths refuse because their targets may still name the old checkout after copying.

Migration disables the source timers and stops their services before copying. Failure before cutover removes the failed container before restoring the timers' captured enabled and active states; source files remain unchanged. After cutover, only the admitted Schedules’ services are replaced and their obsolete command and callback files are removed. Their timers remain on the Node. Schedule cutover precedes Git retirement, so a late retirement refusal leaves the moved Schedules running from the container.

A timer fire received while migration holds admission is recorded as a waiting Schedule Operation with the timer’s idempotency key. After cutover or rollback, Orbit rebuilds its command context from the final runtime and queues it after the migration transaction commits. Cancelling migration before it starts, or expiry before it starts, frees its new reservation and releases waiting fires against the checkout. An uncertain start keeps its reservation. Waiting time does not consume a fire’s run budget. If rollback fails and admission stays closed, the deferred fire records a failed Operation instead of disappearing.

The command must be one literal invocation of `php`, `node`, `bun`, `vp`, `composer`, `git`, `true`, `false`, `echo`, `printf` or an executable inside the checkout. Arguments may be whole quoted strings. Assignment prefixes refuse. PHP and Node need a direct script operand, even when its name has no extension; options before that operand refuse. Bun needs an explicit script path containing `/`; ambiguous subcommands refuse. PHP and Composer need a selected PHP build in the container runtime. An optional `> /dev/null` or `>> /dev/null` may end the command, followed by `2>&1`. Shell operators, patterns, expansion, inline evaluation and executable configuration flags refuse; Orbit does not interpret a shell program to guess which paths it uses.

### Why the timer stays on the Node

Containers cannot reach the Gateway. A timer inside the container could not use the existing Schedule trigger, and would need a new privileged bridge or a change to container isolation. Keeping the trigger on the Node reuses the existing Schedule Operations and the same Incus execution path as container Processes, without a second completion or log mechanism.

## The Schedule workflow

Create writes the command to the vault key `schedule-command-<uuid>` in `orbit-control-inputs`, then creates `schedule-<uuid>` with `workflow.create` and writes its content with `workflow.edit`. The workflow has one step, `Run the Schedule`, that calls `task-command-run` as a nested workflow. Its inputs are fixed: the Schedule UUID, the command hash, the user, group, home, shell, working directory, login mode and timeout, the Node's SSH address and host keys, and the vault key name. The command itself never enters the workflow, its inputs or its output. Its tags name the Schedule, its Node and its target, so the Gateway can record a fire after the Schedule is gone.

Serve stores the workflow as an untracked file in its `workflows/` directory beside the checked-in workflows. A release load checks out the next commit and keeps it, and so do a serve reload and a restart; a fresh clone or `git clean` would drop it. The Gateway's record stays the source: an identical create writes the workflow and its trigger again. Changed SSH host keys reach the workflow only through destroy and create.

The trigger is the workflow's trigger override on serve, set with `workflow.trigger.set` and removed with `workflow.trigger.remove`. The Gateway's credential needs run, read and write on `workflow:schedule-*`.

## Each fire

Serve fires the trigger and starts one root run of `schedule-<uuid>`. Serve runs a limited number of scheduled runs at once, and a Schedule never overlaps itself, so a fire can start late, and a fire that is due while the last one runs waits. Commands must allow for a late or extra run.

`task-command-run` has one step, `Run the command`, using official `@swamp/ssh` exec argv from Node 209. It runs a transient `systemd-run --pipe --wait --collect` unit with the admitted user, working directory and `RuntimeMaxSec`. The unit enforces its user and directory as it starts; no separate context probe or script is shipped.

The command arrives on stdin from `vault.get` and runs in the user's shell, or the Schedule's fixed shell. `systemd-cat` sends output to the Node journal under `orbit-schedule-{uuid}`; it stays out of native history. A failed setup, transport or command records `schedule.run_failed`, with the native failed-step diagnostic. Admission still refuses a missing or moved target with `schedule.target_unavailable`.

A Gateway job asks Swamp once a minute which root runs finished. For each finished `schedule-<uuid>` run it reads the nested command's native history and records one `schedule.run` Operation, `last_run_at` and `last_run_status`. The trigger is `schedule`, and the budget is the Schedule timeout plus 120 seconds. A fire becomes a task when it ends, up to a minute later. An unreadable fire is not recorded. Decided by the orchestrator, for Nick's review: polling replaces the commit feed until a v3 datastore is planned. A manual run starts `task-command-run` directly with its Operation ID. Swamp never reports the result back to the Gateway.

## Enable

Enable sets the Schedule's trigger and stores `enabled` after serve confirms; a repeat sets it again and keeps the existing workflow. A copied Project definition with no workflow gets its command copy and workflow first. That first enable uses the same `schedule-command-<uuid>` vault key as create.

Disable removes the trigger and stores `disabled`; the workflow stays, and a fire that already started finishes. If serve does not confirm, the desired state stays unchanged. A Node timer Schedule refuses enable with `schedule.target_invalid`, because its timer is always on. Production Schedules resolve `current` each time they run, so a new release changes later runs.

## Node timers

A Node timer Schedule owns two public files on its Node, named only by its UUID.

| Artifact | Path | Owner and mode |
| --- | --- | --- |
| Admission service | `/etc/systemd/system/orbit-schedule-{uuid}.service` | `root:root`, `0644` |
| Timer | `/etc/systemd/system/orbit-schedule-{uuid}.timer` | `root:root`, `0644` |

The service sends an authenticated Node request to the Gateway run endpoint. Its systemd invocation ID is the idempotency key. The service contains public execution context and the command hash, without command bytes or credentials. The timer uses `Persistent=true`. Each admitted run uses `task-command-run`.

Definition installation creates the timer files through `operation-schedule-install-instance-definitions`, disabled. Its shared create child checks both unit files, the absence of obsolete files and the captured timer state, then uses a native assertion. The Gateway records readiness from that check rather than a reporting shell. Its native end check confirms every captured member, including an empty set, in the same history read. No parent shell prints a completion marker.

Enable uses `operation-schedule-control` to enable and start the timer, check systemd compliance and enforce it with a native assertion, and destroy uses `operation-schedule-destroy` to check for active work, stop the timer, remove the files and reload systemd. These workflows run on the `swamp` Node (`labels: {role: swamp}`) and reach the Node through official cfgmgmt models and `@swamp/ssh`. A failed step records `operation.native_failed`; completed host actions stay, and a repeat converges. A removal refused with `schedule.run_active` keeps the Schedule in `removing`; retry destroy after the command ends.

## Logs

Logs read only the Schedule's service, through the Node agent's [log stream](/reference/live-logs#one-shot-reads-use-the-stream-path), with a fixed `journalctl --output short-iso --utc` read. `lines` is 1 through 1,000, and the default is 100. The read waits at most 5 seconds and returns at most 256 KiB. The response holds `output` with the newest complete lines, oldest first, and sets `truncated` to `true` when a limit removed older lines. Logs need an `active` Schedule; otherwise the Gateway returns `schedule.state_invalid`. Orbit stores no journal output. The agent and the Gateway redact credential-shaped text and stored environment values, but keep secrets out of command output. The host Node's journal settings decide how long lines stay.

A migrated development Schedule reads both its retained service entries and later command entries from the Node journal. This bounded read uses the existing runtime inspection path, waits at most 120 seconds and keeps at most 4 MiB of UTF-8 bytes. The Node also bounds the JSON-encoded transport below the Gateway’s 8 MiB frame limit, allowing 16 KiB for the response envelope.

The read keeps complete journal entries, including multiline messages and fields longer than 4,096 bytes. Non-UTF-8 messages use replacement characters for invalid bytes; a null message is empty. Either size limit excluding an entry sets `truncated` to `true`. The journal output travels through a private vault copy and receives the same Gateway redaction.

The CLI rejects every `schedule:logs --follow` locally with `schedule.follow_unavailable`, before it makes an API request. A direct API request for a migrated Schedule’s live stream returns `schedule.target_unsupported`. Read without `--follow`.

## Latest run

The Gateway stores only the latest run, from a recorded fire or a manual run's terminal result. A removing Schedule receives no new latest run. Each fire and manual run is also an Operation.

## Remove

Destroy marks the Schedule `removing`, removes its trigger, then deletes its workflow, and deletes the record. Removal addresses the workflow by its stable `schedule-<uuid>` name, so authorization still applies after it is gone. Removing a trigger or a workflow that is gone is safe, so a disabled Schedule takes the same path. A fire that already started runs to its end, and the Gateway still records it. When serve does not confirm, the Schedule stays `removing` with `swamp.schedule_publish_unavailable`; repeat destroy. Destroy also deletes the command's vault key with `vault.delete`; a fire that already started read it when it began.

[Instance removal](/reference/instance-removal) removes each Schedule of the Instance without waiting for a running command. It removes a native Schedule's trigger, workflow and vault key before it admits the removal run. It leaves Node Schedules and the Schedules of other Instances in place.

## Error codes

Schedule operations return these codes in the Orbit error envelope. The CLI checks input before it sends a request. The API answers malformed input with `validation.failed` (422).

| Error code | Meaning |
| --- | --- |
| `operation.native_failed` | A Node timer change failed. Completed host work remains; fix the cause and repeat to converge. |
| `swamp.schedule_publish_unavailable` | Serve did not confirm a workflow or trigger change. Repeat the request. |
| `schedule.name_invalid`, `schedule.calendar_invalid`, `schedule.command_invalid`, `schedule.timeout_invalid` | The input is outside the accepted form, or Swamp refuses the calendar. |
| `schedule.target_invalid` | The target does not exist, or the operation does not apply to it. The Gateway also uses it with 403 when a completion report comes from another Node. |
| `schedule.target_unsupported` | A migrated Schedule has no live log stream; use `schedule:logs` without `--follow`. |
| `schedule.target_unavailable` | The target, its Node, its user, its working directory, or the command copy cannot run the operation. |
| `schedule.retry_conflict` | The target already has a Schedule with this name and a different specification. |
| `schedule.state_invalid` | The Schedule status does not allow the operation, or another operation on the Instance is running. |
| `schedule.target_in_use` | The change would move or remove the target of an existing Schedule. |
| `schedule.artifact_conflict` | A file at a Node timer's owned path is not the Schedule's own file. |
| `schedule.install_failed`, `schedule.rollback_failed`, `schedule.run_failed`, `schedule.activation_failed`, `schedule.remove_failed` | The operation did not finish. |
| `schedule.node_unreachable` | The host Node has no WireGuard address, or SSH to it failed. |

## Inspect Schedule drift

Schedule creation, enable/disable and native run results own Schedule validation. [Doctor](/cli/doctor/doctor#what-each-family-checks) does not read Schedule command files, timers, credentials or orphan inventories.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### One workflow per Schedule

A Swamp workflow holds one trigger. One small workflow per Schedule gives each Schedule its own cron trigger and fixed intent, while every fire runs the same shared `task-command-run`. Serve owns the timing, so a fire needs neither the Node's systemd nor the Gateway, and nothing is installed on the Node.

### Private command bytes use stdin

The command is operator input that runs as the selected account. Keeping it in the admitted private source and sending it through stdin avoids putting it in root argv, public units or native output. Infrastructure commands use fixed arguments. Command output remains operator-controlled journal content, so commands must keep secrets out of their output.

### No edit and no automatic move

A Schedule changes only through destroy and create. When its target moves, an existing workflow would keep firing at the old Node. So a Schedule never moves by itself, and the operator recreates it on the new placement.

### Only the latest run

The native result projection records the latest run time and success or failure. Detailed native run history belongs to Swamp.

### A disabled start for copied Schedules

A copied Schedule on a new production Instance can run before the data of that Instance is ready. So an Instance Schedule can start disabled, and enable is a separate step. A manual run is not an enable, because one run does not start the calendar.

Retained Instance timer removal uses official file and systemd checks. Its native end assertion confirms four managed paths are absent and the timer is stopped, disabled and unloaded. A standalone removal refuses an active service before mutation; Instance cascade removal stops it. The Gateway deletes the copy only after native success.

The Gateway task PR gate runs as the internal Artisan command `task:pr-gate`. This schedule keeps its existing timing and overlap guard; the public task commands are under [`task:*`](/cli/task).
