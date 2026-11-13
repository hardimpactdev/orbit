---
title: "Task Instance workspace phases"
description: "The internal workspace contract for task work in an existing container Instance."
covers:
  - swamp/ansible/task-instance-workspace.yml
  - swamp/ansible/tasks/task-instance-workspace-phase.yml
  - swamp/workflows/workflow-task-instance-workspace.yaml
  - swamp/extensions/behaviors/operations/task-instance-workspace/*
---

# Task Instance workspace phases

This internal child workflow supplies workspace evidence and branch export for later container task flows. It provides no checkout restoration. It changes no public command, task admission, runtime selection, Instance lifecycle or task VM behavior. [Task commands](/reference/tasks) keep their existing workspace path until their container admission is implemented.

[Internal phase chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/task/instance-workspace.mmd).

## Contract

The caller supplies the recorded Incus project and container name, expected branch and head, and a unique snapshot ID. There is no default project. Ansible reaches the recorded Node through its pinned SSH identity. Every guest command runs as `agent` in `/srv/workspace`, with closed stdin. No credential is supplied to the guest. The Node executes no repository code.

| Phase | Required evidence | Result or effect |
| --- | --- | --- |
| `preflight` | Branch and head | Checkout and Git-directory device/inode identity; no clean-state admission policy |
| `before` | Preflight identity | Original HEAD, workspace tree, index tree and digest, bound to this snapshot and runtime |
| `after` | Original snapshot digest | The same evidence after the turn, with the original digest |
| `receipt` | Original digest and candidate head/tree | At most 65,536 UTF-8 bytes from the guest scratch receipt; reject a symlink, oversized data or a different snapshot/candidate |
| `export` | Preflight identity and candidate head/tree | Verify the exact branch commit/tree, create its bundle as `agent`, and copy only that bundle into Node-owned scratch; verify the transferred checksum |
| `discard` | Preflight identity | Remove only this snapshot's guest scratch after its users have stopped |

Snapshots use a temporary Git index. They include unignored untracked files, staged files (including force-added ignored paths), spaces and symlinks, and leave the real index unchanged. Guest scratch contains all indexes and evidence. Git hooks and fsmonitor are disabled. Commands isolate global and system Git configuration and trust only the exact checkout. Evidence does not execute project setup, checks or scripts.

The caller passes the preflight `checkout_fingerprint` to every later phase. Original and after records are immutable on repeat. Their digests bind the runtime and checkout identity.

The receipt lives at `/tmp/orbit-task-workspace-SNAPSHOT/receipt.json`, outside the checkout. Its envelope contains `snapshot_id`, `candidate: {head, tree}` and an object `receipt`. The reader bounds bytes before JSON decoding and checks the envelope against the requested candidate. The original snapshot must also match that candidate.

Worker input and result files are private and removed after the phase settles, including failure. A failed phase retains guest evidence for diagnosis. A successful export returns its Node scratch path to the caller, which removes it after use. Transfer failure removes partial Node scratch. Export removes its guest bundle after transfer, including failure. The parent calls `discard` after all guest evidence users stop, on success or failure. Interrupted cleanup can leave scratch for explicit recovery; it never justifies deleting user files.

## Verification

[Phase cases](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/task/instance-workspace.cases.yaml) specify local regressions and the deferred live proof. Local tests execute the real Ansible/Jinja tasks through a strict disposable transport shim and real Git checkouts. They do not prove Incus, SSH, Node privileges or a public command. Live proof is deferred. It uses a supplied disposable normal Instance, compares before/after trees, and verifies the exported bundle's branch head and tree on its Node.

## Errors

| Code | Meaning |
| --- | --- |
| `tasks.workspace_target_invalid` | Missing or invalid recorded runtime, phase or snapshot ID |
| `tasks.workspace_identity_changed` | The checkout, branch or expected head differs |
| `tasks.workspace_snapshot_mismatch` | Snapshot digest or candidate does not match |
| `tasks.workspace_receipt_invalid` | Receipt is missing, oversized, unsafe, malformed or bound to other evidence |
| `tasks.workspace_phase_failed` | A tool, transfer or cleanup failed |

## Execution choices

Native [workflow dependencies](https://swamp-club.com/manual/reference/workflows) own phase order and worker cleanup. The shared Ansible input behavior ships the playbook and pins the recorded Node. Built-in Ansible modules own files, transfer checks and Node scratch. Git has no Ansible module for temporary-index snapshots or a byte-bounded receipt envelope, so the phase contains a visible Python program for that gap. It runs through Incus only as `agent`; no helper is copied to the Node or guest. The small behavior reads and validates the private result as typed child evidence. No custom transport or external API client is added.
