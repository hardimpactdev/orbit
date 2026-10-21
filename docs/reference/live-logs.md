---
title: "Live logs"
description: "How a viewer tails an Instance log or a Process log live: opening, renewing, and closing a log stream, the events it receives, the limits, redaction, and the fallback to one-shot reads."
covers:
  - apps/gateway/app/Domain/Logs/**
  - apps/gateway/app/Actions/Logs/**
  - apps/gateway/app/Http/Controllers/Api/{InstanceLogStreamsController,ProcessLogStreamsController,RespondsWithLogStreams}.php
  - apps/gateway/app/Infrastructure/Logs/**
  - apps/gateway/app/Infrastructure/AgentView/LogRelay.php
  - apps/agent/src/logs/**
---

# Live logs

A live log stream shows new lines of one Instance log or one Process log as they are written. The Node agent reads the log, and the Gateway relays the lines to a private channel for one viewer. Opening a stream needs the same access as a one-shot read: an access grant to the Node that serves the Instance or Process.

The [web app](/reference/web-app#live-logs) log panes and `--follow` on [`orbit instance:logs`](/cli/instance#orbit-instancelogs) and [`orbit process:logs`](/cli/process#orbit-processlogs) use live streams. One-shot reads, `GET /api/v1/instances/{instance}/logs` and `GET /api/v1/processes/{process}/logs`, read over SSH.

## How a stream works

A client follows five steps, and the Gateway and the Node agent do the rest.

1. The client connects to Reverb, as [Realtime events](/reference/events#discovery) describes, and reads its `socket_id`.
2. The client opens a stream with its `socket_id`. The response names a private channel and signs the client's subscription to it.
3. The client subscribes to the channel with that signature.
4. When the subscription succeeds, the client renews the stream once. This first renewal starts the stream, so no line arrives before the client listens.
5. The client receives the first lines and then each new line. It renews the stream every 20 seconds and closes it at the end.

The Gateway lists the stream for the Node's agent. The agent reads the source, redacts each line, and sends the lines to the Gateway. The Gateway redacts them again and publishes them on the stream's channel. [Node agent](/reference/node-agent#log-tails) describes the agent's part.

## Open a stream

Open a stream through the record whose log you want to follow.

| Method and path | Route name | Access |
| --- | --- | --- |
| `POST /api/v1/instances/{instance}/log-streams` | `instance:log-stream:create` | The same as `instance:logs` |
| `POST /api/v1/processes/{process}/log-streams` | `process:log-stream:create` | The same as `process:logs` |

| Field | Required | Meaning |
| --- | --- | --- |
| `socket_id` | yes | The client's Reverb socket ID, such as `123.456`. |
| `lines` | no | The number of earlier lines to send first, from 1 through 1,000. The default is 100. |

The response has status 201:

```json
{
  "data": {
    "id": "3f9c2a6b0d1e4f5a8b7c6d5e4f3a2b1c",
    "channel": "private-log-stream.3f9c2a6b0d1e4f5a8b7c6d5e4f3a2b1c",
    "auth": "<reverb-app-key>:<signature>",
    "lines": 100,
    "lease_seconds": 60,
    "renew_seconds": 20
  },
  "meta": { "request_id": "..." }
}
```

The stream ID is a random 128-bit value, and the Gateway never lists it. `auth` is valid only for the `socket_id` in the request. Subscribe with it as the Pusher `auth` value. The browser auth endpoint, `POST /api/v1/broadcasting/auth`, refuses every `private-log-stream.*` channel.

## Renew and close

A stream lives for 60 seconds after it opens or after its last renewal.

| Method and path | Route name | Result |
| --- | --- | --- |
| `PUT /api/v1/instances/{instance}/log-streams/{stream}` | `instance:log-stream:renew` | Extends the lease to 60 seconds from now. The first renewal also starts the stream. Until the first line, each renewal prompts the agent again. |
| `DELETE /api/v1/instances/{instance}/log-streams/{stream}` | `instance:log-stream:destroy` | Closes the stream at once. |
| `PUT /api/v1/processes/{process}/log-streams/{stream}` | `process:log-stream:renew` | Extends the lease to 60 seconds from now. The first renewal also starts the stream. Until the first line, each renewal prompts the agent again. |
| `DELETE /api/v1/processes/{process}/log-streams/{stream}` | `process:log-stream:destroy` | Closes the stream at once. |

Each call checks the access grant again. Only the Node that opened a stream can renew or close it, and only through the record it was opened for. A renewal returns `{ id, lease_seconds }`, and a close returns `{ id, closed: true }`. Renewals do not record Activity.

Every 5 seconds the Gateway ends each stream whose lease ended, with `expired`, and each stream whose opening Node lost its access grant to the serving Node, with `revoked`.

## Receive events

The Gateway publishes two server events on `private-log-stream.{stream}`, with the envelope of [Realtime events](/reference/events#envelope). `id` is the stream ID. A client ignores every `client-*` event on the channel.

| Type | When | Data |
| --- | --- | --- |
| `log.lines` | The first lines, and then new lines, at most every 250 milliseconds | `{ sequence, lines, dropped, skipped }` |
| `log.ended` | The stream closed | `{ reason }` |

| Field | Contract |
| --- | --- |
| `sequence` | Increases by one with every `log.lines` event of the stream, from 1. After a failed relay run, the Gateway can send a part again with the same `sequence`; ignore a `sequence` you already have. |
| `lines` | Redacted log lines, oldest first, without line endings. |
| `dropped` | Lines dropped by a rate limit since the previous event. Show it as `[orbit] 120 lines dropped`. |
| `skipped` | Bytes the agent skipped because a file source fell more than 4 MiB behind. Show it as `[orbit] 5.0 MiB skipped`. |
| `reason` | `closed`, `expired`, `revoked`, `agent_left`, `source_unavailable`, or `relay_behind`. |

| Reason | Meaning | Client action |
| --- | --- | --- |
| `closed` | The client closed the stream. | None. |
| `expired` | The lease ended without a renewal. | Open a new stream to keep watching. |
| `revoked` | The opening Node lost its access grant to the serving Node. | Stop. |
| `agent_left` | The Node's agent left its log channel, for example because it stopped, or it had no stream list for 60 seconds. | Fall back to one-shot reads. |
| `source_unavailable` | The agent could not open or keep reading the source. | Fall back to one-shot reads. |
| `relay_behind` | The Gateway could not relay the lines fast enough, for example because Reverb was slow. It dropped the lines that waited. | Fall back to one-shot reads. |

## Sources

Each record has one source, the same one that its one-shot read uses.

| Record | Source | Lines |
| --- | --- | --- |
| Instance | `storage/logs/laravel.log` in the Instance's application directory (inside the checkout or selected release), or the newest `laravel-*.log` when it is absent, as [Instance logs](/reference/instance-logs#know-which-file-the-gateway-reads) describes | Each line of the file |
| systemd Process | The journal entries that `journalctl --unit orbit-process-{id}-{name}.service` selects: the unit's own entries, systemd's messages about the unit, messages that root services log about it, and its core dumps | `2026-09-25T10:15:02+00:00 host name[pid]: message`, as `journalctl --output short-iso --utc` prints it |
| Docker Process | The output of container `orbit-process-{id}-{name}` | Each line of standard output and standard error, in the order the container wrote them |

The agent follows a daily log file to the next day's file. When an earlier file becomes the newest again, it continues where it left that file, so no line is sent twice. It starts from the beginning of a file that was truncated.

The agent follows a systemd Process with `journalctl --unit {unit} --output short-iso --utc`, the command of the one-shot read over SSH. So both reads select the same entries, and each journal line is the same text in both. `journalctl` is part of systemd on every supported Ubuntu Node. When it is missing, or it stops while the agent follows, the stream ends with `source_unavailable`.

The agent first reads the cursor of the unit's newest entry. It reads the first lines from that entry backwards and then follows from the next entry, so an entry written in between is sent exactly once.

The agent makes at most 256 KiB of lines from one journal entry, one stream's queue, and keeps at most 512 KiB of one printed line, so a huge message cannot exhaust its memory. A longer entry ends with the line `[orbit] message cut at 256 KiB`. A single line longer than 256 KiB is cut there too, before a character rather than inside one. The one-shot read cuts each entry by the same rule, so a follow can switch between the reads at any entry without a gap.

The one-shot read of a systemd Process reads the journal newest entry first and stops after 4 MiB, because `journalctl --lines` counts entries and one entry can hold millions of lines. It cuts each entry at 256 KiB before that limit, so a huge entry does not hide the older entries. The one-shot read of a Docker Process also returns both streams in one, in the same order.

The agent refuses a log file that `root` owns, a link at `storage/logs` or at the log file, and a container without the labels `orbit.managed=true` and `orbit.process.id={id}`. The stream then ends with `source_unavailable`.

## Limits

The agent and the Gateway bound every stream, so a busy log cannot exhaust either of them.

| Limit | Value |
| --- | --- |
| Open streams | 16 for each serving Node |
| First lines | `lines`, at most 256 KiB. They do not count against the rates below. |
| Line length | 8 KiB; a longer line is cut and ends with `[truncated]` |
| Events | At most one `log.lines` event every 250 milliseconds from the agent, split into events that fit a Reverb request of 10,000 bytes |
| Rate from the agent | 32 KiB per second for each stream, with a 256 KiB burst, and 256 KiB per second for each Node |
| Rate from the Gateway | 64 KiB per second for each stream, with a 256 KiB burst |
| Waiting lines in the Gateway | 1 MiB for each stream and 16 MiB in all, and at most five failed relay runs in a row |
| Lease | 60 seconds, renewed every 20 seconds |

The 8 KiB counts the line's UTF-8 bytes, so a line of accented letters, CJK characters, or emoji keeps its full length: the Gateway sends Reverb each character as UTF-8, not as an escape. A line of many quotes or backslashes can be cut shorter than 8 KiB, because Reverb's request carries each quote and backslash twice and one line must fit one event.

Lines above a rate are dropped and counted in `dropped`. The agent never queues more than one burst for each stream. The Gateway queues lines only while a relay run is slow or failing, up to its limit for waiting lines; past it, the stream ends with `relay_behind`. For the next 120 seconds the Gateway ignores every event for that stream. A flood therefore cannot grow the agent's or the Gateway's memory.

## Redaction

The agent redacts each line before it leaves the Node. The Gateway redacts it again before it publishes it. See [text-redaction failures](/cli/activity#text-redaction-failures) for the shared failure rule.

| Where | What is replaced with `[REDACTED]` |
| --- | --- |
| Agent and Gateway | PEM blocks, credentials in URLs such as `https://user:pass@host`, `Authorization` and `Proxy-Authorization` header values, `Bearer` tokens, and the value after a secret-named key, such as `API_KEY=...`, `"password": "..."`, or `db_password: ...` |
| Gateway | Each stored environment value of the Instance, or each environment value of a Docker Process, of eight characters or more, except the values of setting keys |

The setting keys are exactly `APP_ENV`, `APP_NAME`, `APP_DEBUG`, `APP_LOCALE`, `APP_FALLBACK_LOCALE`, `APP_FAKER_LOCALE`, `APP_TIMEZONE`, `APP_MAINTENANCE_DRIVER`, `APP_MAINTENANCE_STORE`, `BCRYPT_ROUNDS`, `LOG_CHANNEL`, `LOG_STACK`, `LOG_LEVEL`, `LOG_DEPRECATIONS_CHANNEL`, `DB_CONNECTION`, `DB_PORT`, `SESSION_DRIVER`, `SESSION_LIFETIME`, `SESSION_ENCRYPT`, `BROADCAST_CONNECTION`, `FILESYSTEM_DISK`, `QUEUE_CONNECTION`, `CACHE_STORE`, `CACHE_DRIVER`, `MAIL_MAILER`, `MAIL_PORT`, and `MAIL_ENCRYPTION`. Their values, such as `production`, would otherwise hide ordinary words in the log. The list names each key in full, so a secret under a similar key, such as `LOG_SLACK_WEBHOOK_URL`, stays redacted. Hosts and URLs, such as `APP_URL` and `DB_HOST`, stay redacted too.

A PEM block that spans lines is redacted from its `BEGIN` line through its `END` line. When no `END` line arrives, the rest of the stream stays hidden. The agent keeps only the open-block state, without buffering the key body.

Redaction is a safety net. It misses a secret with no recognizable shape or key name, and a secret split across lines or encoded. It keeps an environment value shorter than eight characters or stored under a setting key. It also misses personal data, such as email addresses and customer records in exception messages. Access to the serving Node decides who may read a log.

The `websocket` role Node sees every log line in plain text, because TLS ends there. Orbit trusts that Node as part of the realtime layer, as it does for every other realtime message.

The CLI redacts credential-shaped text again before it prints a line, and it writes `[redacted]` in lower case. A line in the terminal can therefore show both forms. `[REDACTED]` marks a value that the agent or the Gateway replaced. `[redacted]` marks one that the CLI replaced, for example in `API_KEY=[redacted]`, where the CLI matched the already redacted `API_KEY=[REDACTED]` again.

## When the live path is not available

The Gateway refuses to open a stream with `logs.live_unavailable` (409) when it cannot serve one. `error.details.reason` says why.

| Reason | Meaning |
| --- | --- |
| `ssh_only` | The record is a production Instance. Its log is outside the directory the agent may read, so it is read over SSH only. |
| `realtime_not_configured` | No `websocket` role is active. |
| `subscriber_down` | The agent view subscriber is not running or not connected. |
| `agent_unavailable` | The Gateway has no fresh [view](/reference/node-agent#freshness) of the serving Node, for example because its agent is stopped. |
| `agent_not_joined` | The Node has a fresh agent view, but the agent has not joined its log channel yet, for example while it reconnects during a `websocket` move. |

Clients then use one-shot reads over SSH: the web app polls every 10 seconds, and `--follow` in the CLI polls every 5 seconds. A one-shot read returns at most 4 MiB of whole lines. They do the same when the Gateway refuses a stream with `logs.stream_limit`.

Six reasons pass on their own: `subscriber_down`, `agent_unavailable`, `agent_not_joined`, `logs.stream_limit`, and the ends `agent_left` and `relay_behind`. For these, the web app and the CLI try to open a stream again every 30 seconds while they poll. They keep polling for `ssh_only`, `realtime_not_configured`, and `source_unavailable`.

| Code | HTTP | Meaning |
| --- | --- | --- |
| `validation.failed` | 422 | `socket_id` is missing or not a Pusher socket ID, or `lines` is outside 1 through 1,000. |
| `logs.live_unavailable` | 409 | The live path is not available. See the reasons above. |
| `logs.stream_limit` | 429 | The serving Node already has 16 open streams. |
| `logs.stream_not_found` | 404 | The stream does not exist, has ended, belongs to another record, or was opened by another Node. |
| `node_access.required` | 403 | The caller has no access grant to the serving Node. |

## Stream tests

The agent's Laravel controller integration reads real log files, checks redaction, and ends the stream when a link replaces the source. It waits for each expected frame with a bounded Tokio timeout, using the paused clock to advance polling and flush intervals without waiting for wall time.

Journal fixture tests flush until the expected output arrives within a bounded Tokio timeout instead of sleeping for startup. To check silence, they send a barrier through the follower's real stdout pipe and the line sink. Receiving it proves that earlier records were consumed; unexpected lines or an early stream end still fail the test. Process cleanup checks wait until the child has been reaped. Large-entry fixtures build their text without wide `awk` formatting, which exceeds the default buffer on some Nodes.

Both real-journal tests keep their real observation intervals because they have no fixture barrier. The test that follows a real unit remains ignored by default. [Bun and Rust projects](/reference/bun-and-rust-projects#node-agent) lists the agent test commands.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### The Gateway relays every line

The agent sends lines only to the Gateway, on a channel that only the agent and the Gateway join. The Gateway checks each batch against its open streams, redacts it again with the stored environment values, and publishes it to one viewer. If the agent published to viewer channels itself, it would learn viewer channels and need the environment values on the Node, and revocation would depend on every agent. That alternative is rejected.

### A private channel for each viewer

Every browser with Gateway access joins `presence-node.{id}`, so log lines never go there. One shared log channel for each Node is also rejected, because a viewer of one Instance would receive the logs of every other Instance on that Node.

### The agent reads only what the Gateway lists

A `log-streams.changed` event carries no data. It only prompts the agent to fetch its list over HTTPS. So a forged or lost event cannot start a read, and the agent never acts on the content of a channel message.

### The agent runs no program that changes the Node

The agent is visibility-only. The only program it runs is read-only `journalctl`, with fixed arguments, to follow a systemd Process. `journalctl` changes nothing on the Node, and the Gateway already runs it over SSH for one-shot reads. So the live stream and the one-shot read share one command and one output, and their lines match on every systemd version.

Reading the journal through `libsystemd` is not possible, because the agent is a static musl binary and cannot load it. An earlier agent read the journal files with its own reader. It was rejected, because it had to follow the file format, its compression, and file rotation, and it still printed some rare entries differently from `journalctl`. The agent reads Instance log files itself and never starts `tail`.

### Production Instances stay on SSH

A production Instance lives in its own home, and the agent's unit hides every home except the development Instance root. Widening that unit would expose every production `.env` file that its owner left readable. A tail runs only while someone watches, so SSH reads are enough for production.

### One-shot reads stay on SSH

A one-shot read happens only on request and does not repeat. Serving it from the agent would need a request and response path through Reverb for no gain. The live stream sends its own first lines, so opening a pane costs no SSH read.

### Stream state in a cache, not SQLite

Each viewer renews every 20 seconds. Writing those renewals to the central SQLite database would load it for no lasting value. So streams live in the agent view's file cache and rebuild when a viewer renews.

### Relay outside the socket loop

The subscriber's socket loop only records what arrives. A child process does every database read and broadcast, so a slow Reverb or database never stalls the agent view for other Nodes. Serving the tail as server-sent events from the Gateway is rejected too, because each stream would hold a PHP-FPM worker.

### End a stream instead of dropping old lines

When the relay falls behind, it ends the stream with `relay_behind`. The viewer then falls back to SSH reads, which show the current tail. Dropping the oldest lines silently is rejected, because the viewer would miss lines without knowing it.
