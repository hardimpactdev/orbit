---
title: "Prove the verify-only Doctor on a nested fleet"
description: "Fixture rules for proving FactDoctor reports drift and unverifiable checks without mutating Node state."
---

# Prove the verify-only Doctor on a nested fleet

## Problem

Proving that `FactDoctor` reports drift without mutating Nodes requires a healthy baseline, one declared drift, one unverifiable check, and proof that Doctor writes nothing outside `activity_log`. Timing mismatches, low disk space, and SQLite sidecars can trigger extra findings or false write-check failures.

## Cause and solution

`FactDoctor` evaluates only five families (`node`, `role`, `process`, `firewall`, `instance`) using Gateway DB state, agent facts (`units` every 10 seconds; `host` and `ufw` every 60 seconds), and the machine bus (`nodeClock`). The other families (`project`, `schedule`, `tool`, `database_connection`, `route`, `database_server`) return zero checks.

- **Wait for agent facts:** Wait for fresh `units`, `host`, and `ufw` reports before capturing a baseline. Run `--family=firewall` before the first `ufw` report to prove `firewall.inspection_failed` (exit `1`).
- **Keep root disk healthy:** `FactDoctor` requires at least `2 GiB` and `10%` free bytes on `/` (`node.disk_low`). Clean up owned fixtures or restore `base` to clear low-disk findings.
- **Trigger real drift:** Stop a watched role service (`role.services_inactive`) or alter a managed UFW rule (`firewall.rule_mismatch`), wait for the next agent report, and confirm exit `1`.
- **Crash-loop detection:** `process.crash_loop` fires on systemd `state=activating` with `sub=auto-restart`. Restore the unit before capturing a baseline.
- **Exclude SQLite sidecars from write checks:** Even read-only SQLite connections create and remove `-wal` and `-shm` files. Exclude them from the file inventory; only `activity_log` should gain one row per Doctor request.

## Limits

Nested fleets prove Linux behavior only. [macOS enrollment](/reference/node-provisioning#macos-nodes) and native macOS observations require a real Mac fixture. Router LAN firewall coverage follows the [member-rule contract](/cli/firewall#router-lan-member-rules).

## Verification

Run the proof in a [task copy's nested fleet](/reference/tasks#build-a-feature-in-one-copy):

- Baseline and restored scans exit `0` with zero findings.
- The drift scan exits `1` with the single expected finding (`role.services_inactive` or `firewall.rule_mismatch`).
- The unverifiable scan exits `1` with `firewall.inspection_failed`.
- File inventories before and after differ only in `activity_log` row count.
