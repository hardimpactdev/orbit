---
title: "Remote programs"
description: "The legacy SSH program runner, its remaining admission-time checks, and its protocol."
covers:
  - apps/gateway/app/Domain/RemotePrograms/**
  - apps/gateway/app/Infrastructure/RemotePrograms/**
  - apps/gateway/resources/remote/{bootstrap.py,lib/program.py}
---

# Remote programs

Routine Node actions run through Swamp workflows, the [Gateway engine](/reference/gateway-engine), or [named Rust agent commands](/reference/node-agent#commands). New Node behavior must not use the legacy SSH program runner.

A Gateway [architecture test](/reference/node-agent#commands) enforces this boundary and permits only the narrow admission-time exceptions below.

## Admission exceptions

Two synchronous checks still run over the Node's pinned SSH connection at admission so the Gateway can return HTTP 409 before recording side effects.

**Host-checkout Git dependents (`instance.destroy-git`):** `InstanceDestroyWorktrees::assertAvailable` checks a host-checkout Instance before destroy or transfer admission. It reads only the owner's Git registry and recorded checkout common directories without scanning working trees (10-second outer timeout, 3 seconds per Git call). Containers, reserved sources, missing paths, and retries skip it. Unreachable Nodes refuse with `instance.host_unavailable`, Git errors refuse with `instance.remove_refused`, and confirmed dependents refuse with `instance.worktree_dependents`.

**PostgreSQL replication slots:** `InstanceDestroyReplicationSlots` checks owned PostgreSQL databases at destroy admission before Schedule withdrawal. It runs `sudo -n docker exec -i` with `psql` over pinned SSH, passes the admin password on stdin, and times out after 5 seconds per server with no retries.

## Program files and runtimes

`RemoteProgramCatalog` loads a catalogued program's header (name, libraries, declared outcomes, and timeout) and prepends its runtime library. `SshProgramRunner` supports two runtimes over the Node's pinned SSH connection:

- **Python (`bootstrap.py` and `lib/program.py`):** Runs Python 3.10+ in isolated mode (`-I`) with source, a NUL byte, and JSON input, writing one JSON result line.
- **Bash:** Runs under `bash -seu --` with environment setup such as `GitReadEnvironment` for read-only Git checks.

Declared refusals exit `0` with their structured result, while logs go to standard error.

## The runner

`SshProgramRunner` validates the result shape and declared outcome, distinguishing unreachable Nodes, missing or malformed results, undeclared outcomes, timeouts, and cancellation.

Output is capped at 4 MiB. On failure, the runner redacts secrets before retaining the last 4,096 bytes of standard error and the exit status.

## Still to port

Two sets of files under `apps/gateway/resources` still ship outside Swamp and remain to be moved into native workflows:

- `remote/bootstrap.py` and `remote/lib/program.py` (the remote Python runner).
- `tasks/ci-caches.sh` (the CI cache import script).

## Why it works this way

Named programs originally moved shell logic out of inline PHP strings so lint and unit tests could cover it. Compiled [Node commands](/reference/node-agent#typed-commands-over-ssh) and Swamp workflows replace that layer with typed contracts and native execution.
