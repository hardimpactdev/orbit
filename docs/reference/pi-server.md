---
title: "Pi server"
description: "How the Pi server runs Pi agent sessions on a task VM for the Gateway's pi driver: configuration, the task VM install, agent tools, API, thread states, and restart behavior."
covers:
  - apps/pi-server/**
  - apps/gateway/app/Infrastructure/Tasks/Pi/{PiConnection,PiModel}.php
---

# Pi server

The Pi server runs [Pi](https://github.com/earendil-works/pi) coding-agent sessions on a [task VM](/reference/compute-drivers#task-vms). The Gateway's `pi` driver creates sessions, starts turns, and reads transcripts through it. The [Tasks reference](/reference/tasks#drivers) describes the driver. The source lives in `apps/pi-server`. To build, run, and check it, see [Bun and Rust projects](/reference/bun-and-rust-projects#pi-server).

Orbit runs Pi only on task VMs. The Gateway installs and starts it there; see [Run Pi on a task VM](#run-pi-on-a-task-vm). No host Node runs Pi for task agents.

## Configure the server

The server reads command-line flags first, then the environment. Orbit's systemd Processes pass only arguments, so every setting has a flag. The token has only a file flag, because arguments are visible to other users and are stored in the Process definition. The server refuses to start without a bind address and a token.

| Flag | Variable | Default | Meaning |
| --- | --- | --- | --- |
| `--host` | `PI_SERVER_HOST` | required | Address to listen on, normally the Node's WireGuard address |
| `--port` | `PI_SERVER_PORT` | `3774` | Port to listen on |
| `--token-file` | `PI_SERVER_TOKEN_FILE` or `PI_SERVER_TOKEN` | required | Bearer token of at least 32 characters |
| `--agent-dir` | `PI_SERVER_AGENT_DIR` | `~/.pi/agent` | Pi's directory, which holds the provider sign-ins |
| `--session-dir` | `PI_SERVER_SESSION_DIR` | `<agent dir>/orbit-sessions` | Session transcripts and Orbit's session records |
| `--workspace-root`, repeatable | `PI_SERVER_WORKSPACE_ROOTS`, colon-separated | none | When set, a session's workspace must be inside one of these directories |
| `--allow-api-keys` | `PI_SERVER_ALLOW_API_KEYS=1` | off | Allow every provider signed in with an API key |
| `--allow-provider`, repeatable | `PI_SERVER_ALLOW_PROVIDERS`, comma-separated | none | Allow one named provider although Pi sees an API key, such as a CLIProxyAPI endpoint |
| `--idle-unload-seconds` | `PI_SERVER_IDLE_UNLOAD_SECONDS` | `900` | Unload a session with no turn and no stream after this many seconds |

The Gateway reaches the server at `http://{wireguard_ip}:{ORBIT_PI_PORT}` on the task VM, with the VM's own token. `ORBIT_PI_PORT` defaults to `3774`.

By default the server accepts only subscription sign-ins. A provider signed in with an API key, including a key in the server's environment, is not available until `PI_SERVER_ALLOW_API_KEYS=1` is set or `--allow-provider` names it. A task VM names its one provider with `--allow-provider`. The binary also has `pi-server login <provider>` for a subscription sign-in. Task VMs do not use it, because CLIProxyAPI is their only model route. `pi-server login anthropic` is refused.

The Gateway's `pi` driver refuses every Claude model, also through CLIProxyAPI. See [Claude is unavailable for task agents](#claude-is-unavailable-for-task-agents).

## Run Pi on a task VM

On a [task VM](/reference/compute-drivers#task-vms), the Gateway installs and starts Pi itself, in the `PrepareTaskVmRuntime` job. There is no manual install. Pi runs as the VM's managed user `orbit`, which has passwordless sudo. The claim creates the fresh VM's Pi token, and the job runs every step below.

The ai-router CLIProxyAPI URL must be `task_vms.model_proxy_origin`. Otherwise the job fails with `task_vm.invalid_config` before it creates a key.

1. The Gateway creates a CLIProxyAPI key for the group. It stores the key and the origin on the task VM row first. Then it registers the key with the management key of the [ai-router extension](/reference/ai-router).
2. It checks whether `~orbit/.local/bin/pi-server` already has the SHA-256 digest `task_vms.pi.artifact_sha256`. A VM from a [base image](/reference/compute-drivers#build-the-base-image) built with the same pin has it, so the Gateway skips the next step.
3. Otherwise it sends the pinned Pi executable from `task_vms.pi.artifact_path` over the Node's normal SSH connection, on standard input. The VM checks the digest and installs the executable as `~orbit/.local/bin/pi-server`.
4. It writes `~orbit/.pi/agent/models.json` with the origin in `task_vms.model_proxy_origin`, the models in `task_vms.pi.models`, and the group's key.
5. It writes the task VM's own Pi token to `~orbit/.pi/agent/orbit-token`. Both files have mode `0600`.
6. It creates the normal `pi-server` Process: a systemd service as `orbit` on the VM's WireGuard address and `ORBIT_PI_PORT`, with `--allow-provider=orbit-task-vm`, restart policy `always`, and keep-alive.

Each step is idempotent, so a retried job repeats it and keeps the key. `models.json` has one provider, `orbit-task-vm`, with the API `openai-responses` and the base URL `<origin>/v1`. Each model has `reasoning: true`. Pi's defaults apply to the rest, such as a context window of 128,000 tokens. The `pi` driver sends every model to `orbit-task-vm`.

The Gateway connects to this server with the task VM's token and removes the group's model key from transcripts as it does the token. CLIProxyAPI is the only model route, and no subscription sign-in enters the VM. Destroying the VM revokes the group's key at the origin stored on the row.

## Limits

An agent is a process of the Pi server and shares its user, `orbit`. It can read the VM's Pi token in `~orbit/.pi/agent/orbit-token` and the group's model key in `models.json`. Both are scoped to this VM and this group, and destroying the VM revokes the key. The agent has passwordless sudo inside the VM. The VM edge is the boundary; [One user for every task agent](#one-user-for-every-task-agent) explains why. The VM holds no GitHub token: the Gateway fetches and pushes for it, see [Git on a task VM](/reference/compute-drivers#git-on-a-task-vm).

## Agent tools

A Pi session has Pi's `read`, `bash`, `edit`, and `write` tools and one Orbit tool, `search_docs`. `search_docs` searches the Laravel ecosystem documentation through [Laravel Boost](https://github.com/laravel/boost). Results match the package versions installed in the Project, so the agent uses framework features that exist in those versions. Pi has no MCP client; the tool sends only the MCP messages this search needs.

| Input | Required | Meaning |
| --- | --- | --- |
| `queries` | yes | Search queries, such as `["queue middleware", "rate limit"]` |
| `packages` | no | Limit the search to these packages, such as `laravel/framework` or `pestphp/pest` |
| `project` | no | Laravel app directory relative to the session workspace, such as `apps/gateway` |

The tool chooses the Laravel app in this order:

1. The `project` directory, when the agent passes it.
2. The session workspace, when it has an `artisan` file.
3. The only `apps/*` directory with an `artisan` file and `vendor/laravel/boost`.

When several `apps/*` directories match, the tool asks for `project`.

The app needs `laravel/boost` installed, so run `composer install` in it first. The tool runs `php artisan boost:mcp` in the app with `php` from the server's `PATH`. It calls Boost's `search-docs` tool over MCP on standard input and output and returns the text. Boost runs only in a local or debug app, so the tool sets `APP_DEBUG=true` when the server's environment does not set it. The server's `PI_SERVER_*` variables stay out of that process. Agent bash and documentation lookup processes inherit the workspace's agent `TMPDIR`, separate from the setup/check runner's directory; [task checks](/reference/tasks#project-check) describes the layout. A non-Git session uses `<workspace>/.orbit/tmp/agent-<uid>`. Boost fetches the documentation from `boost.laravel.com`, so the Node needs outbound HTTPS.

The tool stops Boost after the answer, after 60 seconds, or when the turn is interrupted. A failed search returns an error result to the agent. The error names the cause, such as no Laravel app, Boost not installed or not enabled, or a timeout.

## Large tool output

A `read` or `bash` result larger than 8 KiB does not enter the model context. The server writes the full text to a file and stores a short notice in the session. The stream shows that notice. `edit`, `write`, `search_docs`, and an image `read` are unchanged.

8 KiB is 8,192 UTF-8 bytes. `read` measures the selected lines when the call sets `offset` or `limit`, and the whole file otherwise. `bash` measures stdout and stderr in the order the tool read them, without the exit line. A result of 8,192 bytes or fewer is returned in full. There is no line cap on that result.

The file is new, under the session workspace at `.git/orbit/tool-output/`. The server creates the directory with mode `0770` when `.git` is a directory and the process can create it. The file mode is `0660`. Its name starts with the tool, the session id, and the tool call id. A second result does not replace an earlier file. The file contains the measured text only. The notice uses the absolute path.

Those group bits keep a workspace ACL in force, so every user that the ACL names can read the file and delete it. The server does not change the mode of a directory it does not own.

The notice is at most 8,192 bytes:

```text
<bytes> bytes, <lines> lines, saved to <absolute path>
<preview>
View more with the read tool using offset and limit, or grep on that file.
```

`read` previews the first 20 lines. `bash` previews the last 40. Lines split on newline. A final newline does not add a line. When the preview would make the notice larger than 8,192 bytes, whole lines drop from the end of a `read` preview or the start of a `bash` preview. A line is not split. The file keeps it. The first line then adds `Preview shows N lines.`

A `bash` notice ends with `Exit code: N` when the process exits, including `0`. An abort ends with `Command aborted`. A timeout ends with `Command timed out after N seconds`. A non-zero exit, an abort, and a timeout stay failed tool results. The failure text is the notice.

The files stay on the Node until the workspace clone is removed. Idle unload and a server restart leave them in place. They are not pushed and not copied off the Node. Git ignores paths inside `.git`, so they stay out of diffs and the review workspace tree. A workspace whose `.git` is not a directory returns the full text and writes no file.

Reading the saved file follows the same rule. A slice of 8,192 bytes or fewer returns in full. When the file cannot be written, the result is an error: the byte count, the line count, and the reason. The error does not include the output. A `bash` error ends with the same exit, abort, or timeout line as a notice.

The `read` and `bash` descriptions tell the model about this limit. They name `.git/orbit/tool-output/`, the path, the counts, and the short preview.

## API

Every route requires `Authorization: Bearer <token>`. Errors return `{"error": {"code", "message", "details"}}`, the same envelope as the Gateway. Each code is namespaced by family, such as `session.not_found`. A failure the Gateway also has uses the Gateway's code, such as `http.404` and `validation.failed`. No error has details yet, so `details` is an empty object.

Older Pi server binaries send older error shapes. A binary built before this envelope answers `{"error": {"code", "message"}}` with underscore codes, such as `session_not_found`. A short-lived later build answers `http.not_found` with `"details": null`. The Gateway only quotes the code in its error message, so every binary works with it.

[Task VMs](/reference/compute-drivers#configure-task-vms) run the artifact the Gateway pins. [Roll out a new binary](#roll-out-a-new-binary) explains how to change the pin and when it takes effect.

| Route | Result |
| --- | --- |
| `GET /capabilities` | Pi version and the models this Node can run, as `provider/model` |
| `POST /sessions` | Creates a session from `id`, `cwd`, `model`, `thinkingLevel`, and an optional `appendSystemPrompt`, and returns `201`. Repeating the same create returns `200` |
| `POST /sessions/{id}/messages` | Starts a turn from `key` and `text`, and returns `202`. A repeated key returns `200` with `duplicate: true` and starts no turn |
| `POST /sessions/{id}/interrupt` | Aborts the active turn and returns `202` |
| `GET /sessions/{id}` | Session snapshot, `view=state` for state and metrics, or a [delta after a cursor](#read-after-a-cursor). Includes [token usage](#token-usage) |
| `GET /sessions/{id}/stream` | Newline-delimited JSON: a snapshot or, with `run` and `after`, a resumed start; then `entry` and `state` events, with a `heartbeat` every 15 seconds. See [Stream](#stream) |

| Error code | Status | Meaning |
| --- | --- | --- |
| `auth.unauthorized` | 401 | Missing or wrong token |
| `http.404` | 404 | No route matches the method and path |
| `session.not_found` | 404 | No session has this ID |
| `validation.failed` | 422 | A field is missing or invalid, or the workspace is outside the allowed roots |
| `model.unavailable` | 422 | The model is unknown, or its provider is not signed in with an accepted method |
| `session.exists` | 409 | A session with this ID has different settings |
| `session.turn_active` | 409 | The session is already working on a turn |
| `http.413` | 413 | The request body is larger than 1 MiB |
| `server.unhandled` | 500 | The server failed to handle the request |

The turn ID is the key of the latest accepted send. The Gateway uses a new key for each turn and reuses it when it retries a send.

## Token usage

The snapshot, the delta, and each stream `state` event include `usage`. The sums cover every assistant message that has numeric `input`, `output`, `cacheRead`, and `cacheWrite`. A message without that usage is not a call. [Thread token metrics](/reference/tasks#thread-token-metrics) describes how the Gateway stores these numbers on the agent thread.

| Field | Meaning |
| --- | --- |
| `input` | Prompt tokens that are neither a cache read nor a cache write. This field alone is not Orbit's uncached input |
| `output` | Output. A reasoning count on the message is already inside this number |
| `cacheRead` | Input read from cache. This is Orbit's cached input |
| `cacheWrite` | Input written to cache. A cache write is uncached input, so Orbit adds it to `input` |
| `total` | `input + output + cacheRead + cacheWrite` |
| `calls` | Assistant messages included in the sums |
| `peakContext` | Largest prompt on one call: `input + cacheRead + cacheWrite`. That is uncached input plus cached input. Output is excluded |

Orbit stores uncached input as `input + cacheWrite` and cached input as `cacheRead`. Per-call usage in the session file uses the same `input`, `cacheRead`, `cacheWrite`, and `output` fields. `totalTokens` on a call equals those four numbers added.

## Stream

Every stream event carries `run` and `sequence`. The run is a random ID that the server picks each time it loads a session. Sequence numbers start at 0 with each run and grow with each event. A transcript entry is streamed when Pi writes it. Pi writes an assistant message with a tool call when the call starts, and the tool result when the call ends.

Without a cursor, the stream starts with a `snapshot` event, the same as `GET /sessions/{id}`.

To resume, pass the last event the client received as `?run={run}&after={sequence}`. When the run is the session's current run and the sequence is not ahead of it, the stream starts with a `resumed` event:

```json
{"kind": "resumed", "run": "3f9c…", "sequence": 42, "session": {"id": "…"}, "context": []}
```

Then it sends the entries streamed after that sequence and the latest `state` event if it came after it, in sequence order. `context` lists the entries at or before the cursor whose tool calls have no result yet, so the client can name the results that follow.

The server sends a snapshot instead when the cursor is missing or malformed, names another run, or is ahead of the run. A cursor from before a restart or an idle unload names another run. With `max_bytes`, an oversized resumed start also becomes a bounded snapshot.

### Read after a cursor

`GET /sessions/{id}` takes the same `run` and `after` parameters as the stream. When the stream would start with a `resumed` event, the read answers a `delta`:

```json
{"kind": "delta", "run": "3f9c…", "sequence": 57, "session": {"id": "…"}, "state": "done", "error": null, "turnId": "…", "usage": {…}, "context": [], "events": []}
```

| Field | Meaning |
| --- | --- |
| `run` | The session's current run |
| `sequence` | The newest sequence of the run. Pass it as `after` on the next read |
| `session` | The session ID |
| `state`, `error`, `providerError`, `turnId`, `usage` | The session's current values, the same as in a snapshot |
| `context` | The entries at or before the cursor whose tool calls have no result yet, the same as in `resumed` |
| `events` | The `entry` and `state` events that a stream resumed from the same cursor sends after `resumed`, in sequence order. A cursor at the newest sequence gives an empty list |

The read answers a full snapshot whenever the stream would start with one. The Gateway's [agent thread read](/reference/tasks#read-a-thread) uses the delta, so each poll carries only the events after the last one. A read does not change the stream or its sequence numbers.

The Gateway sends `max_bytes` on transcript reads and streams. It must be an integer of at least 4096; an invalid value returns HTTP 422 `validation.failed`. The server uses this byte budget for snapshots. It keeps the newest complete entries that fit and prepends a `system` message with an explicit truncation marker when it omits history. If a delta exceeds the budget, the read returns a bounded snapshot instead. The cursor still names the current state.

This budget comes from the Gateway, which also bounds each read response and each NDJSON stream line before decoding. An oversized partial line fails before its newline arrives. The stream may carry any number of lines within the connection deadline; the server has no second size setting.

`GET /sessions/{id}?view=state` returns a `state` object with session ID, run, sequence, state, error, provider error, turn ID, and usage. It does not build or send transcript entries. Observation polling uses this read, so transcript growth does not interrupt state or token observation.

State-only reads and bounded snapshots require a Pi binary built with these handlers. Older binaries ignore `view=state` and `max_bytes`. The Gateway accepts their snapshot metadata without using transcript entries or retrying, and still enforces the transfer budget. Their oversized sessions remain unreadable until the Pi binary is updated. The recipe pin `e5933d4c8696` predates these handlers. Follow the [artifact rollout instructions](#roll-out-a-new-binary), then move the agent to a new task VM with the updated binary. Prepared VMs keep their existing binary until they are destroyed; updating the Gateway does not restart their agents.

A binary built before delta reads ignores `run` and `after` on `GET /sessions/{id}` and always answers a snapshot. A client tells the two answers apart by `kind`.

## Thread states

The server reports one of the Orbit thread states from its own evidence. The Gateway stores that state on the thread.

| Pi evidence | State |
| --- | --- |
| No completed turn | `idle` |
| A turn was accepted and has not settled | `working` |
| The last assistant message stopped normally | `done` |
| The turn ended with a provider error, an interruption, or the output limit | `failed`, with the error |
| The server restarted while the turn was active | `failed`, with error `The Pi server restarted during the turn.` |

Provider-error failures may include `providerError` in snapshots, deltas, and stream state events: `overloaded`, `rate_limited`, or `server_error`. It is absent for other failures. Classification reads only the last assistant message with stop reason `error`, not tool output. Structured provider error codes and HTTP status codes take precedence over a narrow fallback for known capacity messages when the SDK preserves only text.

For `openai-responses` (the API used by `orbit-task-vm`) and `openai-codex-responses`, Orbit observes HTTP responses and SSE error records before the SDK replaces them with human-readable messages. This includes streamed `error` and `response.failed` records received after HTTP 200. Orbit stores only the resulting category (or an explicit non-transient result) on the failed assistant message, so unloading or restarting the server preserves it.

Codex sessions use SSE so HTTP and streamed error codes follow the same classification path; the SDK's WebSocket transport does not expose that evidence. The `openai-responses` adapter already uses SSE; Orbit keeps its transport settings.

The boundary adds no headers, credentials, or raw error bodies to the transcript. Quota exhaustion, account usage limits, authentication errors, and refusals are not transient. The Gateway uses this field for [automatic retries](/reference/tasks#retry-a-transient-provider-error); older servers without it keep the ordinary failure path.

Pi has no approvals, so a Pi thread never asks for input. A new turn changes a `done` or `failed` state to `working`.

## Restarts

Transcripts persist as Pi session files. After a restart, the server reloads a session when it is first used. Accepted send keys persist, so a repeated send after a restart still starts no turn. The server records a turn as active before it starts and clears the record when the turn settles. A record still marked active after a restart reports `failed` with the error `The Pi server restarted during the turn.` The Gateway resumes that turn. [Recover a Pi server restart](/reference/tasks#recover-a-pi-server-restart) states how.

## Roll out a new binary

Pin the new artifact with `ORBIT_TASK_VMS_PI_ARTIFACT_PATH` and `ORBIT_TASK_VMS_PI_ARTIFACT_SHA256`; [Run Pi on a task VM](#run-pi-on-a-task-vm) shows how the Gateway installs it. A new task VM gets the binary when the Gateway prepares it at claim; the base image does not need a rebuild. A VM that is already prepared keeps its binary until it is destroyed, so no running turn restarts.

## Test worker file access

Run `bun run test` in `apps/pi-server` on Linux with `setfacl` and passwordless `sudo -n -u nobody` available. Many tests call `setfacl` to set up workspaces as a Node does, so they fail without it. The worker ACL test writes tool output as `nobody` inside a temporary checkout owned by the test runner, then checks that the checkout owner can read and rewrite it.

The worker ACL test copies the Bun runtime and the tool-output module into its temporary fixture. It invokes the runtime by absolute path with a restricted `PATH` and uses the fixture as its working directory. This keeps the worker independent of sudo's executable search path and of access to the test runner's home or source checkout. Cleanup removes both copies and the checkout; the test does not change home permissions or sudo policy.

### Fast test fixtures

Tests that provoke a provider failure disable Pi's auto-retry in their in-memory settings as well as the provider's HTTP retries. They assert one provider request, send a real streamed failure through the server, publish it, and reload it after restart, without waiting through retry backoff.

Workspace TMPDIR tests call the workspace bash tool in-process with a private inherited TMPDIR; the worker ACL test still crosses the real user boundary.

Bash output tests retain a one-second command timeout so the output can arrive before expiry. Only the abort cases wait for the command's output before cancelling it. Boost cancellation waits for the child to receive a request, and cleanup polls for process exit instead of sleeping. The busy-turn test holds the provider response until interrupt, so it does not depend on a slow token rate.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### Claude is unavailable for task agents

Anthropic permits Claude subscription credentials only in its own applications, also when a proxy such as CLIProxyAPI relays them. So the `pi` driver refuses a Claude model, `pi-server login anthropic` refuses to sign in, and task agents have no other runtime that runs Claude. [Tasks: Task agents run on Pi](/reference/tasks#task-agents-run-on-pi) explains that choice.

### A long-lived server, not RPC over SSH

A Pi process started over SSH would live only as long as that connection, and a reconnect would lose the live event stream. Reading session files over SSH gives history without live events. So one server on the Node owns the sessions and serves snapshots and streams.

### The server owns the stream cursor

The server already holds the transcript and its order, so it resumes a stream after a cursor. A cursor in the Gateway would need a store of what each browser has seen. A snapshot on every connection would cost more with every conversation and every open tab.

### Large tool output goes to a file

A large tool result fills the model context, and each later call sends that text again. Cutting the result would lose the rest for good. So a large result goes to a file that a later turn can read. The file sits inside `.git`, so diffs and the review tree never include it. When the file cannot be written, the result is an error, because inlining it would bring back the cost. The file mode keeps a workspace ACL in force, so every user that the ACL names can delete the file.

### One user for every task agent

On a [task VM](#run-pi-on-a-task-vm), one user, `orbit`, runs Pi, the agents, the setup steps, and the baseline and handoff checks. That user has passwordless sudo inside the disposable VM for setup and Project commands. Orbit's ordinary Gateway tests need no sudo; its privileged group runs only in the disposable CI job, not in task checks. The VM edge is the boundary. A second user inside the VM would protect nothing that the VM does not already protect. The VM holds no GitHub token and no subscription sign-in. This serves [security fits the real threat model](/mission#principles).
