---
title: "Instance logs"
description: "How the Gateway reads the application log of an Instance, which file it reads, what it redacts, and how a viewer follows it live."
covers:
  - apps/gateway/app/Actions/Instances/ShowInstanceLogsAction.php
  - apps/gateway/app/Http/Controllers/Api/InstanceLogsController.php
  - apps/gateway/app/Infrastructure/Instances/RemoteInstanceLogReader.php
  - apps/gateway/app/Domain/Logs/{LogReadLimit,LogRedactor}.php
  - apps/agent/src/commands/application_log.rs
---

# Instance logs

The Gateway reads the end of an Instance's application log file. Because the application writes this file itself, it shows application errors that Process logs do not. [Processes and schedules](/reference/processes-and-schedules) and [Schedules](/reference/schedules) cover service and schedule logs.

## Read the log

`GET /api/v1/instances/{instance}/logs` runs `instance.read-application-log` over SSH on the Instance's Node and returns `{ id, name, lines, logs }`. `lines` accepts 1 through 1,000 (default 100), and the read is capped at 4 MiB of the newest complete lines. Passing `follow` is refused (`validation.failed`).

The web app displays this log on the Instance page, [`orbit instance:logs`](/cli/instance/logs) prints it in the terminal, and the PHP SDK calls the same endpoint.

## Follow the log live

Development Instances support live log following in the web app and through `orbit instance:logs --follow` using a [live log stream](/reference/live-logs). The Node agent reads the same file and rules, and the Gateway relays new lines to that viewer only.

Production Instances are SSH-only for application logs: live follow refuses with `409 logs.live_unavailable` (`reason: "ssh_only"`), while one-shot reads continue to work over SSH.

## Know which file the Gateway reads

Orbit reads one file under `storage/logs` in the Instance's [application directory](/reference/projects#application-directory) (for example `<checkout>/apps/site/storage/logs` in development or `<production-home>/current/apps/site/storage/logs` in production when the web root is `apps/site/public`):

| Order | File | Used when |
| --- | --- | --- |
| 1 | `laravel.log` | The file exists (`single` log channel). |
| 2 | The newest `laravel-*.log` | `laravel.log` is absent (`daily` log channel). |

If neither file exists, the request succeeds with an empty log. The caller never specifies a path. The reader accepts only regular files directly inside a real `storage/logs` directory and ignores symbolic links.

## Know what the Gateway redacts

Before returning log output, the Gateway replaces every stored environment value of the Instance (eight characters or longer) with `[REDACTED]`, skipping standard non-secret Laravel setting keys listed in [Live logs: Redaction](/reference/live-logs#redaction). Overlapping or adjacent matches collapse into a single marker, and the Gateway applies the same credential-pattern redaction used for Process logs ([text-redaction failures](/cli/activity#text-redaction-failures)).

Application logs can still contain personal or business data written by the app itself, so reading them requires an access grant to the Instance's Node.

## Handle a failed read

Error responses never include log content.

| Status | Error code | Cause |
| --- | --- | --- |
| 403 | `peer.identity_unknown` or `node_access.required` | Caller is not an active WireGuard peer or lacks access to the Instance's Node. |
| 404 | `resource.not_found` | No Instance matches the selector. |
| 422 | `validation.failed` | `lines` is outside 1 through 1,000, or `follow` was passed. |
| 422 | `instance.checkout_path_invalid` | Recorded checkout path is not a valid storage path. |
| 502 | `instance.logs_failed` | SSH or the remote log reader failed to read the log. |
