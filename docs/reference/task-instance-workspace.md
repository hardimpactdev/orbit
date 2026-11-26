---
title: "Task Instance workspace phases"
description: "The internal workspace contract for task work, base syncing, and checks in an existing container Instance."
covers:
  - swamp/ansible/task-instance-workspace.yml
  - swamp/ansible/tasks/task-instance-workspace-phase.yml
  - swamp/workflows/workflow-task-instance-workspace.yaml
  - swamp/workflows/workflow-task-copy-base.yaml
  - swamp/workflows/workflow-task-instance-check.yaml
  - apps/gateway/app/Domain/Tasks/TaskInstanceWorkspace.php
  - swamp/extensions/behaviors/operations/task-instance-workspace/*
---

# Task Instance workspace phases

This internal child workflow supplies workspace evidence, base syncing, and branch export for container [task copies](/reference/tasks#task-copy). Its [check phases](#check-phases) execute `tasks.check` for a task group that owns a container Instance workspace ([`task:start`](/cli/task/start#a-task-copy)). These workflows own neither runtime selection nor the Instance lifecycle; [Tasks](/reference/tasks) owns admission and cleanup.

See the [internal phase chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/task/instance-workspace.mmd).

## Contract

Callers supply the recorded Incus project, container name, expected branch and head, and a unique snapshot ID. Ansible connects to the Node over pinned SSH and runs every guest command as `agent` in `/srv/workspace` with closed stdin, zero guest credentials, and no repository code executed on the host Node.

| Phase | Required evidence | Result or effect |
| --- | --- | --- |
| `preflight` | Branch and head | Verifies checkout and Git-directory device/inode identity (`checkout_fingerprint`) |
| `before` | Preflight identity | Captures original `HEAD`, workspace tree, index tree, and digest bound to the snapshot |
| `after` | Original snapshot digest | Captures post-turn `HEAD`, workspace tree, index tree, and digest |
| `receipt` | Original digest and candidate head/tree | Reads up to 65,536 UTF-8 bytes from `/tmp/orbit-task-workspace-<snapshot>/receipt.json`, rejecting symlinks or mismatched candidates |
| `export` | Preflight identity and candidate head/tree | Builds and verifies the candidate Git bundle as `agent`, then transfers it to Node scratch or `worker_bundle` |
| `discard` | Preflight identity | Deletes only this snapshot's guest scratch after users stop |
| `scan` | Head, copy start, and `nested` | Runs `task_copy_scan.py` as root in the container (`python3 -I`, Git as `agent`), returning paths, lines, secret names, and object digest (never values) |
| `base` | Branch, default branch, mirror, published/approved commits | Fetches default branch into `/var/lib/orbit/instance-repositories/<instance>.git` and imports missing objects into `refs/remotes/origin/<default>` |
| `base-cleanup` | Copy Instance ID | Deletes `/var/tmp/orbit-task-base-<instance>` and the signed executor's mirror seed staging `/var/lib/orbit/instance-repositories/<instance>.git.seeding` on the Node, and `/tmp/orbit-task-base-<instance>` in the guest |

Snapshots use a temporary Git index (capturing unignored untracked files, staged files, spaces, and symlinks without modifying the real index) with Git hooks, `fsmonitor`, and global/system Git config disabled.

During [`task:publish`](/reference/tasks#publish-an-external-group), `export` fetches the bundle directly into `worker_bundle`, pulls the regular `.env` file at `environment_path` for secret checks, optionally pulls `.git/orbit/proposed-pr-bodies/task-<id>.md` (up to 64 KiB) for [docs-impact waivers](/reference/tasks#docs-impact-waivers-in-a-copy), and runs `scan`.

`task-copy-base` (`base` and `base-cleanup`) is called by both `operation-task-sync-copy` ([`task:sync`](/reference/tasks#bring-main-into-a-copy)) and `task-instance-check` (for handoff checks). `base` is the only phase that receives a Git credential (`ORBIT_GIT_AUTHORIZATION`), passed in memory over pipelined stdin to `git fetch` on the Node so no secret ever touches argv, disk, or the guest.

## Check phases

`tasks.check` runs `task-instance-check` when the task group owns an active, running container Instance (`tasks.copy_completion_failed`, `tasks.copy_not_ready`, `tasks.instance_not_running`, or `tasks.no_workspace` otherwise). Handoff checks on Projects requiring hosted CI refuse with `tasks.instance_hosted_check_unavailable`.

Baseline checks run with an empty setup list because the copy cloned a prepared default seed. Handoff checks first run `task-copy-base`, then execute up to four phases as `agent` in `/srv/workspace`:

| Phase | Work |
| --- | --- |
| `base-merge` | Commits uncommitted work and merges `refs/remotes/origin/<default>` if clean; conflicts list paths without mutating state |
| `check` | Records the handed-off tree, runs the Project check, validates command deliverables and `fails_on_base` runs, and captures the final snapshot |
| `commit` | Stages all changes and creates a clean Orbit commit (hooks disabled) unless `HEAD` is already a clean recorded candidate |
| `commit-ref` | Pins the accepted candidate under `refs/orbit/check-operations/{operation}` |

For `fails_on_base` deliverables, the base tree extracts once into guest scratch and each command receives a private copy (`cp --reflink=auto`) with `vendor` and `node_modules` copied from its directory and ancestors while rejecting symlinks outside the checkout. Command output is fetched into private worker scratch, redacted, and deleted; the typed result is capped at 512 KiB.

## Verification

Local regression tests execute the real Ansible and Jinja tasks against real Git checkouts to verify snapshots, receipt bounds, bundle exports, and base-merge behavior.

To prevent groups on the same Node from blocking one another ([Two groups on one Node](/reference/tasks#two-groups-on-one-node)), Swamp names the workspace behavior `task-workspace-<node>-<group>` and the check behavior `task-instance-check-<node>-<group>`.

## Errors

Workspace and check phases return these error codes on failure:

| Code | Meaning |
| --- | --- |
| `tasks.no_workspace` | Group has no recorded container Instance branch or workspace |
| `tasks.workspace_target_invalid` | Missing or invalid recorded runtime, phase, or snapshot ID |
| `tasks.workspace_identity_changed` | Checkout path, branch, or expected head changed unexpectedly |
| `tasks.workspace_snapshot_mismatch` | Snapshot digest or candidate head/tree does not match |
| `tasks.workspace_receipt_invalid` | Receipt missing, oversized, symlinked, malformed, or mismatched |
| `tasks.workspace_phase_failed` | Command timeout, tool error, transfer error, or cleanup failure |
| `tasks.copy_completion_failed` | Task copy's last completion failed (retry with `orbit instance:complete <id>`) |
| `tasks.copy_not_ready` | Task copy is not `active` yet |
| `tasks.instance_not_running` | Container runtime is stopped |
| `tasks.instance_hosted_check_unavailable` | Container handoff requires hosted CI without a Node push |
