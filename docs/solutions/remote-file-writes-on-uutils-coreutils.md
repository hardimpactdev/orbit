---
title: "Remote file writes on uutils coreutils"
description: "Why writing a remote file from standard input with install fails on uutils coreutils, and the write sequence that works."
covers:
  - apps/gateway/app/Infrastructure/Metrics/{MetricsSshExecutor,MetricsExporterSshExecutor}.php
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

Never point a remote `install` from standard input at a live path. Remove any stale `<path>.orbit-candidate`, write the candidate, and then `mv -fT` it onto the target. The move is atomic, which running containers and systemd units need anyway. Metrics SSH publishers, `MetricsExporterSshExecutor::publishConfiguration()`, and `NodeAgentSshExecutor::publishFile()` use this sequence. The agent's `processes.stage-unit` writes each Process unit to `/etc/orbit/systemd-candidates` and verifies it with `systemd-analyze`; `processes.activate-unit` then renames it into place. Metrics exporter SSH lifecycle operations now delegate file publication to the shared publisher.

Before staging a systemd candidate, `processes.stage-unit` reads the existing unit and checks its Process ownership marker. If its content matches a recorded successful activation, or belongs to a legacy Process with no pending activation, and its active or inactive state matches the desired state, convergence returns without writing a candidate, reloading systemd, or restarting the Process. Changed unit content still follows the candidate-and-verify sequence; an unchanged specification does not justify skipping recovery of an incomplete update.

## Limits

The problem affects only `/dev/stdin` sources. `install -d`, mode changes, and regular-file sources work. Node agent converge closes permissions on an existing Instance `.env` with a mode change, not file publication. For a nested Laravel app, that operation targets the [application directory](/reference/node-agent#install-and-upgrade), such as `<checkout>/apps/site/.env`; it does not need the candidate-and-move sequence. A numeric container identity, such as Grafana's `472`, also needs a separate `chown`, because `install -o` and `-g` refuse an identity without a `passwd` entry. The Node agent uses this sequence only on Linux. A macOS install or repair returns `node.agent_unsupported` before SSH, and removal returns without a remote command.

## Verification

Converge the same role twice over existing configuration, which is the case that fails. Unit tests cover the sequence in `apps/gateway/tests/Unit/Infrastructure/Metrics/MetricsSshExecutorTest.php` and `MetricsExporterSshExecutorLifecycleTest.php`; the `processes` tests in `apps/agent` check candidate verification and atomic publication of systemd units, and `apps/gateway/tests/Feature/Infrastructure/Processes/RemoteProcessRuntimeManagerTest.php` checks that repeated convergence of an unchanged running or stopped Process publishes nothing and issues no restart.
