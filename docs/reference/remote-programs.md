---
title: "Remote programs"
description: "The remaining Tasks workspace program runner and the programs still to port to Swamp."
covers:
  - apps/gateway/app/Domain/RemotePrograms/**
  - apps/gateway/app/Infrastructure/RemotePrograms/**
  - apps/gateway/resources/remote/{bootstrap.py,lib/program.py}
---

# Remote programs

Node management runs through [named Rust agent commands](/reference/node-agent#commands). The program runner remains only to recover ownership of Tasks workspaces, which is outside this port. Tasks workspace scripts and their operation journal also remain unchanged. New Node behavior must not use this runner.

## Program files

No Gateway code calls a catalogued program now: the workspace owner check left with the workspace lease. The runner and its header format remain for the Services-only Operation primitive.

`RemoteProgramCatalog` reads the program header and prepends `lib/program.py`. The header declares the program name, libraries, outcomes, and 30-second timeout. The assembled source and its JSON input travel through `SshProgramRunner` over the Node's pinned SSH connection.

## Python programs

PHP tests run the retained Tasks programs in isolated processes. Timeout and descendant-cleanup tests use real deadlines. The authentication test waits for a log marker that proves the checker consumed the first token fragment before sending the rest, so redaction across separate reads needs no injected Python clock or read hook.

The retained runtime needs Python 3.10 or newer. `resources/remote/bootstrap.py` receives source, one NUL byte, and JSON input. It runs under Python's isolated mode, so the working directory and user site packages cannot supply imports. An unsupported Python version exits before running the program.

`lib/program.py` reads one JSON object and writes one result line with `outcome` and `data`. Logs go to standard error. A declared refusal exits zero with its result. An exception or termination signal produces no result.

## The runner

The Services-only [Operation primitive](/reference/operations#swamp-execution) can invoke a named program from the same catalog. Each kind must explicitly allow the program and bind its schema-checked input to admitted intent. The primitive takes the target from the Operation, never from the caller. Firewall allows no RemoteProgram.

`SshProgramRunner` checks the result shape and declared outcome. It distinguishes unreachable Nodes, missing results, malformed results, undeclared outcomes, timeouts, and cancellation. Failures retain a redacted stderr tail of at most 4096 bytes and the exit status when available. Redaction runs before truncation.

The Gateway has an [architecture test](/reference/node-agent#commands) that permits this excluded Tasks path explicitly. It rejects new dependencies on the runner elsewhere.

## Still to port

The Gateway still sends these programs from `apps/gateway/resources` to Nodes, outside Swamp. Each moves to a Swamp flow:

- The Tasks workspace scripts `tasks/check`, `tasks/composer`, `tasks/metadata`, and `tasks/unit-state.py`.
- The remote runner files `remote/bootstrap.py` and `remote/lib/program.py`.
- The CI cache import `tasks/ci-caches.sh`.

`remote/route/publish-dns.py` and `remote/database/valkey.py` have no catalog entry and no caller. Delete them.

PHP tests cover the retained bootstrap and result protocol. They start each excluded Tasks program in an isolated process through `PythonPrograms`. Tasks monitors share `tasks/unit-state.py` through `TaskUnitProgram`, which replaces `# ORBIT_TASK_UNIT_STATE` before transport.

## Why it works this way

Named programs extracted source from PHP strings so lint and direct tests could check it. Compiled [Node commands](/reference/node-agent#typed-commands-over-ssh) now keep Node logic and its tests in Rust. The Tasks workspace exclusion preserves its existing ownership checks and operation journal until its separate migration.
