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

The Gateway reads an Instance's queue live through its registered [Database connections](/reference/database-connections), reporting pending and failed counts, per-queue lengths, and recent jobs without running any command on the Node or inside the application.

## Read the queue

`GET /api/v1/instances/{instance}/queue` returns one snapshot for the Orbit web Instance page (there is no CLI or PHP SDK command). Query parameters:

- `state`: `pending` (default) or `failed`.
- `limit`: 1 through 50 (default 50).

| Field | Meaning |
| --- | --- |
| `available` | `false` when the Instance has no readable queue store (only `state` is returned alongside it). |
| `totals` | Counts of `pending` and `failed` jobs (`failed` is `null` if no database connection holds `failed_jobs`). |
| `queues` | Up to 50 queues with `name` and `length` (waiting plus delayed jobs). |
| `jobs` | Newest jobs in `state`: `id`, `name`, `queue`, `pushed_at`, `failed_at`, and `exception`. `pushed_at` comes from `created_at` on database queues and may be `null` on Redis queues. |

Worker status, jobs per minute, and completed jobs belong to Laravel Horizon and are not included.

## Know when a queue is available

Orbit inspects the Instance's stored environment and attached connections:

- `QUEUE_CONNECTION=redis`: requires an attached `redis` connection under prefix `REDIS` (using `REDIS_PREFIX` or Laravel's `APP_NAME` default, and `REDIS_QUEUE`, defaulting to `default`).
- `QUEUE_CONNECTION=database`: requires an attached `mysql` or `pgsql` connection under prefix `DB` to read `jobs`.
- Failed jobs are read from `failed_jobs` on the attached `DB` connection. An unreadable `failed_jobs` table leaves pending reads working with `totals.failed: null`.

Any other `QUEUE_CONNECTION` or a missing connection returns `available: false`. Attach connections with [`instance:database:add`](/cli/instance/database-add).

## Know how the Gateway reads it

The Gateway connects over WireGuard using the connection's encrypted credentials with a 5-second timeout per connection and statement, issuing read-only queries only:

- **Redis:** discovers up to 50 queues via `SCAN`, pipelines `LLEN` and `ZCARD`, and reads recent jobs from the five longest lists. Delayed jobs are omitted.
- **Database:** counts unreserved rows in `jobs` and fetches the newest rows.

## Know what the Gateway never returns

Because job payloads can contain customer data, Orbit extracts only the job class, ID, and push time and never returns payloads. For failed jobs, Orbit returns only the first line of the exception (omitting stack traces and arguments). All returned strings are cast and bounded (up to 100 characters for IDs and queue names, 200 for job names, and 300 for exception lines).

## Handle a failed read

Error responses never include queue data or raw database errors.

| Status | Error code | Cause |
| --- | --- | --- |
| 403 | `peer.identity_unknown` or `node_access.required` | Caller is not an active WireGuard peer or lacks access to the Instance's Node. |
| 404 | `resource.not_found` | No Instance matches the selector. |
| 422 | `validation.failed` | `state` is not `pending` or `failed`, or `limit` is outside 1 through 50. |
| 502 | `instance.queue_failed` | Store timed out after 5 seconds, rejected credentials, or lacked `jobs`/`failed_jobs`. |
