---
title: "Instance transfer"
description: "Move a development Instance through one native Swamp workflow."
covers:
  - apps/gateway/app/Domain/Instances/{NativeInstanceTransferProjection,NativeInstanceTransferIntent,direct-operations}.php
  - swamp/{workflows/workflow-operation-instance-transfer.yaml,extensions/behaviors/operations/instance-source-command/**}
---

# Instance transfer

A transfer moves one active development Instance to another `app-dev` Node. It keeps the Instance ID and Project. The Gateway records immutable placement intent and starts one native Swamp workflow. Swamp runs on Node 209 and uses official `@swamp/ssh` actions for the admitted source and destination Nodes. The Gateway projects placement from the terminal native result.

```bash
orbit instance:transfer <instance> <node> [--name=NAME] [--sqlite-source-path=PATH] [--force] [--json]
```

The `@nckrtl/instance-source-command` behavior serves only clone and transfer. `instance:rename` uses no workflow: the Gateway checks the Node agent's checkout report.

The CLI calls `POST /api/v1/instances/{instance}/transfer` and follows the returned Operation. An `Idempotency-Key` reuses admission after a lost response.

Both Nodes must be active Linux Nodes with an active `app-dev` role and an active Cluster. The source must be an active development Instance with one authoritative Route and no removal in progress. The destination must be a different Node that the Project permits. A stored absolute destination apps path is required. The run trusts the recorded source placement and reads no source facts before it stops Processes. Extraction refuses an occupied destination path.

The current native admission refuses VM placements and SQLite copies. It returns `operation.kind_unavailable` before reserving or moving anything.

An Instance's Schedules move with it. At run start the Gateway removes their native triggers, so no fire runs against either Node during the move. When the run ends, the Gateway rewrites each published Schedule's workflow for the recorded placement and sets its trigger again: on the destination after a cutover, on the source after a failed move. It also refuses an unknown source profile. Existing external database bindings stay with the Instance.

Each additional Route with a web root must be active and have no domain or placement change. A public web-root Route cannot move across Clusters. The Route family captures the complete primary and web-root inventory and reserves any replacement primary during admission.

## Source and archive

A separate action reads the source's Git directories and creates a private archive on the source. Official SSH copies it through private scratch space on Node 209 to the destination, where a separate extraction action prepares the checkout. Archive bytes never enter the Gateway, workflow arguments or JSON results. Visible cleanup actions remove the three scratch archives.

A linked worktree becomes an independent checkout. Its committed history, branch, origin, staged index, staged new objects, unstaged edits, and untracked files are retained. The source `.env` stays behind. The source index and common repository remain unchanged. A common checkout with other linked worktrees cannot be removed by this transfer. Git commands disable checkout hooks and filesystem monitors.

Extraction rejects paths and links that escape the checkout. Local staging directories have private permissions and are removed when the action ends. Native steps retain only public archive metadata.

The Gateway renders the destination `.env` from the Instance's stored configuration for the new placement and passes it as a vault execution copy. The run writes it with the official file behavior, mode 0640 with group `orbit-env`, so the Node agent can report its key names. An Instance without stored configuration refuses admission with `env.configuration_missing`; import its `.env` first with [`env:import`](/cli/env/import). The run then runs the Project setup steps in the destination checkout, as a development create does. The first failing step restores the source.

## Placement and publication

Admission captures source and destination Process installation batches without account or port discovery. The destination verifies stored port intent before the source pauses. Native steps stop source Processes before the archive, create and start destination Processes before the Route is published, and verify running Vite listeners before retiring source runtime. Desired stopped Processes stay stopped. Before cutover, recovery restores source runtime before discarding the destination. Installation material is readable only on its admitted Node while the stored Instance and Process paths match that placement. Account home and group resolve locally through the canonical Process installer.

Swamp reuses the canonical native Route forward and restore children with the complete captured publication inventory. The forward child's ready result makes the destination authoritative before source retirement. The Gateway reads it from the run's steps; a run that only restored the source fails with `instance.transfer_failed`. The [`instance:transfer` chart](/cli/instance/transfer) shows each step. After publication, one `curl` with the Route domain as SNI checks that the destination answers. Only then does the run retire the source; a failed check keeps the source for repair.

At root terminal, the Gateway changes the Instance placement, relocates Process working directories and explicit environment-file paths, and changes captured Route placements in one database transaction. A later retirement failure preserves that destination receipt. Paths outside the source checkout refuse admission; null defaults stay null. The Operation retains its truthful source Node throughout the workflow.

Workload access, PHP, environment URLs, Caddy and DNS use the prospective placements captured during admission. Route environment material uses named vault execution copies. No step reads intent or reports progress through a Gateway callback. Certificate signing remains the scoped Gateway primitive.

After destination publication, Swamp removes unused source certificate and LAN artifacts, then deletes the recorded source checkout. The Gateway captures and refreshes the source Node’s host-only checkout inventory with the same selection as destroy.

Immediately before removal, the shared destroy phase rechecks the owner’s Git registry and recorded checkout paths without traversing checkout contents. The [known removal limits](/reference/instance-removal) also apply. A dependent added after archiving stops retirement and leaves the source intact; the destination stays authoritative. Linked worktree removal unregisters only that tree through Git and preserves the common repository and sibling worktrees. A refused check or unregister failure has no recursive-delete fallback.

The retirement command retains its bounded public receipt before cleanup. The Operation exposes the refusal’s domain code and dependent paths/Instance IDs, and preserves unknown-checkout warnings. A workflow loaded before its inventory producer refuses retirement safely rather than deleting without the inventory; it never evaluates a missing intent key.

The Gateway then retires only the replaced primary Route and records completion. Other web-root Route IDs remain unchanged.

## Failure and cancellation

Before cutover, the native DAG restores source publication, drops reserved destination Route intent and discards only a proven destination checkout. Failed cleanup remains visible as failure. It never authorizes deletion of an unproven replacement.

After successful physical forward publication, the destination is authoritative. A later retirement failure or cancellation preserves its placement result and source residue for repair. Failure before successful forward publication restores source publication and runtime before discarding the proven destination. Failed recovery retains residue and refuses to guess that cleanup succeeded. Cancellation uses native cancellation; an acknowledgment is not terminal evidence.

Native workflow resume cannot retry retirement after cleanup removes the run’s intent file. After a post-cutover refusal there is currently no supported retirement-only command; the destination keeps serving and the source must stay retained. A fresh identical transfer is not a proven retirement-only recovery entry. A follow-up must add re-preparation and a scoped retry entry.

No transfer action calls orbit-agent. The agent remains frozen.
