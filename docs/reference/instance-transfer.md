---
title: "Instance transfer"
description: "Move a development Instance through one native Swamp workflow."
---

# Instance transfer

A transfer moves one active development Instance to another `app-dev` Node. It keeps the Instance ID and Project. The Gateway records immutable placement intent and starts one native Swamp workflow. Swamp runs on Node 209 and uses official `@swamp/ssh` actions for the admitted source and destination Nodes. The Gateway projects placement from the terminal native result.

```bash
orbit instance:transfer <instance> <node> [--name=NAME] [--sqlite-source-path=PATH] [--force] [--json]
```

The CLI calls `POST /api/v1/instances/{instance}/transfer` and follows the returned Operation. An `Idempotency-Key` reuses admission after a lost response.

Both Nodes must be active Linux Nodes with an active `app-dev` role and an active Cluster. The source must be an active development Instance with one authoritative Route and no removal in progress. The destination must be a different Node that the Project permits. A stored absolute destination apps path is required. Filesystem ownership, the real source Git identity and the unoccupied destination are checked by the native workers.

The current native admission refuses VM placements, SQLite copies and Instances with Schedule definitions. It returns `operation.kind_unavailable` before reserving or moving anything. It also refuses an unknown source profile. Existing external database bindings stay with the Instance.

Each additional Route with a web root must be active and have no domain or placement change. A public web-root Route cannot move across Clusters. The Route family captures the complete primary and web-root inventory and reserves any replacement primary during admission.

## Source and archive

The destination is checked before capture. A separate action creates a private archive on the source. Official SSH copies it through private scratch space on Node 209 to the destination, where a separate extraction action prepares the checkout. Archive bytes never enter the Gateway, workflow arguments or JSON results. Visible cleanup actions remove the three scratch archives.

A linked worktree becomes an independent checkout. Its committed history, branch, origin, staged index, staged new objects, unstaged edits, untracked files and private environment files are retained. The source index and common repository remain unchanged. A common checkout with other linked worktrees cannot be removed by this transfer. Git commands disable checkout hooks and filesystem monitors.

Extraction rejects paths and links that escape the checkout. Local staging directories have private permissions and are removed when the action ends. Native steps retain only public archive metadata. The destination removes public permissions from its environment file.

## Placement and publication

Admission captures source and destination Process installation batches without account or port discovery. The destination verifies stored port intent before the source pauses. Native steps stop source Processes before the archive, install destination Processes after publication, and verify running Vite listeners before retiring source runtime. Desired stopped Processes stay stopped. Before cutover, recovery restores source runtime before discarding the destination. Installation material is readable only on its admitted Node while the stored Instance and Process paths match that placement. Account home and group resolve locally through the canonical Process installer.

Swamp reuses the canonical native Route forward and restore children with the complete captured publication inventory. After physical forward publication, a typed result records the authoritative destination before source retirement. At root terminal, the Gateway changes the Instance placement, relocates Process working directories and explicit environment-file paths, and changes captured Route placements in one database transaction. A later retirement failure preserves that destination receipt. Paths outside the source checkout refuse admission; null defaults stay null. The Operation retains its truthful source Node throughout the workflow.

Workload access, PHP, environment URLs, Caddy and DNS use the prospective placements captured during admission. Route environment material uses named vault execution copies. No step reads intent or reports progress through a Gateway callback. Certificate signing remains the scoped Gateway primitive.

After destination publication, Swamp removes unused source certificate and LAN artifacts, then deletes only the source checkout whose native filesystem identity it captured. A replacement path is refused. Linked worktree removal preserves the common repository and sibling worktrees. The Gateway then retires only the replaced primary Route and records completion. Other web-root Route IDs remain unchanged.

## Failure and cancellation

Before cutover, the native DAG restores source publication, drops reserved destination Route intent and discards only a proven destination checkout. Failed cleanup remains visible as failure. It never authorizes deletion of an unproven replacement.

After successful physical forward publication, the destination is authoritative. A later retirement failure or cancellation preserves its placement result and source residue for repair. Failure before successful forward publication restores source publication and runtime before discarding the proven destination. Failed recovery retains residue and refuses to guess that cleanup succeeded. Cancellation uses native cancellation; an acknowledgment is not terminal evidence.

No transfer action calls orbit-agent. The agent remains frozen.
