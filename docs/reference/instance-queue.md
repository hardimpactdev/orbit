---
title: "Instance queue"
description: "How the Gateway reads the queue of an Instance from its registered connections, what it returns, and what it never returns."
covers:
  - apps/gateway/app/Actions/Instances/ShowInstanceQueueAction.php
  - apps/gateway/app/Domain/Instances/Queue/**
  - apps/gateway/app/Http/Controllers/Api/InstanceQueueController.php
  - apps/gateway/app/Http/Requests/Instances/InstanceQueueRequest.php
  - apps/gateway/app/Infrastructure/Instances/StoreInstanceQueueReader.php
---

# Instance queue

The Gateway reports the queue of an Instance: how many jobs wait, how many failed, the load of each queue, and the newest pending or failed jobs. It reads the queue live, with its own code, through the connections registered on the Instance. It starts no task and runs nothing on a Node or in the application.

## Read the queue

`GET /api/v1/instances/{instance}/queue` returns one report. `state` selects the job list: `pending` or `failed`, and the default is `pending`. `limit` sets the number of jobs from 1 through 50, and the default is 50. The web app shows this report on the Instance page. The CLI and the PHP SDK have no command for it.

| Field | Meaning |
| --- | --- |
| `available` | False when the Instance has no queue store to read. Every other field but `state` is then absent. |
| `totals` | The number of `pending` jobs, and of `failed` jobs, which is null when no database connection holds `failed_jobs`. |
| `queues` | Up to 50 queues, each with its `name` and `length`. The length counts waiting and delayed jobs. |
| `jobs` | The newest jobs in `state`: `id`, `name`, `queue`, `pushed_at`, `failed_at`, and `exception`. `pushed_at` comes from `created_at` of a database queue, and can be null for a Redis queue whose payload carries no push time. |

The report has no worker status, no jobs per minute, and no completed jobs. Those come from the internals of Laravel Horizon, and the Gateway does not read them.

## Know when a queue is available

The stored environment of the Instance and its registered connections name the queue store:

- `QUEUE_CONNECTION=redis` needs a registered Redis connection with the prefix `REDIS`.
- For Redis, the key prefix is `REDIS_PREFIX`, or Laravel's default built from `APP_NAME`.
- For Redis, the main queue is `REDIS_QUEUE`, which defaults to `default`.
- `QUEUE_CONNECTION=database` needs a registered mysql or pgsql connection with the prefix `DB`. It reads the `jobs` table.

A pending read does not fail when `failed_jobs` is unreadable; the failed total is then null. Failed jobs come from the `failed_jobs` table through the registered mysql or pgsql connection with the prefix `DB`. Add a connection to an Instance with [`instance:database:add`](/cli/instance/database-add). Any other `QUEUE_CONNECTION`, or a missing connection, answers `available: false`.

## Know how the Gateway reads it

The Gateway connects the way the Gateway queue connects to its own Redis: with Laravel's database and Predis clients, over WireGuard, with a 5 second limit on the connection and on each database statement. It uses the password it stores encrypted for the connection and copies it nowhere. Every statement reads.

A Redis store gives the queue names with `SCAN` (at most 50), then `LLEN` of each queue list plus `ZCARD` of its delayed set in one pipeline, and `LRANGE` of the tail of the 5 longest lists for the newest jobs. It lists no delayed job. A database store counts the `jobs` rows that no worker reserved, and reads the newest of them.

## Know what the Gateway never returns

A job payload can hold customer data. The Gateway reads a payload only to take the job class, id and push time, and it never returns one. For a failed job it returns only the first line of the exception, because the lines after it are a stack trace with arguments. The data comes out of the application, so the Gateway treats it as untrusted: it casts and bounds every value and passes on only the fields in the table above.

## Handle a failed read

A failed read returns an Orbit error. A report is never part of an error, and the message never repeats what the store printed.

| Status | Error code | Cause |
| --- | --- | --- |
| 422 | `validation.failed` | `state` is not `pending` or `failed`, or `limit` is outside 1 through 50. |
| 502 | `instance.queue_failed` | The store did not answer within 5 seconds, refused the login, or lacks the `jobs` or `failed_jobs` table. |
