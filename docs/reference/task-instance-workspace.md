---
title: "Task Instance workspace phases"
description: "The internal workspace contract for task work in an existing container Instance."
covers:
  - swamp/ansible/task-instance-workspace.yml
  - swamp/ansible/tasks/task-instance-workspace-phase.yml
  - swamp/workflows/workflow-task-instance-workspace.yaml
  - swamp/workflows/workflow-task-instance-check.yaml
  - apps/gateway/app/Domain/Tasks/TaskInstanceWorkspace.php
  - swamp/extensions/behaviors/operations/task-instance-workspace/*
---

# Task Instance workspace phases

This internal child workflow supplies workspace evidence and branch export for later container task flows. It provides no checkout restoration. The [check phases](#check-phases) run `tasks.check` for a group that owns a container Instance workspace. No public command creates that ownership yet, and nothing here changes runtime selection, Instance lifecycle or task VM behavior. [Task commands](/reference/tasks) keep their existing workspace path until their container admission is implemented.

[Internal phase chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/task/instance-workspace.mmd).

## Contract

The caller supplies the recorded Incus project and container name, expected branch and head, and a unique snapshot ID. There is no default project. Ansible reaches the recorded Node through its pinned SSH identity. Every guest command runs as `agent` in `/srv/workspace`, with closed stdin. No credential is supplied to the guest. The Node executes no repository code.

| Phase | Required evidence | Result or effect |
| --- | --- | --- |
| `preflight` | Branch and head | Checkout and Git-directory device/inode identity; no clean-state admission policy |
| `before` | Preflight identity | Original HEAD, workspace tree, index tree and digest, bound to this snapshot and runtime |
| `after` | Original snapshot digest | The same evidence after the turn, with the original digest |
| `receipt` | Original digest and candidate head/tree | At most 65,536 UTF-8 bytes from the guest scratch receipt; reject a symlink, oversized data or a different snapshot/candidate |
| `export` | Preflight identity and candidate head/tree | Create and verify the exact candidate bundle as `agent`; transfer it to Node scratch and verify its checksum |
| `discard` | Preflight identity | Remove only this snapshot's guest scratch after its users have stopped |

Snapshots use a temporary Git index. They include unignored untracked files, staged files (including force-added ignored paths), spaces and symlinks, and leave the real index unchanged. Guest scratch contains all indexes and evidence. Git hooks and fsmonitor are disabled. Commands isolate global and system Git configuration and trust only the exact checkout. Evidence does not execute project setup, checks or scripts.

The caller passes the preflight `checkout_fingerprint` to every later phase. Original and after records are immutable on repeat. Their digests bind the runtime and checkout identity.

The receipt lives at `/tmp/orbit-task-workspace-SNAPSHOT/receipt.json`, outside the checkout. Its envelope contains `snapshot_id`, `candidate: {head, tree}` and an object `receipt`. The reader bounds bytes before JSON decoding and checks the envelope against the requested candidate. The original snapshot must also match that candidate.

Worker input and result files are private and removed after the phase settles, including failure. A failed phase retains guest evidence for diagnosis. A successful export returns its Node scratch path to the caller, which removes it after use. Transfer or guest-cleanup failure removes Node scratch before returning failure. Export removes its guest bundle after transfer, including failure. The parent calls `discard` after all guest evidence users stop, on success or failure. Interrupted cleanup can leave scratch for explicit recovery; it never justifies deleting user files.

## Check phases

`tasks.check` takes this path when the group owns a container Instance: the group's Instance has a recorded runtime and names the group as its workspace owner. Today only a test fixture creates that state; attach and task start add it later. The admission skips the task VM wake and VM ownership checks and calls no task VM admission, slot or image code. It requires a running runtime (`tasks.instance_not_running`) and a recorded Instance branch (`tasks.no_workspace`).

Before it records a check row, it refuses a handoff whose Project requires hosted CI (`tasks.instance_hosted_check_unavailable`) and a setup step named `@orbit/ci-caches` (`tasks.instance_setup_unavailable`). The hosted push from the Node arrives with publication. There is no task VM fallback.

The intent is the task VM check intent with two differences: `checkout_path` is `/srv/workspace`, and `workspace` holds the recorded Incus project and container, the Instance branch and a new scratch ID. The SSH target is the Instance Node. The parent workflow runs `Check in the Instance` and skips the task VM steps.

The child runs three Ansible phases through this playbook. Each runs as `agent` in the container with closed stdin. The phases check the checkout root and branch, but need no expected head.

| Phase | What it does |
| --- | --- |
| `check` | Runs baseline setup, the Project check, the snapshot, each command deliverable and its start-commit run, and the final snapshot |
| `commit` | Stages everything, commits as Orbit with hooks off and reads back `HEAD`, its tree and its parent |
| `commit-ref` | Records the accepted candidate under `refs/orbit/check-operations/{operation}` |

Commands run as `env -C <directory> … /bin/bash -lc <command>` with the task check variables, `HOME=/home/agent` and the agent's login shell. They use the dependencies installed in the container. A start-commit run extracts the base commit into guest scratch, copies the installed `vendor` and `node_modules` directories and the declared test paths, then runs the command. Commands get no credentials. The admitted `checkout_path` is never a Node path: a Node directory with the same path is never read or run.

Snapshots use the temporary index described above, so a staged, force-added ignored file is in the checked tree. The commit stages the same work, so its tree matches the snapshot.

Each command writes its output to a guest file. Ansible copies the output folder to the Node and fetches it into the private worker run folder; the guest and Node copies are removed on success and failure. The typed result holds only exit codes and Git evidence, within the 512 KiB result bound; a larger diff fails the phase.

The existing task check rules then read, redact and delete the output files, judge deliverables, check the candidate commit and shape the receipt. A phase failure returns its workspace error code as the check's error code. The parent captures the receipt from `Check in the Instance`; the Gateway records it as it records a task VM check.

## Verification

[Phase cases](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/task/instance-workspace.cases.yaml) specify local regressions and the deferred live proof. Local tests execute the real Ansible/Jinja tasks through a strict disposable transport shim and real Git checkouts. They do not prove Incus, SSH, Node privileges or a public command. Live proof is deferred. It uses a supplied disposable normal Instance, compares before/after trees, and verifies the exported bundle's branch head and tree on its Node.

## Errors

A failed phase returns one of these codes.

| Code | Meaning |
| --- | --- |
| `tasks.workspace_target_invalid` | Missing or invalid recorded runtime, phase or snapshot ID |
| `tasks.workspace_identity_changed` | The checkout, branch or expected head differs |
| `tasks.workspace_snapshot_mismatch` | Snapshot digest or candidate does not match |
| `tasks.workspace_receipt_invalid` | Receipt is missing, oversized, unsafe, malformed or bound to other evidence |
| `tasks.workspace_phase_failed` | A tool, command time limit, transfer or cleanup failed |
| `tasks.instance_not_running` | The check admission found a stopped container runtime |
| `tasks.instance_hosted_check_unavailable` | A container handoff needs hosted CI, which needs the Node push |
| `tasks.instance_setup_unavailable` | A container baseline names the `@orbit/ci-caches` setup step |

## Execution choices

Native [workflow dependencies](https://swamp-club.com/manual/reference/workflows) own phase order and worker cleanup. The shared Ansible input behavior ships the playbook and pins the recorded Node. Ansible uses its file modules for Node scratch and its stat module to verify transfers. Git has no Ansible module for temporary-index snapshots or a byte-bounded receipt envelope, so the phase contains a visible Python program for that gap. It runs through Incus only as `agent`; no helper is copied to the Node or guest. The small behavior reads and validates the private result as typed child evidence. No custom transport or external API client is added.
