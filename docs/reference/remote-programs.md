---
title: "Remote programs"
description: "The remaining Tasks workspace program runner, its checks, and the Gateway script ratchet."
covers:
  - apps/gateway/app/Domain/RemotePrograms/**
  - apps/gateway/app/Infrastructure/RemotePrograms/**
  - apps/gateway/resources/remote/{bootstrap.py,lib/program.py,gateway/task-workspace-owner.py}
  - apps/gateway/ruff.toml
  - apps/gateway/tests/Programs/**
  - bin/node-scripts-ratchet
  - tools/test_node_scripts_ratchet.py
  - bin/remote-programs-check
---

# Remote programs

Node management runs through [named Rust agent commands](/reference/node-agent#commands). The program runner remains only to recover ownership of Tasks workspaces, which is outside this port. Tasks workspace scripts and their operation journal also remain unchanged. New Node behavior must not use this runner.

## Program files

The sole catalogued program is `gateway.task-workspace-owner`, at `apps/gateway/resources/remote/gateway/task-workspace-owner.py`. It checks a recorded hostname, boot ID, process ID, and process start ticks. Its `inspected` result reports whether that exact workspace owner is dead. A matching host with a changed boot proves death; on the original boot, the process identity must match.

`RemoteProgramCatalog` reads the program header and prepends `lib/program.py`. The header declares the program name, libraries, outcomes, and 30-second timeout. The assembled source and its JSON input travel through `SshProgramRunner` over the Node's pinned SSH connection.

## Python programs

PHP tests run the retained Tasks programs in isolated processes. Timeout and descendant-cleanup tests use real deadlines. The authentication test waits for a log marker that proves the checker consumed the first token fragment before sending the rest, so redaction across separate reads needs no injected Python clock or read hook.

The retained runtime needs Python 3.10 or newer. `resources/remote/bootstrap.py` receives source, one NUL byte, and JSON input. It runs under Python's isolated mode, so the working directory and user site packages cannot supply imports. An unsupported Python version exits before running the program.

`lib/program.py` reads one JSON object and writes one result line with `outcome` and `data`. Logs go to standard error. A declared refusal exits zero with its result. An exception or termination signal produces no result.

## The runner

`TaskWorkspaceLeaseRecovery` calls `RemoteProgramRunner` for `gateway.task-workspace-owner`. It maps the returned facts to workspace lease recovery. No Instance, tool, recipe, or other Node-management action calls this runner. The Services-only [Operation primitive](/reference/operations#swamp-execution) can invoke a named program from the same catalog. It accepts typed input and a catalog name, never caller-supplied source.

`SshProgramRunner` checks the result shape and declared outcome. It distinguishes unreachable Nodes, missing results, malformed results, undeclared outcomes, timeouts, and cancellation. Failures retain a redacted stderr tail of at most 4096 bytes and the exit status when available. Redaction runs before truncation.

The Gateway has an [architecture test](/reference/node-agent#commands) that permits this excluded Tasks path explicitly. It rejects new dependencies on the runner elsewhere.

## Lint and direct tests

`bin/remote-programs-check` runs from the repository root. It checks the remaining programs under `apps/gateway/resources` and extension resources:

- shellcheck checks Bash files.
- ruff 0.17.0 checks Python files and assembled programs with `apps/gateway/ruff.toml`.
- PHP tests verify that findings name the original template or library line.

Use `bin/remote-programs-check --resources PATH` to lint one resource tree. The default checks the Gateway and extension resources. Pest covers the retained Tasks monitors and program runtime; the checker needs no pytest runtime. The separate repository Python tests use the pins in `tools/test-requirements.txt`.

PHP tests cover the retained bootstrap and result protocol. They start each excluded Tasks program in an isolated process through `PythonPrograms`. The PHP helper owns input, output, deadlines, and child cleanup; it runs no Python test host.

Tasks monitors share `tasks/unit-state.py` through `TaskUnitProgram`, which replaces `# ORBIT_TASK_UNIT_STATE` before transport. The check lints the assembled source and maps each finding to its original template or library line. This preserves the workspace scripts while they await the separate Tasks migration.

The `Remote programs` CI job runs these checks. The [candidate gate](/reference/implementation-loop#the-candidate-gate) also runs them when a candidate changes Gateway resources, direct program tests, Ruff configuration, or the checker. A missing tool fails the check.

## Inline scripts

`bin/node-scripts-ratchet --base HEAD^1` compares the remaining script totals with a Git revision. It counts every heredoc and nowdoc in all Gateway PHP, identified by extension or a `<?php` opening, outside the excluded roots below, with the fixed data labels in the checker excluded. Unknown labels count. Literal `sh -c`, `bash -c`, `python3 -c`, `php -r`, `perl -e`, and `node -e` calls count too. Combined flags and option values before the code flag count, such as `bash -euo pipefail -c`. A heredoc body counts once. The matcher also counts interpreter calls in comments: `// never use sh -c` raises the inline total.

### Files

The file scan covers the whole Gateway, extensions included. It counts every non-PHP file, and PHP files under any `resources/` directory, except `.json`, `.xml`, `.md`, `.blade.php`, `.pem`, `.crt`, and `.cer` data and blank `.gitkeep` markers. Resource `.json` and `.xml` files must parse to qualify as data. Unknown types count, so renaming a script to `.txt` or `.stub` is neutral. Every shebang counts. Only `apps/gateway/tests/`, `apps/gateway/extensions/*/tests/`, `apps/gateway/vendor/`, and `apps/gateway/node_modules/` are excluded roots. Nested directories named `tests` or `vendor` are scanned.

The fixed `resources/fpm/opcache-reset.php` adapter is excluded. It runs Gateway PHP in the Gateway's own pool, like Artisan, and is outside Node execution. The handoff keeps the [idle check and socket reset](/reference/gateway-recovery#runtime-handoff). Artisan and support files are still counted by the file guard but remain outside the Node migration. Test fixtures are excluded.

### CI comparison

The `Remote programs` CI job runs the guard and its regression tests before program lint. Inline and file totals cannot rise or offset each other. Moving an inline script between files, or moving a resource into an extension, is neutral. Deleting a script lowers the next comparison. A pull request compares its merge ref with its first parent, `HEAD^1`; a main push compares with its previous SHA. Scheduled and manual checks use `HEAD^1`. There is no editable count ceiling or per-script budget. [Node commands](/reference/node-agent#commands) owns the migration and the remaining execution boundary.

## Why it works this way

Named programs extracted source from PHP strings so lint and direct tests could check it. Compiled [Node commands](/reference/node-agent#typed-commands-over-ssh) now keep Node logic and its tests in Rust. The Tasks workspace exclusion preserves its existing ownership checks and operation journal until its separate migration.
