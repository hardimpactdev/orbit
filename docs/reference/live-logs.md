---
title: "Live logs"
description: "How a viewer tails an Instance or Process log live: opening, renewing, and closing a log stream, the events it receives, the limits, and redaction."
covers:
  - apps/gateway/app/Domain/Logs/**
  - apps/gateway/app/Actions/Logs/**
  - apps/gateway/app/Http/Controllers/Api/{InstanceLogStreamsController,ProcessLogStreamsController,ScheduleLogStreamsController,RespondsWithLogStreams}.php
  - apps/gateway/app/Infrastructure/Logs/**
  - apps/gateway/app/Infrastructure/AgentView/LogRelay.php
  - apps/agent/src/logs/**
---

# Live logs

A live log stream relays new lines from an Instance or Process log to a single viewer over a private Reverb channel. Opening a stream requires the same Node access grant as a one-shot log read. The [web app](/reference/web-app#live-logs) log panes and `--follow` on [`orbit instance:logs`](/cli/instance/logs) and [`orbit process:logs`](/cli/process/logs) use live streams.

Some targets do not support live streams:

- **Production Instances:** application logs are SSH-only (`409 logs.live_unavailable`, `reason: "ssh_only"`).
- **Container Processes:** one-shot journal reads work; live streams refuse with `409 process.target_unsupported`.
- **Schedules:** Schedule follow is disabled (`409 schedule.follow_unavailable`); migrated container Schedules return `409 schedule.target_unsupported`. Use bounded one-shot snapshots instead.

## How a stream works

Clients follow five steps to start, renew, and close a log stream:

1. The client connects to Reverb ([Realtime events](/reference/events#discovery)) and reads its `socket_id`.
2. The client opens a stream with `socket_id`, receiving a private channel name and signed subscription auth.
3. The client subscribes to that channel with the returned `auth` string.
4. Once subscribed, the client renews the stream once to start delivery so no lines arrive before the client listens.
5. The client receives initial and new lines, renews every 20 seconds, and closes the stream at the end.

The Node agent reads the source, redacts each line, and pushes batches to the Gateway. The Gateway redacts each batch again against stored environment values and broadcasts it to the viewer's private channel. See [Node agent: Log tails](/reference/node-agent#log-tails).

## Open a stream

Open a stream through the Instance, Process, or Schedule whose log you want to follow:

| Method and path | Route name | Access |
| --- | --- | --- |
| `POST /api/v1/instances/{instance}/log-streams` | `instance:log-stream:create` | Same as `instance:logs` |
| `POST /api/v1/processes/{process}/log-streams` | `process:log-stream:create` | Same as `process:logs` |
| `POST /api/v1/schedules/{schedule}/log-streams` | `schedule:log-stream:create` | Same as `schedule:logs` (UUID selector) |

| Field | Required | Meaning |
| --- | --- | --- |
| `socket_id` | yes | The client's Reverb socket ID (for example `123.456`). |
| `lines` | no | Initial lines to send first, 1 through 1,000 (default 100). |

A `201` response returns `{ id, channel, auth, lines, lease_seconds, renew_seconds }`, where `id` is a random 128-bit value, `channel` is `private-log-stream.<id>`, `lease_seconds` is `60`, and `renew_seconds` is `20`. `POST /api/v1/broadcasting/auth` refuses all `private-log-stream.*` channels; use the `auth` returned at creation.

## Renew and close

A stream lives for 60 seconds after creation or its last renewal.

| Method and path | Route name | Result |
| --- | --- | --- |
| `PUT /api/v1/{instances,processes,schedules}/{id}/log-streams/{stream}` | `*:log-stream:renew` | Extends the lease by 60 seconds and starts the stream on first renewal. Returns `{ id, lease_seconds }`. |
| `DELETE /api/v1/{instances,processes,schedules}/{id}/log-streams/{stream}` | `*:log-stream:destroy` | Closes the stream immediately. Returns `{ id, closed: true }`. |

Only the Node that opened a stream can renew or close it, and every call rechecks the Node access grant. Renewals do not record Activity. Every 5 seconds, the Gateway closes expired streams (`expired`) and streams whose opener lost Node access (`revoked`).

## Receive events

The Gateway publishes two events on `private-log-stream.{stream}` using the [Realtime events envelope](/reference/events#envelope) (`id` is the stream ID; ignore any `client-*` events):

| Type | When | Data |
| --- | --- | --- |
| `log.lines` | Initial lines and subsequent batches (at most every 250 ms) | `{ sequence, lines, dropped, skipped }` |
| `log.ended` | Stream closed | `{ reason }` |

- `sequence` starts at 1 and increments by 1 per event (ignore duplicates after a retried relay).
- `lines` contains redacted lines without trailing newlines, oldest first.
- `dropped` counts lines dropped by rate limits since the last event (render as `[orbit] N lines dropped`).
- `skipped` counts bytes skipped when a file source falls more than 4 MiB behind (render as `[orbit] X MiB skipped`).

| End `reason` | Meaning | Client action |
| --- | --- | --- |
| `closed` | Client closed the stream. | None. |
| `expired` | Lease expired without renewal. | Open a new stream to continue. |
| `revoked` | Opener lost access to the serving Node. | Stop. |
| `agent_left` | Serving agent disconnected or missed stream list updates for 60 seconds. | Retry after 30 seconds. |
| `source_unavailable` | Agent could not open or continue reading the source. | Stop. |
| `relay_behind` | Gateway relay fell behind and dropped queued lines. | Retry after 30 seconds. |

## Sources

Each record maps to one log source on its serving Node:

| Record | Source | Format |
| --- | --- | --- |
| Development Instance | `storage/logs/laravel.log` in the [application directory](/reference/instance-logs#know-which-file-the-gateway-reads), or newest `laravel-*.log` | File lines (follows daily rotation and truncation) |
| systemd Process | `journalctl --unit orbit-process-{id}-{name}.service --output short-iso --utc` | `2026-09-25T10:15:02+00:00 host name[pid]: message` |
| Docker Process | Container `orbit-process-{id}-{name}` stdout and stderr | Container output lines |

The agent reads the newest journal cursor first, fetches the initial lines backward, and follows forward so entries written in between are delivered once. Single journal entries are capped at 256 KiB (`[orbit] message cut at 256 KiB`). The agent refuses root-owned log files, symlinks at `storage/logs` or the log file, and containers lacking `orbit.managed=true` and `orbit.process.id={id}` (ending with `source_unavailable`).

## Limits

The Node agent and Gateway enforce these bounds on every stream:

| Limit | Value |
| --- | --- |
| Open streams | 16 per serving Node |
| Initial batch | Requested `lines`, capped at 256 KiB |
| Line length | 8 KiB UTF-8 bytes (longer lines end with `[truncated]`) |
| Batch interval | At most one `log.lines` event every 250 ms, split to fit Reverb's 10,000-byte request cap |
| Agent rate | 32 KiB/s per stream (256 KiB burst); 256 KiB/s per Node |
| Gateway rate | 64 KiB/s per stream (256 KiB burst) |
| Gateway buffer | 1 MiB per stream, 16 MiB total, at most 5 consecutive relay failures before `relay_behind` (then ignored for 120 seconds) |
| Lease | 60 seconds, renewed every 20 seconds |

## Redaction

Both the Node agent and the Gateway redact lines before delivery, collapsing overlapping or adjacent matches into one `[REDACTED]` marker ([text-redaction failures](/cli/activity#text-redaction-failures)):

- **Agent and Gateway:** PEM blocks (spanning lines from `BEGIN` through `END`), credentials in URLs, `Authorization` and `Proxy-Authorization` headers, `Bearer` tokens, and values after secret-named keys (`API_KEY`, `password`, `db_password`, and similar).
- **Gateway:** stored Instance or Docker Process environment values of 8 characters or more, except the exact non-secret setting keys: `APP_ENV`, `APP_NAME`, `APP_DEBUG`, `APP_LOCALE`, `APP_FALLBACK_LOCALE`, `APP_FAKER_LOCALE`, `APP_TIMEZONE`, `APP_MAINTENANCE_DRIVER`, `APP_MAINTENANCE_STORE`, `BCRYPT_ROUNDS`, `LOG_CHANNEL`, `LOG_STACK`, `LOG_LEVEL`, `LOG_DEPRECATIONS_CHANNEL`, `DB_CONNECTION`, `DB_PORT`, `SESSION_DRIVER`, `SESSION_LIFETIME`, `SESSION_ENCRYPT`, `BROADCAST_CONNECTION`, `FILESYSTEM_DISK`, `QUEUE_CONNECTION`, `CACHE_STORE`, `CACHE_DRIVER`, `MAIL_MAILER`, `MAIL_PORT`, and `MAIL_ENCRYPTION`.

The CLI runs a final redaction pass and renders markers in lowercase (`[redacted]`). Redaction is defense-in-depth and does not catch arbitrary personal data or encoded secrets; Node access grants control who may read logs. The `websocket` Node terminates TLS for Reverb and is trusted as part of the realtime layer.

## When the live path is not available

When a live stream cannot open, the Gateway returns `409 logs.live_unavailable` with `error.details.reason`:

| Reason | Meaning |
| --- | --- |
| `ssh_only` | Production Instance application logs are read over SSH only. |
| `realtime_not_configured` | No `websocket` role is active. |
| `subscriber_down` | The Gateway's agent view subscriber is not connected. |
| `agent_unavailable` | No fresh [agent view](/reference/node-agent#freshness) for the serving Node. |
| `agent_not_joined` | The agent has not joined its log channel yet. |

For transient reasons (`subscriber_down`, `agent_unavailable`, `agent_not_joined`, `logs.stream_limit`, `agent_left`, `relay_behind`), CLI `--follow` and web panes retry every 30 seconds.

| Code | HTTP | Meaning |
| --- | --- | --- |
| `node_access.required` | 403 | Caller lacks access to the serving Node. |
| `logs.stream_not_found` | 404 | Stream does not exist, ended, or belongs to another record or opener. |
| `logs.live_unavailable` | 409 | Live path unavailable (see reasons above). |
| `process.target_unsupported` | 409 | Container Processes do not support live streams. |
| `schedule.follow_unavailable` | 409 | Schedule live follow is disabled. |
| `schedule.state_invalid` | 409 | Schedule is not active. |
| `schedule.target_unsupported` | 409 | Migrated Schedules do not support live streams. |
| `validation.failed` | 422 | Invalid `socket_id` or `lines` outside 1 through 1,000. |
| `logs.stream_limit` | 429 | Serving Node already has 16 open streams. |

## Stream tests

Agent stream tests cover real log files, redaction, symlink replacement, and journal fixture followers using a paused Tokio clock for polling and flush intervals, with bounded timeouts and stdout barriers for silence checks. Two tests that read the real journal keep real observation intervals (the live unit test is ignored by default). See [Bun and Rust projects](/reference/bun-and-rust-projects#node-agent) for test commands.

## Why it works this way

### The Gateway relays every line

Routing lines from the agent through the Gateway keeps viewer channel names and stored environment values off workload Nodes and lets the Gateway enforce access revocation centrally. Using a private per-viewer channel (`private-log-stream.<id>`) avoids leaking logs across viewers on shared Node channels.

### Observer runs read-only commands only

The Node agent reads log files directly and invokes only read-only `journalctl` with fixed arguments for systemd units, never running `tail` or mutating the host.

### Stream state in cache and bounded relay

Keeping 20-second lease renewals in cache avoids unnecessary SQLite writes, and running Reverb broadcasts outside the subscriber socket loop prevents a slow viewer or Reverb outage from stalling other Nodes. When the relay falls behind, ending the stream with `relay_behind` makes gaps explicit instead of silently dropping lines.
