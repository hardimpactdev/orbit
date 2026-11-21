---
title: "Schedules"
description: "How the Gateway stores a Schedule as its own Swamp workflow with a cron trigger, records each fire as a task, and removes it."
covers:
  - apps/gateway/app/{Actions,Domain}/Schedules/**
  - apps/gateway/app/Infrastructure/Schedules/**
  - apps/gateway/app/Data/Schedules/**
  - apps/gateway/app/Http/{Controllers/Api/Schedule*,Requests/Schedules/*}.php
  - apps/gateway/{app/Models/Schedule.php,database/migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php,database/migrations/2027_01_10_120000_add_native_trigger_to_schedules.php}
  - apps/gateway/app/{Infrastructure/Swamp/SwampScheduleWorkflows.php,Jobs/Operations/ProjectSwampScheduleFire.php}
  - packages/php-sdk/src/{Requests,Responses}/Schedules/**
  - swamp/workflows/workflow-task-command-run.yaml
---

# Schedules

A Schedule runs one command on a recurring calendar for a Node or an Instance. By default, Orbit stores each Schedule as a cron-triggered workflow (`schedule-<uuid>`) that executes the command on the target Node over SSH without installing unit files on the Node. Schedules that use a systemd calendar expression (such as per-minute production schedulers) run as [Node timers](#node-timers) instead.

[`schedule`](/cli/schedule) lists the CLI commands, and [Processes and schedules](/reference/processes-and-schedules) covers Project Schedule definitions. macOS Nodes are not valid Schedule targets (`schedule.platform_unsupported`, 422). Orbit Schedules are separate from the Gateway's own internal scheduled maintenance; see [Gateway queue: Scheduled work](/reference/gateway-queue#scheduled-work).

## Fields

Each Schedule is identified publicly by an immutable UUID.

| Field | Contract |
| --- | --- |
| `id` | Immutable UUID. |
| `target_type`, `target_id` | Either `node` or `instance` with its numeric ID. |
| `name` | Unique within the target: 1–63 lowercase ASCII letters or digits, with single hyphens between them. |
| `calendar` | Standard 5-field or 6-field (seconds first) cron expression, or shorthand such as `@daily` (UTC). Node-timer Schedules use a systemd `OnCalendar` expression. |
| `command` | One non-empty UTF-8 line of at most 4,096 bytes, with no NUL, CR, or LF. |
| `timeout_seconds` | 1 through 86,400 (default 3,600). |
| `desired_timer_state` | `enabled` or `disabled`. |
| `status` | `provisioning`, `active`, `failed`, or `removing` (with `failed_step` and `error_code` on failure). |
| `last_run_at`, `last_run_status` | Timestamp and outcome (`success` or `error`) of the latest fire or manual run, or `null` before the first run. |

Cron triggers fire in UTC; `CRON_TZ=` and `TZ=` prefixes return `schedule.calendar_invalid` (422). Schedules are immutable: an identical create returns the existing Schedule, and a different spec with the same target and name returns `schedule.retry_conflict` (409). To change a Schedule, destroy and recreate it (`schedule:update` edits only Project definitions).

## API

All routes under `/api/v1/schedules` require an access grant to the target's Node.

| Operation | Request | Result |
| --- | --- | --- |
| List | `GET /api/v1/schedules` | Lists Schedules on reachable Nodes, ordered by name, omitting `command`. |
| Create | `POST /api/v1/schedules` | Records the Schedule, publishes its workflow and trigger, and returns an Operation (202). |
| Show | `GET /api/v1/schedules/{uuid}` | Shows one Schedule, including `command`. |
| Run | `POST /api/v1/schedules/{uuid}/run` | Starts one immediate run and returns its Operation (202) without changing trigger state. |
| Logs | `GET /api/v1/schedules/{uuid}/logs?lines=N` | Returns recent journal lines (1–1,000, default 100). |
| Enable | `POST /api/v1/schedules/{uuid}/activate` | Enables the trigger and returns an Operation (202). |
| Disable | `POST /api/v1/schedules/{uuid}/disable` | Removes the trigger of a cron-workflow Schedule and returns an Operation (202). |
| Destroy | `DELETE /api/v1/schedules/{uuid}` | Removes the trigger, workflow (or timer units), and record (202). |
| Complete | `POST /api/v1/schedules/{uuid}/complete` | Node-timer completion callback (204). |

Create accepts `target_type`, `target_id`, `name`, `calendar`, `command`, and optional `timeout_seconds` and `start` (default `true`). Activity logs record the Schedule and target without exposing the command, calendar, or journal output.

## Execution context

Orbit derives the host Node, user, working directory, and shell from the target:

| Target | User | Working directory | Shell |
| --- | --- | --- | --- |
| Node | Node's managed user | That user's home | User's login shell (login mode, protected stdin) |
| Instance on `app-dev` | Node's managed user | Instance [application directory](/reference/projects#application-directory) in checkout | User's login shell (login mode, protected stdin) |
| Instance in a development container | `orbit` | Application directory under `/srv/workspace` | `/bin/bash` (login mode, protected stdin) |
| Instance in a production container | `orbit` | Application directory under `/home/orbit` | `/bin/bash` |
| Instance on `app-prod` | Instance's production user | Application directory under `<production-home>/current` | `/bin/bash -s` (non-login) |

The target Node must be an active Linux Node with a WireGuard address, and an Instance target must be active. Production Instances require a selected release before creating a Schedule, even when `start` is `false`. Production Schedules resolve `current` (or the confirmed serving release in a production container) on every fire so new releases take effect automatically.

While a Schedule exists, Node removal is refused with `schedule.target_in_use` (409). During an [Instance transfer](/reference/instance-transfer), Orbit pauses the Instance's Schedule triggers while the transfer runs, then rewrites each published workflow for the destination Node and restores enabled triggers when the transfer completes. [Instance removal](/reference/instance-removal) removes the Instance's Schedules automatically.

## The Schedule workflow

For cron-triggered Schedules, create stores the command in a private vault key (`schedule-command-<uuid>`), publishes workflow `schedule-<uuid>` with the fixed target context, and sets its cron trigger. If publishing fails, the request returns `swamp.schedule_publish_unavailable` (503) without recording an Operation so you can retry.

When a trigger fires (or when you call `run`), Orbit starts a transient `systemd-run --pipe --wait --collect` unit on the target Node with the admitted user, working directory, and `RuntimeMaxSec`. The command arrives on standard input so it never appears in command arguments or public workflow history, and output goes to the Node journal under `orbit-schedule-{uuid}`. A Schedule never overlaps itself; a due fire waits if the previous run is still active.

### Enable and disable

Enable publishes the trigger and records `enabled` (creating the workflow first for copied Project definitions that start disabled). Disable removes the trigger and records `disabled` while letting any active run finish.

- **Node-timer enable:** Instance-targeted Node-timer Schedules enable and start their systemd timer. Node-timer Schedules targeting a Node refuse enable with `schedule.target_invalid` (409).
- **Disable:** Only cron-workflow Schedules support `disable`; Node-timer Schedules return `schedule.state_invalid` (409).

## Node timers

A Node-timer Schedule installs two root-owned `0644` unit files on its Node:

| Artifact | Path |
| --- | --- |
| Admission service | `/etc/systemd/system/orbit-schedule-{uuid}.service` |
| Timer | `/etc/systemd/system/orbit-schedule-{uuid}.timer` |

The service sends an authenticated request to the Gateway run endpoint using the systemd invocation ID as the idempotency key and contains no command bytes or credentials. The timer sets `Persistent=true`. Standalone removal refuses while a run is active (`schedule.run_active`, 409); Instance removal stops and cleans up all Instance Schedules automatically.

## Checkout migration

[`instance:migrate`](/cli/instance/migrate) carries registered Node-timer Schedules into the Instance container with their UUID, name, calendar, timeout, and desired state intact. Schedules backed by cron workflows cannot migrate yet.

During migration, Orbit captures each host timer's state, disables and stops the host units, installs the container units disabled, and activates them at cutover before retiring the host files. Any host timer fire received while migration holds admission is queued under the Instance lock: if migration rolls back, waiting fires run against the checkout; if cutover succeeds, waiting host fires are marked skipped rather than replayed into the container.

Commands migrated into a container must be a single literal invocation of `php`, `node`, `bun`, `vp`, `composer`, `git`, `true`, `false`, `echo`, `printf`, or a checkout executable, with optional `> /dev/null` (`>> /dev/null`) and `2>&1` redirection. Shell operators, variable expansion, and inline evaluation are refused. Host binary prefixes (`/usr/bin/php8.5`, `/usr/bin/composer`) are normalized to `php` or `composer` (mismatched PHP versions are refused).

### Container timers

Inside a container, `orbit-schedule-<uuid>.timer` and `.service` run as `User=orbit` with `/bin/bash`, the container `PATH`, and the application environment file, preserving the source timezone explicitly. `Persistent=false` prevents catch-up runs after sleep or stop, and `DeferReactivation=true` skips overlapping ticks.

Automatic container fires do not create Gateway Operations; `schedule:list`, `schedule:show`, and `schedule:logs` read observed state and journal lines from the container. Stopped containers report an inactive timer and return `schedule.target_inactive` for `schedule:logs` without waking. Clones do not inherit ad hoc container Schedules.

## Logs

`schedule:logs` reads retained journal entries for an `active` Schedule (`schedule.state_invalid` otherwise) and redacts credential-shaped text and stored environment values:

- **Host Schedules:** reads `journalctl --output short-iso --utc` via the Node agent (1–1,000 lines, default 100, 5-second timeout, 256 KiB cap, sets `truncated: true` on truncation).
- **Migrated container Schedules:** reads retained service and command journal entries (120-second timeout, 4 MiB UTF-8 cap).
- **Live follow:** `--follow` is unsupported for Schedules. The CLI rejects it locally with `schedule.follow_unavailable`, and direct API live-stream requests for migrated Schedules return `schedule.target_unsupported`.

## Latest run

The Gateway stores only the latest run timestamp and status (`last_run_at`, `last_run_status`) from recorded fires and manual runs. Removing Schedules receive no new updates.

## Remove

`schedule:destroy` marks the Schedule `removing`, removes its trigger, workflow, and command vault key (or systemd timer files for Node timers), and deletes the record. Any fire already in progress finishes cleanly. If publishing fails, the Schedule stays `removing` so you can repeat `schedule:destroy`.

## Error codes

Malformed input returns `validation.failed` (422).

| Error code | Meaning |
| --- | --- |
| `operation.native_failed` | A Node-timer host change failed; fix the cause and retry to converge. |
| `swamp.schedule_publish_unavailable` | Workflow or trigger publish was not confirmed (503); retry. |
| `schedule.platform_unsupported` | The target Node is macOS (422). |
| `schedule.name_invalid`, `schedule.calendar_invalid`, `schedule.command_invalid`, `schedule.timeout_invalid` | Field violates the Schedule contract or calendar syntax. |
| `schedule.target_invalid` | Target does not exist, operation does not apply (such as enabling a Node-targeted Node timer), or completion callback came from the wrong Node (403). |
| `schedule.target_inactive` | The target container is stopped; logs cannot be read without waking. |
| `schedule.target_unsupported` | Live log streaming is not supported for this Schedule. |
| `schedule.follow_unavailable` | `schedule:logs --follow` is not supported. |
| `schedule.target_unavailable` | Target Node, user, working directory, or command copy is unavailable. |
| `schedule.retry_conflict` | Target already has a Schedule with this name and a different specification (409). |
| `schedule.state_invalid` | Schedule status or kind does not allow the operation (409). |
| `schedule.run_active` | Standalone destroy refused while a Node-timer run is still active (409). |
| `schedule.target_in_use` | Cannot remove a Node that still owns a Schedule (409). |
| `schedule.artifact_conflict` | An existing file at a Node timer's path is not owned by this Schedule. |
| `schedule.logs_failed` | Reading Schedule journal logs failed. |
| `schedule.install_failed`, `schedule.rollback_failed`, `schedule.run_failed`, `schedule.activation_failed`, `schedule.remove_failed` | The operation did not finish. |
| `schedule.node_unreachable` | Host Node has no WireGuard address or SSH failed. |

## Why it works this way

### One workflow per Schedule

Giving each Schedule its own small cron-triggered workflow lets every Schedule keep an independent calendar and fixed target intent while sharing one execution path, without installing systemd units on Nodes.

### Private command bytes use stdin

Sending the command on standard input keeps operator commands out of process argument lists, public unit files, and native workflow history. Keep secrets out of command stdout/stderr, as journal output is stored on the Node.

### Immutable Schedules

Requiring destroy and recreate instead of in-place edits keeps Schedule workflows and unit identities predictable.

### Disabled start for copied production Schedules

Production copies start disabled so newly cloned Instances do not fire recurring jobs against incomplete data. A manual `schedule:run` tests a command without enabling the recurring trigger.
