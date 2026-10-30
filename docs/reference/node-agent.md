---
title: "Node agent"
description: "What orbit-agent observes on a managed Node, how the Gateway runs typed commands through it, how it connects and authenticates, how the Gateway delivers and installs it, and how Doctor checks it."
covers:
  - apps/agent/**
  - apps/gateway/app/{Infrastructure,Domain}/AgentView/**
  - apps/gateway/app/Infrastructure/Nodes/{NodeAgentSshExecutor,NodeAgentFootprint}.php
  - apps/gateway/{app/Infrastructure/AgentCommands/**,database/migrations/2027_01_02_000000_add_agent_failed_pin_to_nodes.php}
  - apps/gateway/app/{Http/Controllers/Api/AgentRealtimeController,Actions/Gateway/BootstrapGatewayAction}.php
  - apps/gateway/app/Http/Middleware/RequireNodeAgentSecret.php
  - apps/gateway/database/migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php
---

# Node agent

The Gateway reuses its bounded TLS WebSocket transport to read [Swamp run output](/reference/tasks#swamp-run-output), with a separate read-only credential. This read contacts Swamp on Services directly; it sends no command to the Node agent or task VM. The observer keeps its existing message limits.

`orbit-agent` is a small Rust program on every managed Linux Node. It observes Node state, runs typed commands, and supplies fixed service entry points.

The **observer** is a long-running service. It reports that it runs, the state of the Node's Orbit Processes, and the Git state of the Node's task checkouts. While someone watches a log live, it also reads that log and sends the lines to the Gateway. The web app shows these reports live. The Gateway keeps a [view](#gateway-view) of them to skip repeated SSH reads.

**Command mode** runs one named Node action, such as `agent.install`, and exits. The Gateway starts it over SSH, or as a local process on its own Node. See [Commands](#commands).

Fixed **service entry points** run under their own Linux systemd units. `service schedules-run <uuid>` reads the protected Schedule command file and executes its project command as the service user. `service schedules-complete <uuid>` runs as root, reads only the pinned CA, and reports completion. It runs no project code and reads no agent secret. [Schedules](/reference/schedules#host-artifacts) owns their units and execution context.

The observer listens on no port. Nothing reaches command mode over the network: only a process that SSH or the Gateway starts runs it. To build and check the agent, see [Bun and Rust projects](/reference/bun-and-rust-projects#node-agent).

`swamp.start-worker` starts a task VM worker through fixed systemd runtime files and protected stdin input after its workspace is ready. `swamp.stop-worker` stops that service and its children, then removes its runtime unit. Worker command errors use the `swamp.*` namespace; the Gateway reports task-operation failures as `tasks.*`. `image.trust-ca` installs only the public Orbit CA in the image trust store. These commands expose no caller program or shell. [Task worker enrollment](/reference/tasks#optional-swamp-worker) owns their credential lifetime.

`process.converge-container` accepts an optional `health` object (`url`, `attempts`, `interval_seconds`) for a running container. It validates the HTTP URL and bounds the check to 1–90 attempts, at most 2 seconds apart. The Process lock covers activation, readiness and rollback. The agent retains the previous container until the check succeeds, including when recovering an interrupted replacement. A failed check restores the previous container before returning `process.health_failed` with `restored: true`; failed restoration reports `process.recovery_required`. New and unchanged containers also run the check. Existing callers can omit it.

An optional `expected_spec` refuses replacement before mutation when the current container is missing or has a newer definition. A completed desired spec is accepted for replay. With `expected_spec`, an optional `expected_running` also guards the current state outside interrupted rollback recovery. Retained rollback containers must match the expected spec.

## Package-tool account boundary

`package.prepare-account` creates the fixed Linux system account `orbit-swamp-package`. It creates `/var/lib/orbit-swamp-package/jobs` under root ownership with mode 0700. Its typed input names a worker account that must already exist and must not be root. Package and worker UIDs must differ. The package account has its own primary group, no supplementary groups, `/nonexistent` as home and `/usr/sbin/nologin` as shell. UID and GID come from the Node's system-account allocator; they are returned by the command, never fixed fleet-wide.

An existing account must match that complete shape. Existing workspace parents must be root-owned directories with mode 0700; symlinks, writable ancestors and an unrelated account are refused. Preparation grants no sudo, socket, worker-cache, token, datastore or vault access. Worker convergence owns its own account and credentials; this primitive never changes them.

### Isolated execution

`package.session` accepts only admitted package manifests, a fixed package tool, relative working directory and literal arguments. The bounded binary input carries a JSON manifest map (64 MiB total, 512 files); it never expands the 4 MiB command envelope. It stages a private job under `/var/lib/orbit-swamp-package/jobs`, runs package code as `orbit-swamp-package`, and returns bounded manifest/lockfile output. Package code has a private root, PID and IPC namespaces, no capabilities or sudo, and no host home, worker credential/cache, Swamp datastore or credential mounts. `/proc` contains only that job's processes, so host `/proc/<serve-pid>/root` is unavailable. The trusted caller keeps GitHub credentials for git/gh; the package environment contains none.

### Required enforcement

Linux x86_64 requires systemd 257 or later (`PrivatePIDs`), `openat2`, cgroup-v2 network filters and a root-owned public toolchain at `/opt/orbit/swamp-package-runtime`. A trusted launch guard proves native seccomp syscall denial, PID 1, NoNewPrivileges and zero capabilities, then waits until the root supervisor verifies distinct PID/IPC/mount namespaces and attached ingress, egress and IPv4/IPv6 bind filters. Unix sockets, socketpair, io_uring and non-native syscall ABIs are refused. Missing enforcement refuses before package code starts. Package jobs use public DNS.

Network filters deny loopback, private fleet endpoints, CGNAT (`100.64.0.0/10`) and every assigned host address, including global IPv6. Root-owned `network-deny.json` in the runtime directory names additional public or NAT addresses as `{"public_addresses":["80.47.233.206"]}`; missing or unsafe metadata refuses execution. `SocketBindDeny=any` blocks listeners, and the trusted guard proves that both IPv4 and IPv6 bind calls fail.

npm uses distinct, empty, root-owned user and global configuration files inside the package root. No host npm configuration is mounted.

Only the active PHP version’s `/etc/php/<version>/cli` configuration is copied into the private root, with selected INI links resolved; pool configuration stays outside the job. Unsupported platforms refuse; there is no execution as `orbit` fallback.

Every package command belongs to the calling native host service's lifetime. Cancellation stops the entire package cgroup, including `setsid` descendants. Before staging, export or removal, the root launcher stops and verifies the previous package cgroup. File transfer uses directory descriptors and rejects symlinks, magic links and hard links. The dedicated UID has no supplementary groups or sudo rule. The managed `orbit` administrator still needs its existing root command authority; it never runs package code.

The native package-boundary CI job runs the real delivered agent on systemd. It creates synthetic protected files, proves their host-side existence, and checks their denial in the package root and through the host process alias. It also checks actual sudo denial, missing prerequisites, the private-PID guard, timed cgroup termination and cancellation of detached descendants. Missing test prerequisites fail the job; this suite is not opt-in.

Persistent Node workers share `swamp.start-worker` and `swamp.stop-worker` with task VMs. The Node profile requires the root-owned converge opt-in and runs as its dedicated non-root account. `swamp.issue-node-worker` alone mints the protected 24-hour pair on the Linux issuer; it is excluded from the compiler and Operation transports and refuses keyed effects. See [per-Node workers](/reference/node-provisioning#per-node-swamp-worker) for activation gates.

## Where it runs

The Gateway installs the observer only on active managed Linux Nodes with a WireGuard address and a pinned SSH host key. Only those Nodes hold an agent secret. [Exporter selection](/reference/metrics#exporter-selection) uses the same boundary. The observer needs no role.

A macOS Node uses the command binary as its enrolled account. Enrollment installs it through `agent.install`, then calls `node.inspect-macos-enrollment` to read the native architecture and verify the existing WireGuard address. The command reads kernel identity and interface addresses without a shell or network change. macOS has no observer, agent secret, or LaunchAgent.

## What it observes

The agent watches two sources and reports each Orbit Process unit or container by its full name, such as `orbit-process-42-web`.

| Source | What it watches | Reported `runtime_state` |
| --- | --- | --- |
| systemd over D-Bus | Units named `orbit-process-*.service` | The unit's `ActiveState`, such as `active`, `inactive`, or `failed`. A missing unit reads as `inactive`. |
| The Docker socket `/run/docker.sock` | Containers named `orbit-process-*` | The container state, such as `running`, `exited`, or `restarting`. A missing container reads as `exited`. |

The values use the same words as the `runtime_state` field of the Process API. The agent does not know which Process owns a name. The Gateway matches the name to a Process, so a candidate container such as `orbit-process-42-web-candidate` never matches. The agent does not read Schedules, role services, CPU, or memory.

Without Docker, the agent watches systemd only and reports Docker as `absent`. It checks for the socket every 10 seconds and sends a new snapshot when it appears. When the Docker event stream ends, the agent reconnects and sends a new snapshot. It merges changes to one unit that arrive within 250 milliseconds and sends only the latest state. It sends a heartbeat every 5 seconds and a complete snapshot at least every 60 seconds.

## Task workspaces

The agent reports the Git state of each [task](/reference/tasks) checkout on its Node, so the Gateway can skip `git` over SSH. It reads only Git metadata, never file contents or file names, and it starts no program.

### Watch list

The agent asks `GET /api/v1/agent/workspaces` which checkouts to watch, at start and every 60 seconds:

```json
{
  "data": [
    {
      "instance_id": 31,
      "path": "/home/orbit/apps/orbit/task-58",
      "base": "main",
      "start": "9f2c4be07d1a6c35e8f0b2a4d6c8e0f1a3b5c7d9"
    }
  ],
  "meta": { "request_id": "..." }
}
```

The list holds the Instances on the caller's Node that hold the workspace of an unfinished task: `backlog` or `todo` with an Instance, `reserved`, `running`, `reviewing`, or `settling`. `base` is the Project's default branch. `start` is the Instance's starting commit, or null. The list is empty while Tasks is disabled, and it holds at most 64 entries.

The agent accepts only a normalized absolute path of at most 4,096 bytes that opens as the root of a Git work tree. A linked worktree works. It never opens a path that the list does not name, and never opens a submodule.

### Reading Git

The agent reads Git with libgit2 in its own process, which runs no hooks, filters, or `fsmonitor` programs. The checkouts belong to `orbit` and the agent runs as `root`, so the agent turns off libgit2's owner check.

Every 2 seconds, the agent checks the size, time, and inode of `HEAD`, `index`, `packed-refs`, the current branch ref, the local base ref, and `origin/base`. When one changed, it reads the checkout again. It also reads every checkout every 30 seconds, because an edit to a working file changes none of those files.

| Field | Meaning |
| --- | --- |
| `instance_id`, `base`, `start` | The values from the watch list. |
| `branch` | The current branch, or null when `HEAD` is detached. |
| `head` | The full `HEAD` commit, or null in an empty repository. |
| `dirty` | `true` when the index or working tree differs from `HEAD`, untracked files included. Null when the agent cannot read the working tree. |
| `commits` | Commits reachable from `HEAD` but not from `start`, at most 1,000. Null without `start`. |
| `diff` | `{ files, added, removed, truncated }` as `git diff --numstat origin/base...HEAD` counts it. Null when `origin/base` does not resolve. |

| Limit | Value |
| --- | --- |
| Changed files in `diff` | 5,000. A larger diff has an exact `files` count, no line counts, and `truncated: true`. |
| Lines counted per file | Files up to 1 MiB. A larger or binary file counts as changed with no lines. |
| Submodules | A changed submodule counts as one file with no lines. |
| Branch name | 255 characters. A checkout on a longer branch is left out. |
| libgit2 memory | 8 MiB object cache and 32 MiB mapped pack window. |

A checkout that the agent cannot open or read is left out of its reports, and the Gateway reads it over SSH. The agent publishes the state as `client-workspaces` and `client-workspace` events. [Realtime events](/reference/events#events) defines them.

## Log tails

The agent reads an Instance log or a Process log only while a viewer watches it through a [live log stream](/reference/live-logs). It sends the lines only to the Gateway.

### Stream list

The agent asks `GET /api/v1/agent/log-streams` which logs to read. The list holds at most 16 active streams whose source is on the caller's Node. Each entry has an `id`, a number of first `lines`, and a `source`. The agent fetches the list when it joins its log channel, when the Gateway publishes `log-streams.changed` there, and every 15 seconds while it reads a stream. The event carries no data, so the agent reads only what the HTTPS response names. It stops every stream that the list does not name.

When it cannot fetch the list for 60 seconds, the agent stops every stream and ends each one with `client-log-end` and reason `list_unavailable`. It never reads that stream again.

### Sources

The agent checks each source itself. For a `journal` source it runs read-only `journalctl`; for the other sources it runs no program.

| Type | Fields | What it reads | What it refuses |
| --- | --- | --- | --- |
| `laravel` | `path`: the Instance's [application directory](/reference/projects#application-directory) | `storage/logs/laravel.log`, or the newest `laravel-*.log`. It follows a daily file to the next day's file. | A path that is not normalized and absolute. A link at `storage/logs` or at the file. A file that is not regular, or that `root` owns. |
| `journal` | `unit` | The output of `journalctl --unit {unit} --output short-iso --utc`: the unit's entries, systemd's and root services' messages about it, and its core dumps. | A unit that is not `orbit-process-{id}-{name}.service`. |
| `docker` | `container`, `process_id` | The container's output through the Docker Engine API. | A name that is not `orbit-process-{id}-{name}`, and a container without the labels `orbit.managed=true` and `orbit.process.id` equal to `process_id`. |

Refusing a file that `root` owns means the agent reads only files that other users may read, inside the Instance root that its unit binds. So a link in a checkout cannot point the read at another user's file. When the agent cannot open or keep reading a source, it ends the stream with `source_unavailable`.

`journalctl` is part of systemd on every supported Ubuntu Node, at `/usr/bin/journalctl`. The agent starts it with no environment and no input, and stops it when the stream stops, so no `journalctl` outlives its stream. When the agent itself stops, systemd stops any `journalctl` that is left, because it runs in the agent's unit. [Live logs](/reference/live-logs#sources) describes how the agent hands over from the first lines to the follow and how it cuts a long entry.

The agent redacts each line with the Gateway's secret patterns before it sends it. [Live logs](/reference/live-logs#redaction) lists the patterns and the handling of unclosed PEM blocks. Both implementations scan in linear time and test the same expected bytes from `apps/agent/tests/redaction_cases.json`, including multiline text and long repeated inputs.

| Limit | Value |
| --- | --- |
| Streams | 16 |
| First lines | The stream's `lines`, at most 256 KiB |
| Line length | 8 KiB. A longer line is cut and ends with `[truncated]`. |
| Events | One `client-log` event per stream every 250 milliseconds at most, each under 10,000 bytes |
| Rate | 32 KiB per second per stream, with a 256 KiB burst, and 256 KiB per second for the agent |
| Read ahead | 1 MiB per read. A file more than 4 MiB behind skips to its newest 64 KiB. |
| Journal | One `journalctl` per stream. The agent keeps at most 512 KiB of one printed line and makes at most 256 KiB of lines from one entry. |

The agent drops a line that exceeds a rate and reports the count in its next event. It sends log events on a second presence channel, `presence-node-logs.{id}`, as member `agent.{id}`. Only the agent and the Gateway's subscriber join it. [Realtime events](/reference/events#log-channels) defines the events.

Gateway bootstrap configures its own observer after its web service and private DNS are ready. It uses the same agent runtime as Node convergence, records the secret hash, and fails at `agent-install` if the observer cannot be configured. Repeating bootstrap converges that same service.

## Commands

The Gateway runs every Node action through one runner, `AgentCommandRunner`. The recorded Gateway serving Node uses local execution, including during role reconvergence. Before bootstrap records that identity, the Node whose Gateway role serves the site runs locally. Other Nodes use pinned SSH. It sends a command name and typed input, never program source.

```text
[sudo -n] /usr/local/bin/orbit-agent command <name>
```

A Gateway architecture check scans core and extension PHP for process, SSH, and agent transport dependencies. Only the command transports, Gateway control-plane processes, command rendering, and the excluded Tasks and private DNS paths may use those dependencies. New Node behavior must call `AgentCommandRunner`; adding another executor fails the check.

The `php.configure-runtime` command accepts exactly the six managed OPcache directives and two positive PHP size quantities at most 1 GiB. The upload limit must not exceed the POST limit. An absent FPM binary makes the command a no-op before any file write. It verifies all eight values with `php-fpm -i`, then reloads valid active masters when the module changes. See [request and upload limits](/reference/php-runtime#request-and-upload-limits).

Development profile inspection and URL writing take the Node’s recorded managed username. Each command resolves the account and checks file ownership locally; the Gateway makes no separate account lookup for these actions.

Standard input starts with one JSON envelope line. Standard output carries one JSON result. Redacted logs go to standard error.

Commands with binary input declare an `input_max_bytes` bound in the exported contract. The envelope's `stream_bytes` gives the exact number of raw bytes after its newline. The agent checks its pin before reading them. The supervised process receives them into an unlinked private file and checks the exact size and end of input before invoking the command. Short input, excess bytes, and failed reads refuse with `agent.binary_input_invalid`. Git packs, SQLite snapshots, and source archives remain binary; their bytes never enter JSON, logs, or diagnostics. Each domain command validates and consumes its own data.

The Gateway sends the envelope line followed by the staged file through one process input stream. It keeps the file open and rewinds it after a version mismatch, without making another disk copy. The caller owns the private staging file and leaves its contents unchanged until the command finishes. Transfer archives stay in `storage/app/transfer-staging`, including during upload.

Read commands can declare bounded binary output before their result. Each chunk starts with one JSON header line, `{"stream_bytes":N}`, followed by exactly `N` raw bytes. A chunk is at most 64 KiB, and the exported contract sets the total byte limit. The runner streams each chunk to a private sink, then requires the final typed result. It rejects partial chunks, excess bytes, and output after that result. A mismatch precedes all binary data; a partial transfer is never retried.

A binary producer enforces its deadline and stops its children before returning the final result. The dispatcher cannot write a timeout result over an unfinished binary frame. If the consumer stops reading, the final write gets at most one second to drain before the agent exits with a transport failure.

| Envelope field | Meaning |
| --- | --- |
| `agent_sha256` | The SHA-256 of the binary that the Gateway pins for this Node |
| `deadline_ms` | The time left for the call. The command's own bound applies too |
| `stream_bytes` | Exact binary body size; required only for commands that declare binary input |
| `input` | The command's typed input. Unknown fields refuse with `agent.invalid_input` |

The result is `{"ok": true, "data": {...}}` or `{"ok": false, "error": {"code": ..., "message": ..., "details": {...}}}`. A valid result exits 0, a refusal included.

### The frozen check

The process that executes a command first checks the SHA-256 of its own executable against `agent_sha256`. It does this before it looks up the name, parses the input, or reads binary bytes. On a mismatch it answers `agent.version_mismatch` with the expected and observed digests. From the first released command-mode agent, the envelope line framing, its hash field, this check, and that answer never change between versions. Every released command-mode agent answers a newer Gateway in a way the Gateway can act on. Unpublished SPIKE prototypes are reconverged before this contract is frozen.

### Privilege and supervision

Each command declares one privilege per platform. The runner reads it from the contract, never from caller input. The command checks its effective identity before any change.

Linux keeps the managed account's existing `NOPASSWD:ALL` sudo rule. The named-command boundary is a code rule, enforced by architecture tests and command definitions. It does not narrow that account's OS authority. Commands use filesystem and OS APIs or fixed executable arguments. Project hooks, Git, builds, and package tools run as their recorded owner, without root trust files or secrets in their input or environment.

| Privilege | Linux | macOS |
| --- | --- | --- |
| `account` | The managed account | The enrolled account |
| `owner` | Starts as root through `sudo -n`, and drops to the recorded owner before project code | The enrolled account |
| `root` | `sudo -n` | Not declared, so the command refuses with `agent.platform_unsupported` |

A read runs in the started process and takes no lock. On Linux, a mutation runs in its own transient service, `orbit-command-<name>-<random>`, with `KillMode=control-group` and `RuntimeMaxSec` set to its bound. Its control group holds every child, so a daemon or `setsid` child stops with the command. The supervised process checks its own executable again. It takes an exclusive `flock` on its target, when the command has one, then on each shared resource in sorted order, then on the update lock when it needs it. Lock files are close-on-exec, so project code never inherits one.

Each lock records the transient service's control group before the command starts its work. A successor first takes the file lock, then waits for that previous control group to become empty or disappear. This keeps surviving children protected while systemd stops them, including after the command process crashes. A lock wait ends 250 milliseconds before the command's deadline, so a busy lock refuses with its own code, not with `agent.deadline_exceeded`. An unreadable or invalid control-group record refuses with `agent.lock_unavailable`; a lock or group that stays busy until then refuses with `agent.target_busy`.

Target and resource locks live in `/run/orbit-agent/locks/<uid>`, one directory per account. The root process that starts the mutation creates it, owned by the account with mode 0700, and passes it to the service in `ORBIT_AGENT_LOCK_DIR`. The supervised process refuses with `agent.lock_unavailable` unless it owns that directory and no one else can enter it. It opens lock files without following links. Only root writes `/run`, so no other account can create, hold, or replace these locks.

A root command and an account command on the same target do not exclude each other. Commands that must serialize a shared mutation use the same privilege and lock key. So both `.env` writers, `instance.write-environment` and `instance.write-development-url`, are owner commands: root holds the `environment:` lock, and the write runs as the file's owner.

Every root command that runs `apt-get` or `dpkg` holds the shared `packages` resource. Every command that changes UFW rules holds `firewall`. A mutation with neither a target nor a resource changes only state that one Gateway operation owns at a time, such as one Instance's checkout during its own deploy.

### The contract

A command name is `<namespace>.<action>`, and an error code is `<namespace>.<reason>`. A namespace is singular, as in the CLI: `instance.inspect-release` refuses with `instance.release_invalid`. An action can name a collection, such as `incus.images`. Four namespaces end in `s` but are not plurals: the mass nouns `analytics` and `metrics`, the product `incus`, and the extension slug `documents`. The Gateway Node's start-hook drop-in runs `orbit-agent service documents.prepare-cleanup`, so that name stays with the extension slug. Service entry points are not commands: `schedules-run` and `schedules-complete` are written into every Schedule's units on its Node, so they keep their names. A Rust test refuses any other namespace whose last word ends in one `s`; `process` ends in two.

Rust defines each command: its input, output, error codes, bound, privilege, and whether it mutates. `apps/agent/contract/commands.json` is written from those definitions, and a Rust test fails when the file is stale. The Gateway reads privileges and bounds from that file. `ORBIT_AGENT_CONTRACT` can select a different contract file; a relative path resolves from the Gateway directory. Rewrite the file with `ORBIT_AGENT_WRITE_CONTRACT=1 cargo test --lib -- contract_file` in `apps/agent`.

`apps/agent/contract/schemas.json` holds the JSON Schema of each command's input and output, derived from the same Rust types. `ORBIT_AGENT_WRITE_CONTRACT=1 cargo test --lib -- schemas_file` rewrites it. Rust tests keep every input object closed to unknown fields in the schema, and decode a generated input of every command with an unknown field at each level to prove the real decoder refuses it.

The PHP test fakes refuse a command, error code, or binary stream that the contract does not declare. They check each input in its wire form, where `{}` and `[]` differ, and each answer against the output schema. A test of the Gateway's own guard against a malformed result says so with `answerMalformed`.

These errors can come from any command:

| Code | Meaning |
| --- | --- |
| `agent.version_mismatch` | The binary is not the pinned one. The runner installs the pin |
| `agent.binary_input_invalid` | Binary input is short, has excess bytes, or cannot be read |
| `agent.envelope_invalid` | The envelope is not one JSON object with a valid digest, deadline, and input |
| `agent.unknown_command` | The pinned binary has no command with that name |
| `agent.invalid_input` | The input does not match the command |
| `agent.identity_mismatch` | The command does not run as its privilege requires |
| `agent.platform_unsupported` | The command does not run on this platform |
| `agent.deadline_exceeded` | The command did not finish before its deadline |
| `agent.supervisor_failed` | A mutation's service ended without a result |

A transport loss, a missing result, a malformed result, a timeout, and an undeclared error code are runner failures: `agent.runner_failed` with a `kind`. A lost result never proves that no change happened, so the runner sends a mutation once.

Binary output also reports `output_limit` when it exceeds its declared or caller limit, and `output_write_failed` when its private sink cannot accept bytes. Binary contents never appear in the diagnostic result.

### Transports

The runner picks the transport from the Node or build guest. Every adapter carries the same arguments, envelope, and result.

| Node | Transport |
| --- | --- |
| A managed Node | SSH to its WireGuard address, as its enrolled account, with its pinned host key |
| The Gateway's own Node | A local process. Mutations still change the Node, because each runs in its own transient service outside the worker's read-only view |
| A Node being admitted | Its pinned bootstrap SSH connection, until managed WireGuard SSH is ready |
| An Incus build guest | `incus exec` through its managed host, before guest networking or SSH exists |
| An UpCloud build guest | Guest SSH with the host identity that Orbit generated and pinned through user-data |

Image building installs command mode before the first recipe command through the runner's guest interface. Images need no observer, WireGuard identity, agent secret, or PHP to run commands. Enrollment adds the Node identity later. `orbit:node-programs` also renders enrollment commands as `orbit-agent command <name>` with the pinned envelope on standard input for external consumers.

Only binary staging may change a Node outside a named command. First admission uses a fixed `uname -sm` staging probe because no agent exists yet. It validates the platform and architecture before installing a candidate. A root bootstrap login runs root commands directly; other Linux logins use passwordless sudo. The installer owns atomic replacement and the cleanup of each candidate it runs from.

### Ownership and exclusions

The Gateway owns validation, authorization, records, operations, placement, and error mapping. Rust commands own Node execution and its tests. [Extensions](/reference/extensions#add-a-plug-in-point) own their Rust command modules, startup hooks, and compiled services; one agent binary dispatches them. There is no dynamic loader or second command runner.

Tasks workspace scripts, their operation journal, and `gateway.task-workspace-owner` retain their [existing runner](/reference/remote-programs). The private DNS listener and its publication scripts remain for the separate [DNS migration](/decisions/0219-answer-cluster-names-from-a-dns-on-the-router). Python outside the Gateway is outside this port. The Gateway's own Artisan entry point and OPcache adapter are control-plane code. Static configuration, templates, and certificates remain data.

Operations own recovery. Mutations accept an `effect_key` as `op-<operation id>.<step key>`. Executor transports always supply it after contract authorization. After acquiring command target and resource locks, the shared command layer writes a private intent on the target before the effect and a durable receipt before returning. A repeated key with the same command, input and Operation nonce returns the saved result. A different command or input is refused with `agent.effect_conflict`. An interrupted intent has unknown outcome and is never executed again under that key.

`agent.effect-receipt` reads `{command, effect_key, owner?}` without changing target state. It returns `{command, effect_key, state, exit_code, result_digest, finished_at, result, sanitized, effect_nonce, effect_created_at}`. Status is `succeeded`, `failed`, `running`, or `unknown`; an absent or unfinished receipt has null result, digest, and time. The original invocation supplies protected masks in transient envelope metadata. Before saving a receipt, the shared layer removes those values from the result and discards the masks. The receipt keeps a sanitization flag. Missing metadata refuses execution, and an unproven stored result cannot be recovered. The result is the sanitized agent outcome, including the command's own refusals. The Gateway redacts and filters it before storage or response.

Root receipts live in `/var/lib/orbit-agent/effects`; account receipts live under the account's recorded home in `.orbit/effects`. Directories are private and files have mode 0600. Receipts keep only an input fingerprint, never the input itself. Bounded binary input contributes its digest without moving the consumer's input cursor.

Existing named remote programs reserve and complete their receipts through `agent.effect-reserve` and `agent.effect-complete`. These commands use the same store and execute no program. A reservation binds the program name and input fingerprint. Only its first successful caller may start the program. A lost reservation or program reply leaves an unknown intent; it cannot authorize a repeat. Completion stores the program result before the runner returns. Cancellation finishes the current operation step and starts no next step. Detached Tasks checks and topologies keep their own supervision.

Effect receipts and their per-key locks have a 30-day retention period. Mutating keyed commands prune old files in batches under an exclusive store guard, at most once daily unless more files remain. Active commands hold that guard shared, so pruning cannot split a live lock. Read-only receipt commands never prune. An original effect identity older than the retention period is refused even when its files are absent; cleanup never authorizes an old effect to run again.

The target sanitizer masks matching strings, object keys and numbers before storing results. Boolean masks retain their original type in protected transient metadata; a numeric or string secret equal to `1` does not mask unrelated `true` results.

Uncertain infrastructure refusals leave the unfinished intent. If a typed pre-start or preflight refusal proves that nothing ran, the store removes that intent and syncs its directory while still holding the key lock. The same key can then execute after the precondition clears. Failure to remove the intent remains uncertain. Fixed remote-program receipts retain their failed state and exit code on both live replies and later recovery.


### Fixed read-only task analysis

`tasks.analyze` is one fixed, read-only profile of the detached Tasks check supervision. It is not a generic command runner and not an agent command. [Tasks](/reference/tasks#analyze-an-external-groups-repository) owns its typed input, report, provenance, and recovery.

The repository's tracked `bin/task-analyze` is the only entry point, pinned by content digest and run with no arguments. Callers cannot choose the shell, arguments, environment, path, report location, model, or analyzer. It runs as the workspace owner without model or GitHub credentials, and it writes, commits, pushes, and approves nothing.

It adds no Gateway Node script and no exception to the [script ratchet](/reference/remote-programs#inline-scripts). Other deterministic task steps, and every Node mutation, still need named agent commands or a separately reviewed fixed contract.

### Future Mac session execution

Command mode currently runs as the enrolled macOS account. A future LaunchAgent can receive the same envelope in that user's login session and show Touch ID or password confirmation for root work. That path is not built. It must authenticate the SSH-started process beyond its user ID, prevent project code from replacing the executable, and name the command in each prompt. The executing process must still check its own binary digest and declared privilege.

### Live output

A command that runs project code can publish its output while it runs. `instance.run-deploy-step` runs one production deploy step as the Instance owner, and `instance.run-development-step` runs one development deploy step as the managed account. Each step runs in its own session. When its shell ends, or at the step's `timeout_seconds`, its process group gets TERM and then KILL 100 milliseconds later, so a process the step left behind cannot hold its output or outlive it. A stopped step exits with 143.

While a step runs, its command joins the operation's channel, `private-operation.{id}`, and publishes each redacted output line as a `client-output` event, as [Realtime events: Deploy step output](/reference/events#deploy-step-output) describes. `POST /api/v1/agent/broadcasting/auth` signs that channel for the agent of the Node that runs the operation, while the operation runs or waits. Publishing is best effort: when Reverb is down, the step still runs, and its result still carries the output for the record.

SSH stays the repair path. When an agent cannot start or answer, `orbit node:converge` reaches the Node over SSH and installs the pin.

### Install on mismatch

A Gateway release is the only step that updates agents. When a command answers `agent.version_mismatch`, the runner:

1. copies the pinned binary from the [delivery](#delivery) to a unique private candidate file with mode `0700`;
2. runs that candidate's own `agent.install`, which waits up to 120 seconds for the update lock;
3. sends the command once more, with the deadline that is left.

The download, the copy, and the installer share one deadline: the call's remaining deadline, or 5 minutes for an explicit installation. When that deadline passes before the installer starts, the call fails as a timeout and nothing is recorded.

Candidate paths use `/var/tmp/orbit-agent-candidate-<random>` on Linux and `/tmp/orbit-agent-candidate-<random>` on macOS. Linux streams through `install`. macOS prepares the file with BSD `install` and streams bytes through fixed `dd` arguments.

The lock wait always leaves 30 seconds of the call's deadline for the swap and the health check. When less time is left than the copies, the health check, and a rollback need, the installer refuses with `agent.install_time_short` before it changes anything. A second mismatch after a passing installation is a runner failure. A busy update lock refuses with `agent.update_busy` and records nothing. A lost connection, a timeout, a failed copy, or a common error such as `agent.deadline_exceeded` records nothing either, so the next command tries again.

Only a refusal that `agent.install` declares, such as `agent.replace_failed` or `agent.health_check_failed`, records the pin in the Node's `agent_failed_pin`. `agent.update_busy` and `agent.install_time_short` are the exceptions. Until `orbit node:converge` installs a pin, every command for that Node refuses at once with `agent.install_failed`, without contacting the Node. A recorded failed pin makes that converge re-apply the agent artifact even when its footprint digest already matches; the other artifacts keep their normal digest checks.

`agent.install` replaces only the binary. It checks the candidate's digest, keeps the previous binary as `orbit-agent.previous`, renames the new one into place, and restarts the observer with its existing configuration and secret. It also restarts running compiled services in their Orbit systemd units or managed Process containers. A container must declare a registered service in its command and bind the installed binary read-only at the same path. Docker's saved definition identifies it even after the service replaces itself with its application.

The installer leaves stopped Processes and in-flight commands alone. When the observer does not stay active for 5 seconds or a compiled service restart fails, it restores the kept binary, restarts those services on it, and fails with `agent.health_check_failed`.

When the installed binary already matches, it changes nothing, so concurrent mismatches install once. Once it runs, it removes the staged candidate whatever the outcome. A call whose deadline passes after staging and before the installer starts leaves its `0700` candidate in `/var/tmp`, where systemd-tmpfiles ages it out; the transport has no file-removal API. It records no recipe identity.

## How it connects

The agent connects to the Gateway at `https://gateway.orbit` and to Reverb at `wss://reverb.orbit`. It never uses system DNS. It connects to the Gateway's WireGuard address from its configuration, `gateway_address`, and to the Reverb address from the realtime response. It verifies each certificate for the hostname against the Orbit root certificate in `ca.pem`.

1. The agent calls `GET /api/v1/agent/realtime` and reads the Reverb connection from the response.

   ```json
   {
     "data": {
       "url": "wss://reverb.orbit",
       "address": "10.44.0.3",
       "key": "<reverb-app-key>",
       "channel": "presence-node.12",
       "log_channel": "presence-node-logs.12",
       "member": "agent.12"
     },
     "meta": { "request_id": "..." }
   }
   ```

   Without an active `websocket` role, `url`, `address`, and `key` are null, and the agent asks again every 60 seconds.

2. It opens a connection to the Reverb address on port 443 and reads its `socket_id`.
3. It asks `POST /api/v1/agent/broadcasting/auth` to sign its membership, with `socket_id`, `channel_name`, and `version`. The Gateway signs `agent.{id}` only on the caller's own two channels.
4. It joins `presence-node.{id}`, sends its snapshot, and then publishes its events.
5. It joins `presence-node-logs.{id}` the same way and fetches its [stream list](#stream-list).

When the `gateway` role moves, the Gateway's WireGuard address changes. Every agent then loses the Gateway until its configuration is rewritten. Run `orbit node:add <node>` or a role converge on each Node. The Reverb address needs no converge, because each realtime response carries it.

Every agent endpoint call requires an active WireGuard peer and a valid [agent secret](#agent-secret); there is no exemption for older agents or Nodes without a stored secret. Agent endpoints need no access grant and record no Activity.

| Code | HTTP | Meaning |
| --- | --- | --- |
| `peer.identity_unknown` | 403 | The caller's address does not belong to an active Node. |
| `agent.secret_required` | 401 | The request carries no bearer secret. |
| `agent.secret_invalid` | 403 | The secret's hash differs from the one stored for the Node, or none is stored. |
| `agent.node_ineligible` | 403 | The Node is outside the managed-Node boundary. |
| `agent.channel_forbidden` | 403 | `channel_name` is not one of the caller's own channels. |
| `validation.failed` | 422 | `socket_id`, `channel_name`, or `version` is missing or malformed. |
| `realtime.not_configured` | 404 | No `websocket` role is active. |

The agent bounds each step, so a peer that stops answering never holds it.

| Step | Limit |
| --- | --- |
| A Gateway request | 10 seconds to connect, 30 seconds in total |
| One join, from the connection to `pusher_internal:subscription_succeeded` | 30 seconds |
| A WebSocket write or close | 10 seconds; a blocked write ends the session and starts a new connection |
| A quiet connection | After 15 seconds without a message, the agent sends `pusher:ping`. Without an answer in 10 more seconds, it reconnects. |

After a reset, server close, or ping timeout, the agent reconnects with exponential backoff capped at 30 seconds. Jitter spreads retries across each delay window, including at the cap. A session that stayed joined for 60 seconds starts the backoff again in the initial 1–2 second window. Every connection repeats all steps, because Reverb gives each connection a new `socket_id`.

One Node runs one agent. The agent holds an exclusive lock on `/etc/orbit/agent` while it runs, and a second process exits with `another orbit-agent already runs on this Node`. The agent also refuses to start outside `orbit-agent.service`, so a copy started from a shell never joins as the Node's agent.

## Agent secret

Every Unix user on a Node reaches the Gateway from the Node's WireGuard address, and production Nodes run customer code as unprivileged users. The agent secret keeps those users from acting as the agent.

| Item | Value |
| --- | --- |
| File | `/etc/orbit/agent/secret`, `root:root` mode `0600`, in `/etc/orbit/agent`, `root:root` mode `0700` |
| Contents | 64 lowercase hexadecimal characters: 32 random bytes from the Gateway |
| On the Gateway | Only the SHA-256 hash, in the Node record. The API never returns it. |
| Sent by the agent | `Authorization: Bearer {secret}` on every Gateway request |

The agent reads the file when it starts. It exits with an error when the file is missing or malformed, and systemd starts it again after 2 seconds. The Gateway compares the hashes in constant time.

Each agent converge sends the stored hash to `agent.configure`. The command keeps the secret while the file matches that hash; otherwise it creates a new secret and restarts the agent. It returns only the hash. There is no scheduled rotation. To rotate a secret, delete the file on the Node and converge.

The converge writes the secret file first, then installs the other files, restarts the agent, and stores the new hash last. The running agent reads its secret only when it starts. So a converge that fails at any step leaves the running agent with a secret that the Gateway still accepts. Doctor then reports `mismatch`, and the next converge repairs it.

One converge runs per Node at a time, under a lock in a file cache store under `ORBIT_HOME`. A second converge waits up to 2 minutes and then fails with `agent.converge_busy`. The lock expires after 7 minutes, which covers the wait for the Node's update lock below. A running converge renews it before each step and stops with `agent.converge_lock_lost` when the lock has expired. The binary download is limited to 20 seconds to connect and 120 seconds in total, so one step fits in the lock's term.

### Recover a lost agent secret reply

Swamp `agent.configure` returns its new hash only in the first live response. The Gateway stores it encrypted on the owning Node. It also reads exact plaintext SHA-256 hashes written by an older release. The encryption migration is reversible and does not encrypt an already encrypted value again, so rollback and roll-forward preserve the accepted secret. The target receipt holds a presence marker and cannot recover that hash. If the live response is lost before storage, keep the uncertain Operation open and run this operator command on the Gateway:

```bash
php artisan orbit:node-agent-rekey <node-id> <operation-id>
```

Use the `node.converge` ID that owns the uncertain `footprint-agent` run. This command runs synchronously on the Gateway, including for Services and executor workers, and needs no live Swamp worker. Swamp configuration holds the same Node agent lock through secret hydration, remote configuration and encrypted hash projection, so retained synchronous convergence cannot replace its secret meanwhile. Recovery requires its existing managed Linux Node, original configuration, started configure run with no result, and retained Node target hold. It takes the shared Operation attempt lease and Node agent lock. An active effect or different owner refuses recovery.

The command starts a fresh unkeyed configure with that recorded configuration and a null previous hash. It encrypts the returned hash and records only the Node ID and recovery time. It prints no secret and leaves the original receipt uncertain. Cancel the old Operation through the normal API and start a fresh converge after recovery.

If this recovery response is also lost, run the explicit operator command again. Repeating an acknowledged recovery is a no-op. A terminal or superseded Operation cannot authorize recovery; start a fresh normal converge after the target is free.

## Gateway view

The Gateway keeps the latest state that each agent reports, so it can skip repeated SSH reads. A long-running Gateway process, the agent view subscriber, receives the reports. The view is only an input to reads.

### Subscriber

The subscriber runs next to PHP-FPM on the Gateway machine. `gateway.converge-agent-view` renders and installs its fixed unit, enables it and restarts it under a root command. PHP supplies the stable Gateway path, Orbit home and account; the service runs Gateway PHP as that account.

| Item | Value |
| --- | --- |
| Command | `/usr/bin/php8.5 artisan orbit:agent-view`, as the `orbit` user in the Gateway checkout |
| Unit | `/etc/systemd/system/orbit-agent-view.service`, with `Restart=always` and `RestartSec=2` |
| Installed by | `orbit:bootstrap` and `orbit:gateway-web` |
| Connection | One WebSocket to Reverb for all Nodes, verifying `reverb.orbit` against the Orbit root CA |
| Channels | `presence-node.{id}` and `presence-node-logs.{id}` for every managed Linux Node |
| Member | `gateway.{socket id}`, with `user_info` `{ "kind": "gateway" }`, signed with the Reverb app secret |

A new member makes every agent on the channel send a complete snapshot. So when a channel owes a snapshot for 5 seconds, the subscriber leaves and joins it again. A channel owes one while agent events arrive without a complete snapshot, or after the agent's `sequence` goes back without a membership change. The subscriber asks each channel at most once every 5 seconds and keeps what it knows until the snapshot arrives.

Every 5 seconds, the subscriber checks the Reverb connection, and every 30 seconds the Node list. It joins new Nodes, leaves removed ones, and reconnects when the Reverb key changes. During a `websocket` move, it keeps one link to each Reverb server that holds clients and uses the link with the newest agent event. Without an active `websocket` role, it checks again every 60 seconds.

When the connection drops, the subscriber clears the view and reconnects with a backoff from 1 to 30 seconds, with jitter. It sends its own ping after 30 quiet seconds and reconnects when no answer comes in 30 more seconds. It ignores a message larger than 64 KB and keeps at most 4,096 units per Node.

Every 60 seconds, the subscriber compares the Gateway checkout's commit with the one it started from. When they differ, it exits, and systemd starts it with the new code. The unit and that check use the stable `ORBIT_GATEWAY_CHECKOUT` path, so in the [release layout](/reference/gateway-recovery#release-layout) they follow a release switch. A release's runtime handoff also restarts the subscriber at once.

### Stored state

The view lives in its own file cache store in `ORBIT_HOME/cache/agent-view`, whatever `CACHE_STORE` says, so heartbeats never write the SQLite database. The subscriber and every PHP-FPM worker run as `orbit` and share those files.

| Entry | Contents | Kept for |
| --- | --- | --- |
| One per Node | Units, `docker` state, task workspaces, agent version, log channel membership, last `sequence`, and the Gateway time of the last event | 60 seconds after its last write |
| One per Node with open log streams | Each stream's ID, source, `lines`, opening Node, and lease end | Until its last lease ends |
| One for the subscriber | Whether realtime is configured, whether the socket is connected, the number of joined channels, and the time of the last write | 30 seconds after its last write |

The subscriber accepts an event only when Reverb's `user_id` is `agent.{id}`. It applies a snapshot once every part has arrived, and starts over when the agent's `sequence` restarts. It removes a Node's entry when `agent.{id}` leaves the channel. It keeps a unit only when the name has the form `orbit-process-{id}-{name}`, the runtime is `systemd` or `docker`, and the state is a short lowercase word.

### Freshness

A reader asks for one Node's state and gets one of three answers. Freshness uses only the Gateway's clock.

| Answer | Meaning |
| --- | --- |
| Fresh | A complete snapshot exists, and an agent event arrived in the last 15 seconds. |
| Stale | An entry exists, but no agent event arrived in the last 15 seconds. |
| Missing | No entry: the subscriber is down, Reverb is unreachable, the agent is not a member, or no complete snapshot arrived. |

In a fresh view, a systemd Process that the agent does not list is `inactive`, and a Docker Process it does not list is `exited`. When the agent reports Docker as `absent`, the view does not answer for Docker Processes.

### Reads that use the view

These reads ask the view first and fall back when it is not fresh.

| Read | With a fresh view | Otherwise |
| --- | --- | --- |
| Process list `runtime_state` | A state the Gateway observed after its own start, stop, or restart wins for 30 seconds. Otherwise the view answers. | [Prometheus](/reference/metrics#process-runtime-state), then SSH |
| Readiness while a hibernated Instance [wakes](/reference/app-dev-runtime-hibernation#wake) | The view answers every 0.5 seconds. A `failed` answer, or the timeout, is checked once over SSH. | SSH every 0.5 seconds |
| The hibernator's [idle halt](/reference/app-dev-runtime-hibernation#idle-window-and-sweep) | When the view shows the Process stopped, the hibernator skips the stop. | `process.control`, which checks ownership and stops |
| The [scheduler tick](/reference/tasks#session-routing)'s check for new commits | `commits` is greater than 0 | `git` over SSH |
| The line diff of an active [task](/reference/tasks#tokens-and-line-diff) | `diff.added` and `diff.removed`, unless truncated | `git diff --shortstat` over SSH |

A task read uses a workspace only when its `base` and `start` equal the values the reader would use. Reads that decide what the Gateway commits or tells an agent stay on SSH: the branch check before an approval commit, a subtask's start commit, turn receipts, and the Project check. The state that a start, stop, or restart returns also stays on SSH, because it must show the change the Gateway just made.

### Publish runs

The subscriber never reads Prometheus, writes the database, or broadcasts in its socket loop. It starts `php artisan orbit:agent-view-publish` as a child process with the queued work and does not wait for it. Task workspaces, Process usage, and log lines each have their own lane, with one run at a time. A run that takes longer than 12 seconds is stopped.

When a workspace of an unfinished task reports a new `head` or new diff counts, a run stores the task's line counts when the diff is complete and broadcasts [`task_group.updated`](/reference/events#tasks). A failed run retries after 15 seconds. After five failures in a row, the workspace is dropped until the agent reports a new change.

### Process usage

The subscriber pushes the CPU and memory of every Process to browsers, so no browser polls the Process list for them. While at least one `viewer.*` member is present on the channels it joins, it queues a sample every 15 seconds. A publish run reads CPU and memory for every Process from Prometheus and broadcasts one [`process.usage`](/reference/events#process-usage) event on `orbit`. With no viewer, nothing is queried. A failed sample is dropped, because the next one replaces it.

### Log relay

The subscriber accepts `client-log` and `client-log-end` only from `agent.{id}` on that Node's log channel, and queues them in order. A publish run drops lines unless the stream is open for that Node. It redacts each line again, also with the stored environment values of the Instance or Process, and publishes `log.lines` in parts under 10,000 bytes. A failed run puts its events back in front of the queue and retries after 2 seconds. [Live logs](/reference/live-logs#limits) lists the Gateway's queue limits and the end reasons.

## Delivery

Each Gateway release pins one agent build: the delivery in its commit's [release](#releases). CI builds the Linux and macOS artifacts when `apps/agent` differs from the last published delivery. Otherwise it reuses that delivery after checking provenance and binary digests. The manifest names the last commit that changed `apps/agent` or a compiled extension. A delivery job checks that their source commits and versions agree, verifies each binary digest, and combines them into one `manifest.json`:

```json
{
  "commit": "<full commit SHA>",
  "version": "0.5.0",
  "artifacts": {
    "linux-x86_64": {"sha256": "<sha256>", "path": "orbit-agent-linux-x86_64", "url": "https://github.com/hardimpactdev/orbit/releases/download/cli-v0.N.0/orbit-agent-linux-x86_64"},
    "macos-aarch64": {"sha256": "<sha256>", "path": "orbit-agent-macos-aarch64", "url": "..."},
    "macos-x86_64": {"sha256": "<sha256>", "path": "orbit-agent-macos-x86_64", "url": "..."}
  }
}
```

The Gateway reads it from `apps/agent/delivery/manifest.json` in its checkout, or from the path in `ORBIT_AGENT_DELIVERY`. Git does not track the file. The Gateway picks a Node's artifact by its platform and recorded architecture. It uses the file beside the manifest when it exists, and otherwise downloads the URL once into `ORBIT_HOME/agent-artifacts/<sha256>`. It checks the SHA-256 before every use. An existing local artifact with the wrong digest refuses; only an absent local file uses the published URL. `apps/agent/scripts/build-delivery` writes the same layout for CI and for a topology.

[Preparing a Gateway release](/reference/gateway-recovery#prepare-a-release) takes the manifest from the commit's published release, in PHP and without the agent. It first verifies the release's [provenance](/reference/gateway-recovery#release-provenance): a Sigstore bundle that proves the release workflow on `main` signed the manifest and `SHA256SUMS`. It then checks every digest against `SHA256SUMS` before any agent command.

Prepare then writes the manifest into the release and installs the pin on the Gateway's own Node; for a release the Gateway already holds, that also comes before any agent command. Preparation, including adoption, refuses without it. The release's private configuration cache selects this checkout's manifest, so a shared environment override cannot keep the previous release's pin. Repeating preparation verifies the existing manifest.

The manifest also supplies the Linux pins in the [recipe identity](/reference/compute-drivers#recipe-identity). The recipe source carries no separate agent version or hashes.

Convergence, Doctor's binary check, the fleet verifier, the release handoff, and the [desired fleet state](/reference/self-update#desired-fleet-state) all read this one pin.

## Install and upgrade

The agent converge writes these files on a Linux Node:

| Item | Path or value |
| --- | --- |
| Binary | `/usr/local/bin/orbit-agent`, owned by `root`, mode `0755` |
| Directory | `/etc/orbit/agent`, `root:root` mode `0700` |
| Configuration | `/etc/orbit/agent/config.toml`, with `gateway_url = "https://gateway.orbit"` and `gateway_address` |
| Orbit root certificate | `/etc/orbit/agent/ca.pem` |
| Agent secret | `/etc/orbit/agent/secret` |
| Unit | `/etc/systemd/system/orbit-agent.service`, marked `# Managed by Orbit: agent` |
| Kept binary | `/usr/local/bin/orbit-agent.previous`, the binary before the last replacement |

The converge installs the binary first, through [`agent.install`](#install-on-mismatch) from a staged candidate. It then runs `agent.configure`, which writes the configuration, certificate, secret, and unit, and restarts the agent only when one of them or the binary changed. The Gateway sends the secret hash it recorded. The agent keeps a secret file that matches it and otherwise writes a new one, and answers only the new hash. The Gateway stores that hash after the restart.

A failed installation or configuration fails the converge with `agent.install_failed`, and a passing installation clears the Node's recorded failed pin. `agent.remove` stops the agent and removes its unit, binary, and directory.

The unit runs the agent as `root` with `Restart=always` and `RestartSec=2`. It grants no capabilities and sets `NoNewPrivileges=yes`, `ProtectSystem=strict`, `ProtectHome=tmpfs`, `BindReadOnlyPaths=-{Instance root}`, `PrivateTmp=yes`, `MemoryMax=128M`, and `RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6`. The memory limit also covers the `journalctl` processes of live journal streams.

The Instance root is the Node's [apps root](/reference/node-settings#derive-the-effective-root). The bind line appears only when the root lies under `/home` or `/root`. When the Gateway cannot resolve the managed user, or the root has characters other than letters, digits, `.`, `_`, `-`, and `/`, the unit sets `ProtectHome=yes`, and the Gateway reads that Node's checkouts over SSH.

| Path | What the agent can read |
| --- | --- |
| `/home` and `/root` | Nothing, except the Instance root |
| The Instance root | Read-only. Without capabilities, root reads only files that other users may read: the tracked files and `.git`, not an Instance `.env`. |
| Everything else | Read-only |

The Instance root and each checkout must stay world-traversable, mode `0755` as Orbit creates them. Otherwise the agent leaves the checkout out. Orbit removes the world bits from an Instance `.env` when it configures the Laravel URL, after registration or transfer, and on each agent converge. Agent converge independently closes environment permissions for the Instances under the Node's apps root; it does not rely on registration or transfer having run.

For Laravel, it uses the shared [application directory](/reference/projects#application-directory): root `apps/site/public` means `<checkout>/apps/site/.env`, not only `<checkout>/.env`. Root `public` and non-Laravel Instances keep the checkout-root path. It closes only regular environment files, without following symlinks or discovering apps by scanning the repository. The Gateway sends the checkout list to `agent.configure`. A `.env` the agent cannot close is named in its answer, and the converge logs a warning and continues.

The Gateway converges the agent at these points.

| When | Result of a failure |
| --- | --- |
| `node:add`, after the Metrics exporters | Provisioning fails at step `agent` with `node.agent_install_failed`. A new Node becomes `failed`, and an active Node stays `active`. |
| A role converge on the Node | The role converge continues. The Gateway logs a warning, and Doctor reports the drift. |

To upgrade the fleet, merge the agent change and release the Gateway. Every Node then gets the new pin from the Gateway on its next command, through [install on mismatch](#install-on-mismatch). The [fleet rollout](/reference/gateway-recovery#fleet-rollout) sends `cli.self-update` to each Node of the rollout set, one at a time, so that command installs the pin first. `orbit self-update` itself replaces only the CLI.

The rollout leaves out the Gateway's own Node. [Prepare](/reference/gateway-recovery#prepare-a-release) installs the release's pin there, and the [runtime handoff](/reference/gateway-recovery#gateway-node-agent) installs it again through the local transport.

`agent.install` holds `/run/lock/orbit-self-update.lock` while it replaces and restarts the agent. `agent.configure` and `agent.remove` hold the same lock, after the installation. So no two of them swap or restart the agent at the same time. A CLI self-update that keeps the lock longer than 5 minutes fails `agent.configure` with `agent.update_busy`.

An installation changes no configuration, certificate, secret, or unit; the rollout's footprint step re-applies those when their digest changed. Doctor reports each Node that runs another binary.

The observer sends its version with each channel authorization: the Cargo version with the first 16 hexadecimal digits of its binary's SHA-256 as build metadata, such as `0.5.0+ad999ffe1ab24dca`. The Gateway derives the same string from the delivery for each Node, so presence proves the exact pinned binary. The Gateway keeps the version of `presence-node.{id}` in the agent view's file store without an expiry, because an agent that stays connected never joins again. The fleet rollout waits for the pinned version there after `cli.self-update`, and the catch-up visits a Node whose agent reports another version.

## Failures

The agent recovers from each failure below without an operator.

| Situation | What happens |
| --- | --- |
| The agent crashes | systemd restarts it after 2 seconds. The web app shows the Node offline until the agent joins again, and the Gateway reads over SSH. |
| The agent stops cleanly | It closes its connection. The web app shows the Node offline at once, and the Gateway drops the Node's view. |
| The Node loses power or network | Heartbeats stop. After 15 seconds, the web app shows the Node offline and the view turns stale. |
| The watch list request fails | The agent keeps its last list and asks again after 60 seconds. |
| A log source cannot be read, or `journalctl` is missing or stops | The agent ends that stream with `source_unavailable`. |
| The agent leaves while it streams | The subscriber ends each stream of that Node with `agent_left`. |
| Reverb is down, or no `websocket` role is active | The agent and the subscriber retry. The web app polls Prometheus, and Gateway reads use Prometheus and SSH. |
| The subscriber stops | systemd restarts it after 2 seconds. Meanwhile the view turns stale, and Gateway reads use Prometheus and SSH. |
| A snapshot is lost | The subscriber asks for a new one within about 10 seconds. |
| Reverb stops answering without closing the connection | The agent reconnects within about 30 seconds. |
| systemd D-Bus is unavailable | The agent exits, and systemd restarts it. |
| The secret differs from the Gateway's hash, such as after a Gateway database restore | The Gateway refuses the agent with `agent.secret_invalid`. Doctor reports `node.agent_secret_mismatch`, and a converge writes a new secret. |

The agent logs to the systemd journal. Its logs contain no Reverb key, signature, secret, or streamed log line.

## Removal

Online [`node:remove`](/reference/node-provisioning#remove-a-node) stops and disables `orbit-agent.service` and deletes the unit, the binary, and `/etc/orbit/agent`, secret included. A failure only logs a warning. `--offline --force` for an unreachable Node leaves the agent installed and lists it under `retained_on_node`. The Gateway then refuses its requests with `peer.identity_unknown`, and the agent keeps retrying.

## Doctor

Doctor checks the agent in the `node` family on every managed Linux Node.

| Issue code | Meaning |
| --- | --- |
| `node.agent_missing` | The binary or the unit is absent. |
| `node.agent_binary_mismatch` | The binary exists but does not match the pinned checksum. |
| `node.agent_inactive` | The unit exists but is not active. |
| `node.agent_secret_mismatch` | The secret file is `missing`, or its hash does not match the stored one: `mismatch`. Doctor reads only the hash, and the report shows neither the secret nor a hash. |
| `node.agent_view_stale` | The agent unit is active and a `websocket` role is active, but the Gateway has no fresh view of the Node. |
| `node.release_lag` | The [fleet rollout](/reference/gateway-recovery#catch-up) is on, and this Node of the rollout set does not run the desired state of the Gateway's commit. |

Run `orbit node:add <node>` to repair the first four. `node:add` refuses a Node that owns Instances. Repair such a Node by converging one of its roles with `orbit node:role:add <node> <role> --converge`.

`node.agent_view_stale` reports what it observed.

| Observed | Meaning | Repair |
| --- | --- | --- |
| `subscriber_down` | The subscriber stopped, or wrote no health in the last 30 seconds. | Check `systemctl state orbit-agent-view` on the Gateway host, or run `php artisan orbit:gateway-web`. |
| `disconnected` | The subscriber runs but has no Reverb connection. | Check the `websocket` role with `orbit doctor --family=role`. |
| `missing` | The subscriber has no complete snapshot from this agent. It asks for one within about 10 seconds of the agent's next event. | Check `journalctl -u orbit-agent` on the Node. |
| `stale` | No agent event arrived in the last 15 seconds. | Check the Node's network and `journalctl -u orbit-agent`. |

## Checks

Run the [agent checks](/reference/bun-and-rust-projects#node-agent) as a normal user. The dev profile optimizes `regex` and `regex-automata` so the shared redaction fixtures run without the cost of unoptimized matching. Cargo's test profile inherits these package overrides. The inputs, redaction rules, and assertions stay the same; these overrides do not change the release profile. [Live logs](/reference/live-logs#stream-tests) describes which stream tests use a paused clock and which retain real processes and observation intervals.

Tests that create executable tools use the shared `tests/support/executable.rs` helper. It writes a source file, then waits for a separate `install` process to copy it to the executable path. Only that child opens the executable inode for writing. Parallel test forks can inherit a writable descriptor for the source, but the source is never executed. Closing, syncing, or renaming a file written by the test process would leave an inherited descriptor open until the other child executes or exits, which can cause Linux `ETXTBSY` errors.

### Recipe interruption

The native recipe runner waits directly for its command and treats SIGHUP, SIGINT, and SIGTERM in the child wait state as interruption, even when the agent handler has not run yet on another thread. It owns the command process group and enforces its deadline with TERM, then KILL after one second. Deadline expiry remains exit code 124. An external timeout program cannot preserve this evidence reliably: it can turn a child signal into a numeric exit code.

Required and optional commands both stop the recipe on interruption. The handler flag still detects signals before execution and after a command exits normally. A numeric exit code such as 143 is a command failure, not proof of a signal.

Recipe interruption tests run on a non-main thread in an isolated process. A socket barrier delays the agent handler on the main thread while the recipe runs on another thread, so child exit precedes the handler flag update. The delayed-handler cases target the main thread explicitly: a process-wide signal can select the recipe thread and deadlock a blocking test handler. A separate service-termination case signals both process groups with the real handler. A nested signal checks that interrupted socket I/O keeps the barrier closed.

The supervised fixture sends a Unix datagram after execution, including its process group. The test receives that event before it signals the worker and supervisor groups. Fixture readiness, handler entry and release, command lifetime, and helper exit have deadlines. A deadline failure fails the test and kills its owned process groups. Separate cases check each supported signal before execution, after normal execution, and during required and optional commands.

## Releases

The agent ships in the one public release that each green `main` commit gets, beside the CLI ([CLI binaries: Published releases](/reference/cli-binaries#published-releases)). A push to `main` builds or reuses the [delivery](#delivery) in CI and uploads it as the `agent-delivery` artifact. An unchanged agent, including a docs-only commit, reuses the last published binaries. Reused bytes keep their original agent source commit and digests.

After main's required checks pass, the Orbit CLI Release workflow downloads that artifact from the CI run, checks each binary against its manifest digest, and publishes it with the CLI binaries. It never rebuilds the agent. It publishes with the workflow's own token; there is no other credential.

| Asset | Contents |
| --- | --- |
| `orbit-agent-linux-x86_64` | Static binary for `x86_64` Nodes |
| `orbit-agent-macos-aarch64` | Command binary for Apple silicon Macs |
| `orbit-agent-macos-x86_64` | Command binary for Intel Macs |
| `manifest.json` | The delivery manifest, with each binary's URL in this release |

The release's `SHA256SUMS` lists the agent binaries with the CLI binaries, and its `provenance.sigstore.json` [signs](/reference/cli-binaries#provenance) the manifest and `SHA256SUMS` with the other files. A Gateway release of a commit waits for that commit's release, because [prepare](/reference/gateway-recovery#prepare-a-release) takes the agent from it.

## Limits

The agent has these limits.

- The agent reports presence, Process state, and task checkout Git state, and tails logs only for live log streams.
- The observer reports state, and commands change it.
- Task workspace reads that gate an action still run as SSH programs.
- The agent does not report CPU or memory. The Gateway pushes them from Prometheus as [Process usage](#process-usage).
- The delivery holds Linux builds for `x86_64` and `aarch64`. The observer runs only on Linux. Command mode builds and tests on macOS arm64, and the delivery includes command binaries for Apple silicon and Intel Macs.
- The agent cannot trigger `orbit self-update`. The [fleet rollout](/reference/gateway-recovery#fleet-rollout) runs it over SSH.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### Typed commands over SSH

Scripts that the Gateway assembles and sends on every call have hidden contracts: positional arguments, exit codes, and text that PHP parses. CI cannot test them alone, and they need an interpreter on every Node. A named command with typed input and output has one implementation and one contract, tested in Rust where the logic runs. So the Gateway sends a name and data, never source.

SSH stays the transport. It authenticates the Gateway with a pinned host key and the enrolled account. It also reaches a Node whose agent is broken, so the Gateway can always repair it. An agent that listened on the network would add a root-capable endpoint that every user on every Node could reach. So the observer still listens on no port, and command mode starts only when SSH or the Gateway starts it. The binary is not setuid: a Node user who runs it gets only that user's permissions.

A Gateway release delivers new agent code, as it delivered scripts before. Install on mismatch reaches an offline Node, a Node that the rollout missed, and a VM from an older image on their first command. No separate agent update step exists.

Moving source into named Python programs improved linting but kept source assembly, interpreter startup, and a second execution contract. Delivering scripts through the agent would keep those costs. PHP SSH command sequences would also split a Node action across round trips without one lock around its checks and changes. Compiled commands keep the rule and its tests together.

The exact binary digest identifies the contract. Version ranges and compatibility commands would keep multiple implementations active. The frozen mismatch response lets a released agent refuse a newer command before understanding its name or input. The Gateway can then install the pinned implementation once.

Ordinary commands run from their open executable and do not hold the update lock. Atomic replacement leaves them valid while an update proceeds. Holding the lock throughout a long deploy would block updates unnecessarily. Installers and final recipe verification take it exclusively, so verification cannot record a pin while it is being replaced.

### Rust

The agent runs on every Node, so memory matters. A Rust agent uses about 5 MB. PHP or Bun would use about 20 times more. Rust also has mature libraries for systemd D-Bus and the Docker socket.

### One pinned delivery

A workflow artifact needs a GitHub login and expires, so the commit's release publishes the tested artifact as release assets with permanent URLs, beside the CLI. One release per commit needs no separate tag, token, or App. Building on each Node would put a Rust toolchain on production hosts. Resolving the latest release at install time would let an unreviewed build reach Nodes. So each Gateway release pins the exact digests of its own commit's delivery, and every reader takes the pin from it.

The Gateway fetches and installs that delivery itself in PHP, so installing the agent never needs the agent. It verifies the release's provenance first, so only files that the release workflow on `main` signed reach a Node.

### The view in a file cache

HTTP reports would cost a PHP-FPM worker per heartbeat on a small Gateway. A PHP-FPM request cannot wait for a snapshot, which comes only when a member joins. SQLite would take a write every few seconds that competes with API writes. So one long-running subscriber keeps a disposable view in files, and every reader falls back to Prometheus and SSH when the view is missing.

### Rejoin to ask for a snapshot

Reverb gives neither client events nor presence events a socket ID, so the subscriber cannot tell two connections of one member apart. A client event that asks for a snapshot is rejected, because the subscriber never sends client events. A new membership already makes every agent send a snapshot, so the subscriber leaves and joins again.

### A per-Node secret

The WireGuard address alone does not tell the agent apart from other users on the Node. A firewall owner match depends on per-Node rules that fail silently when missing. Mutual TLS needs a certificate issuer and renewal. A bearer secret in a file that only root can read fails closed, at a fraction of that cost. The Gateway stores only the hash, because a leaked database then reveals no secret, and a slow password hash adds nothing for 256 random bits.

### The hash is stored last

Storing a new hash before the agent restarts would refuse the running agent if the restart fails, while the file would match the record. Doctor would then report nothing. Storing it last keeps the running agent accepted and leaves a mismatch that Doctor reports.
