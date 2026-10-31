---
title: "Instance cloning"
description: "Create a production Instance from an admitted candidate through one native Swamp workflow."
covers:
  - apps/gateway/app/Actions/Instances/StartInstanceCloneAction.php
  - apps/gateway/app/Domain/Instances/{InstanceCloneProjection,NativeInstanceProvisioningIntent,NativeInstanceProvisioningProjection}.php
  - apps/gateway/app/Http/{Controllers/Api/InstanceClonesController.php,Requests/Instances/CloneInstanceRequest.php}
  - apps/cli/app/Commands/Instances/CloneInstanceCommand.php
  - packages/php-sdk/src/Requests/Instances/CloneInstanceRequest.php
---

# Instance cloning

Cloning creates a production Instance from an active candidate. The Gateway records the source and destination placements, reserves the target, and starts one native Swamp workflow. Swamp inspects the candidate on its own worker and prepares the destination on its own worker. The candidate keeps running.

The native workflow currently supports Projects without Routes, database attachments, Process definitions, or Schedule definitions. Neither placement may be a task VM. SQLite seeding and other combinations return `operation.kind_unavailable` before the Gateway reserves a target.

`instance:create` on an `app-prod` Node returns `instance.candidate_required`. The `app-prod` role decides this, not `APP_ENV`.

## Clone

Choose a candidate and a destination with an active `app-prod` role.

```bash
orbit instance:clone <candidate> <node> <name> --preview-name=preview [--branch=BRANCH]
```

The CLI calls `POST /api/v1/instances/{candidate}/clone`. The API returns HTTP 202 with the admitted Operation. An optional `Idempotency-Key` returns the same admission after a lost response. Swamp owns steps, placement, failure handling, and cancellation. The Gateway records the resulting Instance state.

| Input | Meaning |
| --- | --- |
| `node_id` | Destination Node with an active `app-prod` role. Required. |
| `name` | New Instance name. Required. |
| `preview_name` | Reserved preview identity. Required; no Route is created for the supported Project types. |
| `branch` | Repository branch for the production target. Defaults to the candidate's branch, or its deployment branch for a production candidate. |
| `sqlite_source_path` | Unavailable in the native clone workflow. |

The request accepts no Project, commit, Unix user, or filesystem path. The candidate decides the Project. The caller needs access to both Nodes. The successful result is `{instance_id, ready: true}`.

## Candidate and destination

A candidate needs a recorded source profile. An unclassified candidate returns `instance.source_profile_missing` before target reservation; complete native registration or source preparation first.

A candidate is an active development Instance with its recorded checkout, or an active production Instance with a selected release. Swamp verifies its repository, branch, clean working tree, submodules, and committed source. It checks that the commit and target branch exist in the Project repository. Candidate inspection uses the admitted source account and disables Git hooks and filesystem monitors.

The Gateway records placement metadata only. It does not run Git or inspect the candidate during admission. The source worker receives read-only public intent and the declared Git read material. Only the destination worker can project the parent result or read its environment material.

The destination must be an active Linux Node with an active `app-prod` role. A Project has at most one production Instance per Node. Swamp creates the fixed `orbit-app-<ProjectID>` account and private home, then prepares `releases/initial` from the repository. It performs these actions through local system commands. It does not invoke agent action commands.

## What the clone gets

The target gets independent source and stored configuration.

| Part | Result |
| --- | --- |
| Source | Committed source checked out from the Project repository. Candidate working files are not copied. |
| Environment | Stored candidate values, encrypted for the new Instance, excluding `DB_*` and candidate WebSocket app credentials. Production mode is set. |
| Dependencies | The Project's admitted Vite+ environment, when applicable. |
| Release | A prepared initial checkout; no release is selected. |

Private values stay inside the native effect callback. Workflow inputs and resources contain only public source identity and placement. Swamp checks the destination before replacing files and refuses foreign ownership or unsafe paths.

Cloning does not stop the candidate's Processes or Schedules, pause its queues, or change its source, environment, or database.

## Failure and retry

A failed or cancelled workflow records its terminal Operation status and releases the Gateway admission hold. Swamp records the failed stage. An identical request can use the existing reserved target; a changed candidate, Node, name, preview, branch, or SQLite path returns `instance.clone_retry_conflict`.

After completion, an identical request keeps the existing Instance. Removing a candidate with an incomplete clone returns `instance.clone_in_progress`.

## Next steps

Check the copied environment values, synchronize them, and record the Project's deploy steps. Then run `instance:deploy` to create and select the first release.

## Why it works this way

A repository holds code but not the Instance's stored environment. A candidate supplies both identities. Rebuilding committed source avoids carrying dependencies, logs, caches, or host configuration into production. A separate first deployment gives you a chance to check production configuration before selecting a release.
