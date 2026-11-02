---
title: "Schedules"
description: "How the Gateway stores a Schedule as its own Swamp workflow with a cron trigger, records each fire as a task, and removes it."
covers:
  - apps/gateway/app/Actions/Schedules/**
  - apps/gateway/app/Domain/Schedules/**
  - apps/gateway/app/Infrastructure/Schedules/**
  - apps/gateway/app/Data/Schedules/**
  - apps/gateway/app/Http/{Controllers/Api/Schedule*,Requests/Schedules/*}.php
  - apps/gateway/{app/Models/Schedule.php,database/migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php,database/migrations/2027_01_10_120000_add_native_trigger_to_schedules.php}
  - apps/gateway/app/{Infrastructure/Swamp/SwampScheduleWorkflows.php,Jobs/Operations/ProjectSwampScheduleFire.php}
  - packages/php-sdk/src/{Requests,Responses}/Schedules/**
---

# Schedules

A Schedule runs one command on a calendar for one Node or one Instance. The Gateway stores the Schedule. Swamp serve on Node 209 holds one small workflow per Schedule, `schedule-<uuid>`, with the Schedule's cron trigger and its intent fixed inside. Each fire runs the shared `task-command-run` workflow, which reaches the Node over SSH. Nothing is installed on the Node (ADR 0244). macOS is not a Schedule target. A Schedule mutation there returns `schedule.platform_unsupported` (HTTP 422).

Per-minute schedulers on production Instances, such as `laravel-scheduler` and `powersync-health`, keep a systemd timer on their Node until the serve load and outage test passes. The calendar's form picks the path: a cron expression makes a native Schedule, and a systemd calendar keeps the [Node timer](#node-timers). Production preparation copies each Project definition this way; a native copy starts active and disabled, and its workflow is written when it is first enabled. [`schedule`](/cli/schedule) lists the commands. [Processes and schedules](/reference/processes-and-schedules) describes Project Schedule definitions and their Instance copies.

An Orbit Schedule is separate from the Gateway's Laravel schedule. Swamp owns [observation schedules](/reference/gateway-queue#scheduled-work), including Doctor collection, Horizon health and operation recovery. The Gateway scheduler keeps its own self-maintenance: Activity finalization, Horizon metrics snapshots, and batch and failed-job pruning. Hourly `problems:file` stays in the Gateway scheduler with its lock.

The Gateway also runs `orbit:auto-deploy` every minute without overlap for [automatic deployments](/reference/deployments#automatic-deployments). That internal tick is not a user-created Schedule and does not install a timer on an app-dev Node. Every five minutes Swamp also reads the Gateway endpoint that resolves the [desired fleet state](/reference/self-update#how-the-gateway-resolves-it) so the CLI release check never waits in a request.

While task VMs are enabled, the Gateway checks the deployed recipe each minute and queues the [task VM host sweep](/reference/compute-drivers#sweep-task-vm-hosts) every 10 minutes. The sweep also runs while only the [UpCloud](/reference/compute-drivers#sweep-upcloud) token file is set. The same recipe reconcile and release handoff path queues the [UpCloud template refresh](/reference/compute-drivers#refresh-the-upcloud-template-on-recipe-changes) when UpCloud is configured and no verified template matches the deployed recipe. UpCloud has no separate nightly schedule.

Release handoff queues [base image builds](/reference/compute-drivers#build-the-base-image) on each configured host when the deployed recipe changes. The minute check recovers missed dispatches. [Nightly recipe maintenance](/reference/compute-drivers#nightly-recipe-maintenance) opens a snapshot and Ubuntu pin PR at 03:00 Europe/Amsterdam; the Swamp maintenance workflow requests its guarded merge and advances fleet convergence.

The Gateway also attempts `project-documents:probes:reconcile` once per minute without overlap. The [documents extension](/reference/extensions) registers this entry and the cleanup worker below from its provider, and both run whether or not its switch is on. It processes a finite batch from the private probe journal and permanently retains each cleanup record for late PUT recovery. This is an internal attempt schedule, not a promise of object lifetime or a Schedule on a Node. [Reserved probe recovery](/reference/project-documents#recover-reserved-probes) defines its bounds, backoff, repair, and separation from the gate for document-body cleanup.

While the Gateway queue is set, Swamp requests [operation recovery](/reference/operations#recover-a-lost-worker) every five minutes. The Gateway runs `orbit:vault-sync` every minute without overlap while the `vault` [extension](/reference/extensions#the-runtime-switch) is on. It does nothing until [Vaultwarden](/reference/vaultwarden#sync) is set up, and it contacts Vaultwarden only when a credential changed or the hourly check is due.

The Gateway attempts `project-documents:cleanup:work` every five minutes without overlap. Each run processes at most 100 due document-body cleanup records, and only while the restore-time gate is running. See [Project Documents](/reference/project-documents#publish-and-recover) for authorization, durable claims, retry bounds, and permanent abandoned-upload fences. This worker is separate from reserved-probe reconciliation.

The Gateway prunes failed queue jobs and batch metadata daily. [Gateway disk retention](/reference/gateway-recovery#disk-retention) describes the configurable cutoffs.

## Execution

Create, enable, disable and destroy touch no Node. The Gateway changes the Schedule's workflow or trigger on serve, waits for serve to confirm, and answers at once: HTTP 202 with an Operation that has already succeeded (R1). When serve does not confirm, the request fails with `swamp.schedule_publish_unavailable` (503) and records no Operation; repeat the same request. A manual run starts `task-command-run` and returns its running Operation with HTTP 202. An Idempotency-Key replays the original Operation.

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

Create takes `target_type`, `target_id`, `name`, `calendar`, and `command`, and optionally `timeout_seconds` and `start`. `start` defaults to `true`. A Node target refuses `start: false` with `schedule.state_invalid`. An Instance target can start disabled, without a trigger. Run, enable, and destroy take an empty body. The Gateway refuses unknown, duplicate, or wrongly typed members with 422 before it changes anything.

Each Schedule API request except Complete records one operator Activity entry. The entry names the Schedule and its target, including a destroy request that admits an Operation. It never holds the command, calendar, journal lines, paths, users, or unit text. Executor requests have their own worker audit entries.

## Execution context

The caller picks only the target. The Gateway derives the host Node, the user, the working directory, and the shell from the target's placement.

| Target | User | Working directory | Shell |
| --- | --- | --- | --- |
| Node | The Node's managed user | That user's home | The user's login shell, with login mode and protected stdin |
| Instance on `app-dev` | The Node's managed user | The Instance's application directory in its checkout | The user's login shell, with login mode and protected stdin |
| Instance on `app-prod` | The Instance's production user | The Instance's application directory under `<production-home>/current` | `/bin/bash -s`, without a login |

For Laravel, the [application directory](/reference/projects#application-directory) is the effective web root without its trailing `/public`. With root `apps/site/public`, a Schedule runs in `<checkout>/apps/site` on `app-dev` or `<production-home>/current/apps/site` on `app-prod`, so `php artisan schedule:run` finds that app's `artisan` and `.env`. Root `public` keeps the checkout or release root. Node Schedule working directories do not change. Instances that are not Laravel apps keep their checkout or release root.

The target Node must be an active Linux Node with a WireGuard address. An Instance target must be active. A production Schedule resolves `current` each time it runs, so a new release changes later runs. A production Instance needs a selected release before it can take a Schedule, even one that starts disabled.

A Schedule does not follow its target. While a Schedule exists, the Gateway refuses to remove its target Node or host Node with `schedule.target_in_use` (409). [Instance transfer](/reference/instance-transfer) does not check Schedules. After a transfer, list and show still work, and an identical create returns `schedule.target_in_use`. Run, logs, enable, and destroy fail with `schedule.target_unavailable`, because the Instance runs on another Node than the host Node. Destroy the Instance's Schedules before a transfer. Instance removal removes the Instance's Schedules itself.

## The Schedule workflow

Create writes the command to the vault key `schedule-command-<uuid>` in `orbit-control-inputs`, then creates `schedule-<uuid>` with `workflow.create` and writes its content with `workflow.edit`. The workflow has one step, `Run the Schedule`, that calls `task-command-run` as a nested workflow. Its inputs are fixed: the Schedule UUID, the command hash, the user, group, home, shell, working directory, login mode and timeout, the Node's SSH address and host keys, and the vault key name. The command itself never enters the workflow, its inputs or its output. Its tags name the Schedule, its Node and its target, so the Gateway can record a fire after the Schedule is gone.

Serve stores the workflow as an untracked file in its `workflows/` directory beside the checked-in workflows. A release load checks out the next commit and keeps it, and so do a serve reload and a restart; a fresh clone or `git clean` would drop it. The Gateway's record stays the source: an identical create writes the workflow and its trigger again. Changed SSH host keys reach the workflow only through destroy and create.

The trigger is the workflow's trigger override on serve, set with `workflow.trigger.set` and removed with `workflow.trigger.remove`. The Gateway's credential needs run, read and write on `workflow:schedule-*`.

## Each fire

Serve fires the trigger and starts one root run of `schedule-<uuid>`. Serve runs a limited number of scheduled runs at once, and a Schedule never overlaps itself, so a fire can start late, and a fire that is due while the last one runs waits. Commands must allow for a late or extra run.

`task-command-run` runs on Node 209 in four steps: `Read the check script`, `Check user and directory`, `Run the command` and `Report the result`. The check, over official `@swamp/ssh`, confirms the account, its home, shell and group, that the user can enter the working directory, and the command's hash, with the command on stdin. The command then runs as the Schedule's user through `sudo`, `timeout` and `env -i`, with the command on stdin. Its output goes to the Node journal under `orbit-schedule-{uuid}`; it is absent from native history. `Report the result` prints `{"ready":true}`, `schedule.target_unavailable` when the check failed, or `schedule.run_failed`.

The Gateway's shared run reader sees each root run of `schedule-<uuid>` on the commit feed. When the run starts, it records one running `schedule.run` Operation with `triggered_by: schedule` and the run ID, with the Schedule's timeout plus 120 seconds as its run budget. When the run ends, the same Operation gets the result of the nested `Report the result` step, such as `schedule.target_unavailable` or `schedule.run_failed`, and the Gateway sets `last_run_at` and `last_run_status`. Swamp never calls the Gateway. A manual run starts `task-command-run` directly, because its own run carries the Operation ID; the shared terminal subscriber projects it like any Operation.

## Enable

Enable writes the command copy and the Schedule's workflow, sets its trigger, and stores `enabled` after serve confirms; a repeat does it again. Disable removes the trigger and stores `disabled`; the workflow stays, and a fire that already started finishes. If serve does not confirm, the desired state stays unchanged. A Node timer Schedule refuses enable with `schedule.target_invalid`, because its timer is always on. Production Schedules resolve `current` each time they run, so a new release changes later runs.

## Node timers

A Node timer Schedule owns two public files on its Node, named only by its UUID.

| Artifact | Path | Owner and mode |
| --- | --- | --- |
| Admission service | `/etc/systemd/system/orbit-schedule-{uuid}.service` | `root:root`, `0644` |
| Timer | `/etc/systemd/system/orbit-schedule-{uuid}.timer` | `root:root`, `0644` |

The service sends an authenticated Node request to the Gateway run endpoint. Its systemd invocation ID is the idempotency key. The service contains public execution context and the command hash, without command bytes or credentials. The timer uses `Persistent=true`. Each admitted run uses `task-command-run`.

Definition installation creates the timer files through `operation-schedule-install-instance-definitions`, disabled. Enable uses `operation-schedule-control` to enable and start the timer, and destroy uses `operation-schedule-destroy` to check for active work, stop the timer, remove the files and reload systemd. These workflows run on the `swamp` Node (`labels: {role: swamp}`) and reach the Node through official cfgmgmt models and `@swamp/ssh`. A failed step records `operation.native_failed`; completed host actions stay, and a repeat converges. A removal refused with `schedule.run_active` keeps the Schedule in `removing`; retry destroy after the command ends.

## Logs

Logs read only the Schedule's service, with fixed `journalctl --output cat` arguments. `lines` is 1 through 1,000, and the default is 100. The read has a 10-second deadline and returns at most 1 MiB. The response holds `output` with the newest complete lines, oldest first, and sets `truncated` to `true` when the byte limit removed older lines. Logs need an `active` Schedule; otherwise the Gateway returns `schedule.state_invalid`. Orbit does not store journal output, and it does not redact it: Process and Instance logs are redacted, Schedule logs are not. Keep secrets out of command output. The host Node's journal settings decide how long lines stay, and Orbit keeps no copy elsewhere.

## Latest run

The Gateway stores only the latest run, from a recorded fire or a manual run's terminal result. A removing Schedule receives no new latest run. Each fire and manual run is also an Operation.

## Remove

Destroy marks the Schedule `removing`, removes its trigger, then deletes its workflow, and deletes the record. Removing a trigger or a workflow that is gone is safe, so a disabled Schedule takes the same path. A fire that already started runs to its end, and the Gateway still records it. When serve does not confirm, the Schedule stays `removing` with `swamp.schedule_publish_unavailable`; repeat destroy. Destroy also deletes the command's vault key with `vault.delete`; a fire that already started read it when it began.

[Instance removal](/reference/instance-removal) removes each Schedule of the Instance without waiting for a running command. It leaves Node Schedules and the Schedules of other Instances in place.

## Error codes

Schedule operations return these codes in the Orbit error envelope. The CLI checks input before it sends a request. The API answers malformed input with `validation.failed` (422).

| Error code | Meaning |
| --- | --- |
| `operation.native_failed` | A Node timer change failed. Completed host work remains; fix the cause and repeat to converge. |
| `swamp.schedule_publish_unavailable` | Serve did not confirm a workflow or trigger change. Repeat the request. |
| `schedule.name_invalid`, `schedule.calendar_invalid`, `schedule.command_invalid`, `schedule.timeout_invalid` | The input is outside the accepted form, or Swamp refuses the calendar. |
| `schedule.target_invalid` | The target does not exist, or the operation does not apply to it. The Gateway also uses it with 403 when a completion report comes from another Node. |
| `schedule.target_unavailable` | The target, its Node, its user, its working directory, or the command copy cannot run the operation. |
| `schedule.retry_conflict` | The target already has a Schedule with this name and a different specification. |
| `schedule.state_invalid` | The Schedule status does not allow the operation, or another operation on the Instance is running. |
| `schedule.target_in_use` | The change would move or remove the target of an existing Schedule. |
| `schedule.artifact_conflict` | A file at a Node timer's owned path is not the Schedule's own file. |
| `schedule.install_failed`, `schedule.rollback_failed`, `schedule.run_failed`, `schedule.activation_failed`, `schedule.logs_failed`, `schedule.remove_failed` | The operation did not finish. |
| `schedule.node_unreachable` | SSH to the host Node failed. |

## Inspect Schedule drift

Schedule creation, enable/disable and native run results own Schedule validation. [Doctor](/cli/doctor#what-each-family-checks) does not read Schedule command files, timers, credentials or orphan inventories.

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
