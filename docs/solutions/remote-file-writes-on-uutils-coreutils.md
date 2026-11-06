---
title: "Remote file writes on uutils coreutils"
description: "Why writing a remote file from standard input with install fails on uutils coreutils, and the write sequence that works."
covers:
  - apps/agent/src/commands/metrics_runtime.rs
  - apps/agent/src/commands/metrics_exporter.rs
  - apps/gateway/app/Infrastructure/Nodes/NodeAgentSshExecutor.php
  - apps/agent/src/commands/processes.rs
---

# Remote file writes on uutils coreutils

## Problem

A convergence that writes a file on a Node succeeds once and fails on every later run. The remote command prints only `install: No such file or directory`, although the directory exists. In Metrics, the codes are `metrics.configuration_publish_failed` and `metrics.exporter_configuration_failed`.

## Cause

Ubuntu 26.04 ships uutils coreutils, not GNU coreutils. Its `install` refuses an existing destination when the source is `/dev/stdin`:

```text
$ printf a | sudo install -m 0640 /dev/stdin /etc/orbit/metrics/marker   # first run
$ printf a | sudo install -m 0640 /dev/stdin /etc/orbit/metrics/marker   # second run
install: No such file or directory
```

Creating a new file works, and overwriting from a regular file works. Only an overwrite from standard input fails, so the failure shows one convergence after the code that causes it.

## Solution

Node commands publish files through Rust filesystem APIs. They create a fresh candidate, write it with the required mode and ownership, then rename it onto the live path. This avoids the standard-input overwrite problem and makes each replacement atomic. Metrics configuration and exporter commands use this sequence, and so does `process.stage-unit` for Process units.

## Limits

The problem affects only `/dev/stdin` sources. `install -d`, mode changes, and regular-file sources work. Rust publication sets numeric ownership directly, so Grafana's UID and GID `472` need no host account.

Swamp workflow steps meet the same rule. The shared workflow `task-dependency-auth-write` writes `~/.config/composer/auth.json` and `~/.npmrc` with `install -m 0600 /dev/stdin`, so it clears both files first.

## Verification

Converge the same role twice over existing configuration, which is the case that failed. The Rust Metrics runtime and exporter tests use temporary filesystems and cover repeated publication, ownership refusals, modes, and rollback.
