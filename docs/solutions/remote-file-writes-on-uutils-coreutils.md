---
title: "Remote file writes on uutils coreutils"
description: "Why writing an existing remote file from /dev/stdin with install fails on Ubuntu 26.04 uutils coreutils, and how to write files safely."
covers:
  - apps/gateway/app/Infrastructure/Nodes/NodeAgentSshExecutor.php
  - apps/agent/src/commands/processes.rs
---

# Remote file writes on uutils coreutils

## Problem

A Node convergence that writes a file succeeds on the first run and fails on every subsequent run with `install: No such file or directory`, even though the parent directory exists.

## Cause

Ubuntu 26.04 ships uutils coreutils instead of GNU coreutils. Its `install` command fails when overwriting an existing file from `/dev/stdin`:

```text
$ printf a | sudo install -m 0640 /dev/stdin /etc/orbit/metrics/marker   # first run: succeeds
$ printf a | sudo install -m 0640 /dev/stdin /etc/orbit/metrics/marker   # second run: fails
install: No such file or directory
```

Creating a new file or overwriting from a regular file works; only overwriting from `/dev/stdin` fails.

## Solution

Write to a regular temporary or candidate file with the target mode and ownership, then atomically rename it onto the destination path. In `apps/agent/src/commands/processes.rs`, `process.stage-unit` writes and verifies `/etc/orbit/systemd-candidates/<unit>` and `process.activate-unit` atomically renames it onto `/etc/systemd/system/<unit>`.

In shell or Swamp steps that must use `install -m 0600 /dev/stdin <path>`, remove the existing destination first before writing.

## Limits

This bug affects only `/dev/stdin` sources. `install -d`, mode changes, and regular-file sources work as expected.

## Verification

Converge the same role twice over existing files to verify overwrite idempotence.
