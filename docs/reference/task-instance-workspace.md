---
title: "Task Instance workspace phases"
description: "The internal workspace contract for task work in an existing container Instance."
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

This internal child workflow supplies workspace evidence and branch export for task copies. It provides no checkout restoration. The [check phases](#check-phases) run `tasks.check` for a group that owns a container Instance workspace. [`task:start`](/cli/task/start#a-task-copy) creates that ownership for a [task copy](/reference/tasks#task-copy). These phases own neither runtime selection nor the Instance lifecycle. [Task commands](/reference/tasks) own admission and cleanup.

[Internal phase chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/task/instance-workspace.mmd).

## Contract

The caller supplies the recorded Incus project and container name, expected branch and head, and a unique snapshot ID. There is no default project. Ansible reaches the recorded Node through its pinned SSH identity. Every guest command runs as `agent` in `/srv/workspace`, with closed stdin. No credential is supplied to the guest. The Node executes no repository code.

| Phase | Required evidence | Result or effect |
| --- | --- | --- |
| `preflight` | Branch and head | Checkout and Git-directory device/inode identity; no clean-state admission policy |
| `before` | Preflight identity | Original HEAD, workspace tree, index tree and digest, bound to this snapshot and runtime |
| `after` | Original snapshot digest | The same evidence after the turn, with the original digest |
| `receipt` | Original digest and candidate head/tree | At most 65,536 UTF-8 bytes from the guest scratch receipt; reject a symlink, oversized data or a different snapshot/candidate |
| `export` | Preflight identity and candidate head/tree | Create and verify the exact candidate bundle as `agent`; transfer it to Node scratch and verify its checksum, or with `worker_bundle` fetch it to the worker |
| `discard` | Preflight identity | Remove only this snapshot's guest scratch after its users have stopped |
| `scan` | Head, the copy's start and `nested` | Run [the copy check](/reference/tasks#the-copy-check) as root in the container, with Git as `agent`; return paths, lines, secret names and the object digest, never a value |
| `base` | Branch, default branch, repository and mirror; the group's published commits and approved commit | Fetch the default branch into the copy's Node mirror and import only what the copy lacks into `refs/remotes/origin/<default branch>`; then read the copy's `work` |
| `base-cleanup` | The copy's Instance id | Remove the copy's bundle on the Node and its root-owned bundle folder in the guest |

Snapshots use a temporary Git index. They include unignored untracked files, staged files (including force-added ignored paths), spaces and symlinks, and leave the real index unchanged. Guest scratch contains all indexes and evidence. Git hooks and fsmonitor are disabled. Commands isolate global and system Git configuration and trust only the exact checkout. Evidence does not execute project setup, checks or scripts.

The caller passes the preflight `checkout_fingerprint` to every later phase. Original and after records are immutable on repeat. Their digests bind the runtime and checkout identity.

The receipt lives at `/tmp/orbit-task-workspace-SNAPSHOT/receipt.json`, outside the checkout. Its envelope contains `snapshot_id`, `candidate: {head, tree}` and an object `receipt`. The reader bounds bytes before JSON decoding and checks the envelope against the requested candidate. The original snapshot must also match that candidate.

Worker input and result files are private and removed after the phase settles, including failure. A failed phase retains guest evidence for diagnosis. A successful export returns its Node scratch path to the caller, which removes it after use.

[`task:publish`](/reference/tasks#publish-an-external-group) of a task copy names `worker_bundle`, a path in its own publication scratch on the worker: the export fetches the bundle there, checks its checksum, and leaves no Node scratch. It also names `environment_path` and `worker_environment`: the Node pulls the copy's `.env` from `/srv/workspace/<environment_path>`, requires a regular file, and fetches it to that worker path for the publish content check. Its bytes enter no phase result or log. A missing file or a link fails the export.

After the export, the publish runs the `scan` phase. It names the copy's `start`, `nested` (the copy's intent has `club_key`) and the proposed body's path. The phase sends `swamp/ansible/task_copy_scan.py` into the container as the `-c` argument of `python3 -I`, with no stdin and a clean environment, and records its one JSON line as the evidence. The program itself never fails the phase: an error is evidence too, `tasks.publish_secret_unchecked`, which the publish reads.

The publish also names `proposed_body_path`, the task branch's proposed pull request body `.git/orbit/proposed-pr-bodies/task-<id>.md`, and `worker_proposed_body`. The Node pulls that file and fetches it to the worker only when it is a regular file of at most 64 KiB. A missing file or a link fetches nothing and does not fail the export. The file is data: the publish reads only its waiver lines ([Docs-impact waivers in a copy](/reference/tasks#docs-impact-waivers-in-a-copy)).

`base` returns the default head, the branch commit, `behind_by`, the start and `work`. After the import, `agent` reads whether the copy's work is on the new default head, as [published work of a copy](/reference/tasks#published-work-of-a-copy) defines.

[`task:sync`](/reference/tasks#bring-main-into-a-copy) of a task copy runs `base`, then `base-cleanup`, through their own child workflow, `task-copy-base`, which only the copy's sync workflow, `operation-task-sync-copy`, calls. The shared child of this page takes no secret and no base input. `base` is the one phase that uses a credential: the sync's read token, a Git header that the child names as `git_authorization`. Only its step `Bring in the base` reads it, and the Node passes it to its one `git fetch` as that command's environment, through the stdin of a pipelined task, which Ansible keeps in memory. It never reaches argv, a file, the guest or a stored output, so a killed run leaves no credential anywhere.

The fetch goes into the copy's mirror, `/var/lib/orbit/instance-repositories/<instance>.git`. A missing mirror is first made from the default's mirror; a copy whose default is gone syncs only when its own mirror exists. The Node bundles the default branch with the copy's `origin/<default branch>` and branch heads as prerequisites, where the mirror holds them. When the copy holds the head already, no bundle is made.

Root removes and makes the copy's bundle folder and pushes the bundle into it, root-owned with the agent's group, mode `0640`, so `agent` can read it and cannot swap it for a link. As `agent`, the guest fetches it into `refs/remotes/origin/<default branch>` only. HEAD, the working tree, the index and the local branches stay as they are.

The copy's Instance id names its Node scratch, `/var/tmp/orbit-task-base-<instance>`, and its bundle folder, `/tmp/orbit-task-base-<instance>`. The bundles go when the base ends: in `base-cleanup`, at the next `base` of the copy, which removes them before it starts, or at the copy's destroy, which removes the Node scratch with the mirror. A test proves that a failed fetch, a cancel during the fetch and a cancel after the push leave no credential and, once one of these ran, no bundle.

## Check phases

`tasks.check` takes this path when the group owns a container Instance: the group's Instance has a recorded runtime and names the group as its workspace owner. A [task copy](/cli/task/start) creates that state. The admission skips the task VM wake and VM ownership checks and calls no task VM admission, slot or image code. It requires a task copy whose last completion did not fail (`tasks.copy_completion_failed`) and that is `active` (`tasks.copy_not_ready`), a running runtime (`tasks.instance_not_running`) and a recorded Instance branch (`tasks.no_workspace`).

Before it records a check row, it refuses a handoff whose Project requires hosted CI (`tasks.instance_hosted_check_unavailable`). The hosted push from the Node arrives with publication. There is no task VM fallback.

A baseline in a container workspace runs no setup steps: its intent holds an empty `setup` list. The container is a copy of the Project default, whose seed already ran them, as for every [clone](/reference/development-containers#lifecycle). The baseline runs the Project check alone; a Project without one passes on the snapshot. A task copy [starts clean](/reference/development-containers#a-task-copy-starts-clean), so its baseline snapshot is the start commit plus ignored files. A task VM baseline keeps every setup step.

The intent is the task VM check intent with two differences: `checkout_path` is `/srv/workspace`, and `workspace` holds the recorded Incus project and container, the Instance branch and a new scratch ID. The SSH target is the Instance Node. The parent workflow runs `Check in the Instance` and skips the task VM steps.

The child runs up to four Ansible phases through this playbook. Each runs as `agent` in the container with closed stdin. The phases check the checkout root and branch, but need no expected head. A handoff check first brings in the default branch through `task-copy-base`, as a sync does, then runs `base-merge` ([The handoff check merges main first](/reference/tasks#the-handoff-check-merges-main-first)).

| Phase | What it does |
| --- | --- |
| `base-merge` | Merges `refs/remotes/origin/<default>` when clean, after it commits uncommitted work. A conflict names its paths and changes nothing |
| `check` | Runs the Project check, the snapshot, each command deliverable and its start-commit run, and the final snapshot |
| `commit` | Stages everything and commits as Orbit with hooks off, unless `HEAD` is a clean recorded candidate. Reads back `HEAD`, its tree and a new commit's parent |
| `commit-ref` | Records the accepted candidate under `refs/orbit/check-operations/{operation}` |

Commands run as `env -C <directory> … /bin/bash -lc <command>` with the task check variables, `HOME=/home/agent` and the agent's login shell. They use the dependencies installed in the container. A start-commit run extracts the base commit into guest scratch, copies the installed `vendor` and `node_modules` directories and the declared test paths, then runs the command. Commands get no credentials. The admitted `checkout_path` is never a Node path: a Node directory with the same path is never read or run.

Snapshots use the temporary index described above, so a staged, force-added ignored file is in the checked tree. The commit stages the same work, so its tree matches the snapshot.

Every check makes a fresh Orbit commit on the checked `HEAD`, with the checked tree as its tree, also an empty one when the implementer committed the work. Its message is the Gateway's cleaned handoff message, so a candidate never carries a skip directive from the implementer's message.

The one exception is a clean tree whose `HEAD` an earlier check recorded under `refs/orbit/check-operations/`, such as a published head that is checked again. That check makes no commit: its candidate is that `HEAD`. Another empty commit would be work that origin lacks, and it would make a [cancel](/reference/development-containers#workspace-work-state) refuse the copy. A task VM check always commits.

Each command writes its output to a guest file. Ansible copies the output folder to the Node and fetches it into the private worker run folder; the guest and Node copies are removed on success and failure. The typed result holds only exit codes and Git evidence, within the 512 KiB result bound; a larger diff fails the phase.

The existing task check rules then read, redact and delete the output files, judge deliverables, check the candidate commit and shape the receipt. A phase failure returns its workspace error code as the check's error code. The parent captures the receipt from `Check in the Instance`; the Gateway records it as it records a task VM check. The `candidate` step receives the commit read as a map, closed with `} }`: Swamp ends an expression at the first `}}`.

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
| `tasks.copy_completion_failed` | The check admission found a task copy whose last completion failed; the details name the Operation, its failed step, its error code and the retry `orbit instance:complete <copy-id>` |
| `tasks.copy_not_ready` | The check admission found a task copy that is not `active` yet: its create, its completion or a retry still runs |
| `tasks.instance_not_running` | The check admission found a stopped container runtime |
| `tasks.instance_hosted_check_unavailable` | A container handoff needs hosted CI, which needs the Node push |

## Execution choices

Native [workflow dependencies](https://swamp-club.com/manual/reference/workflows) own phase order and worker cleanup. The shared Ansible input behavior ships the playbook and pins the recorded Node. Ansible uses its file modules for Node scratch and its stat module to verify transfers. Git has no Ansible module for temporary-index snapshots or a byte-bounded receipt envelope, so the phase contains a visible Python program for that gap. It runs through Incus only as `agent`; no helper is copied to the Node or guest. The small behavior reads and validates the private result as typed child evidence. No custom transport or external API client is added.

The caller also names the task group. The workspace phase runs as the behavior `task-workspace-<node>-<group>`, and the base-merge, check, commit and commit-ref phases as `task-instance-check-<node>-<group>`. Swamp locks a behavior for the whole step, so copies of two groups on one Node never wait for each other ([Two groups on one Node](/reference/tasks#two-groups-on-one-node)). The short prepare, input, evidence, local, candidate, finish and cleanup steps keep their names by Node or one shared name.
