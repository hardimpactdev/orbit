---
title: "Instance cloning"
description: "Copy committed development source and stored configuration through one native Swamp workflow."
covers:
  - apps/gateway/app/Actions/Instances/StartInstanceCloneAction.php
  - apps/gateway/app/Domain/Instances/{InstanceCloneProjection,NativeInstanceProvisioningIntent}.php
  - apps/gateway/app/Http/{Controllers/Api/InstanceClonesController.php,Requests/Instances/CloneInstanceRequest.php}
  - apps/cli/app/Commands/Instances/CloneInstanceCommand.php
  - packages/php-sdk/src/Requests/Instances/CloneInstanceRequest.php
---

# Instance cloning

Cloning creates an independent development Instance from an active candidate. The Gateway reserves the target, captures public intent and copies the candidate's stored environment values. One native Swamp workflow runs on Node 209 and uses official `@swamp/ssh` actions on the admitted source and destination Nodes. The candidate keeps running.

The current workflow supports development Projects without Routes, database attachments, Process definitions or Schedule definitions. Production placements, task VM placements and SQLite seeding return `operation.kind_unavailable` before target reservation. These variants need their native child composition before they can be admitted.

```bash
orbit instance:clone <candidate> <node> <name> --preview-name=preview [--branch=BRANCH]
```

The CLI calls `POST /api/v1/instances/{candidate}/clone`. The caller needs access to both Nodes. An `Idempotency-Key` returns the same admission after a lost response. The request accepts no Project, commit, Unix user or filesystem path.

| Input | Meaning |
| --- | --- |
| `node_id` | Active Linux destination with an active `app-dev` role and stored apps root. |
| `name` | New development Instance name. |
| `preview_name` | Reserved preview identity; supported Project types create no Route. |
| `branch` | Existing repository branch for the target; defaults to the candidate's recorded branch. A missing requested branch is refused. |
| `sqlite_source_path` | Currently unavailable. |

## Candidate rules

The candidate must be active, have a recorded source profile, and keep its recorded branch checked out. Native read steps verify the repository identity, branch, commit, clean working tree and absence of submodules before changing target source. Git hooks and filesystem monitors are disabled. An origin containing credentials is rejected on the Node before its value can enter native history.

Swamp fetches committed source from the admitted Project repository. Working files, dependency directories and logs from the candidate are not copied. When the candidate and target use the same branch, their commits must match. Source preparation records the existing preparation ID with the directory's device and inode; an identical failed attempt can reuse only that owned directory.

## Configuration and result

The shared native VP preparation jobs capture the checkout's pinned requirements, prepare the manager only when absent, and install and verify the admitted runtime through the stock VP CLI. A project without VP requirements skips installation. The final clone result requires those jobs to succeed before the Gateway activates the target.

The target receives independently encrypted stored candidate values, excluding `DB_*` and candidate WebSocket app credentials. The Gateway publishes the rendered environment as a named vault execution copy. Official SSH resolves it only for the environment write; private values never enter workflow inputs or public results. The file is written atomically with mode `0640`.

The existing scoped GitHub App mint primitive supplies repository read access. When GitHub reports no App installation for the repository, the descriptor contains no token and Git reads use anonymous access. Authentication and service errors still refuse the read. Its root key stays in the Gateway. All other steps use captured inputs and native outputs; no step reads intent or reports progress through Gateway HTTP.

### Terminal projection and retries

The shared terminal subscriber projects source facts and activates the target only after successful native completion. Failure or cancellation keeps the target reserved and records the error. API cancellation requests native cancellation; an acknowledgment does not prove that the root has settled.

An identical retry reuses the reserved target. A changed candidate, Node, name, preview, branch or SQLite input returns `instance.clone_retry_conflict`. An identical request after completion keeps the existing Instance. The candidate's source, Processes, environment and database remain unchanged.
