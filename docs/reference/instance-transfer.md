---
title: "Instance transfer"
description: "Move a development Instance through one native Swamp workflow."
---

# Instance transfer

A transfer moves one active development Instance to another `app-dev` Node. It keeps the Instance ID and Project. The Gateway records immutable placement intent and starts one native Swamp workflow. Swamp performs every filesystem and publication action on the admitted Node workers. The Gateway records bounded placement changes and the final result.

```bash
orbit instance:transfer <instance> <node> [--name=NAME] [--sqlite-source-path=PATH] [--force] [--json]
```

The CLI calls `POST /api/v1/instances/{instance}/transfer` and follows the returned Operation. An `Idempotency-Key` reuses admission after a lost response.

Both Nodes must be active Linux Nodes with an active `app-dev` role and an active Cluster. The source must be an active development Instance with one authoritative Route and no removal in progress. The destination must be a different Node that the Project permits. A stored absolute destination apps path is required. Filesystem ownership, the real source Git identity and the unoccupied destination are checked by the native workers.

The current native admission refuses VM placements, SQLite copies and Instances with Schedule definitions. It returns `operation.kind_unavailable` before reserving or moving anything. It also refuses an unknown source profile. Existing external database bindings stay with the Instance.

Each additional Route with a web root must be active and have no domain or placement change. A public web-root Route cannot move across Clusters. The Route family captures the complete primary and web-root inventory and reserves any replacement primary during admission.

## Command workflow inputs

The family input projection returns separate `intent` and `execution_secrets` maps. Intent contains the recorded source and destination placement, Route inventory and public Process descriptors for both placements. It excludes private bindings, material values and secret resolver instructions. Execution secrets contain only vault and key names. The shared input builder supplies the authoritative Operation ID, kind and root Node ID.

The internal workflows capture files, materialize checkouts and retire source checkouts. Every command step declares its Node and uses public outputs between actions. A standalone checkout retains its Git directory, including local branches, tags and configuration. A linked worktree uses separate bundle, index and staged-object actions to become an independent checkout. Destination publication retains its directory identity, so a lost command reply can be resumed without replacing an unrelated checkout.

These command components do not register a second public transfer kind or supply an archive relay. The owning parent controls source pause, private archive delivery, Route publication and retirement order. The [Swamp shape guide](https://github.com/hardimpactdev/orbit/blob/main/swamp/docs/swamp-shape.md) owns the shared execution-copy and terminal-result contracts.

## Source and archive

The destination worker checks readiness before the source is captured. The source worker archives the checkout into a private native file resource. The destination streams that resource and verifies its byte count and SHA-256 before publishing the checkout. Archive bytes never enter the Gateway, workflow arguments or JSON results. The archive limit is 1 TiB.

A linked worktree becomes an independent checkout. Its committed history, branch, origin, staged index, staged new objects, unstaged edits, untracked files and private environment files are retained. The source index and common repository remain unchanged. A common checkout with other linked worktrees cannot be removed by this transfer. Git commands disable checkout hooks and filesystem monitors.

Extraction rejects paths and links that escape the checkout. Local staging directories have private permissions and are removed when the action ends. Native archive resources live for the workflow. The destination removes public permissions from its environment file.

## Placement and publication

Admission captures source and destination Process installation batches without account or port discovery. The destination verifies stored port intent before the source pauses. Native steps stop source Processes before the archive, install destination Processes after publication, and verify running Vite listeners before retiring source runtime. Desired stopped Processes stay stopped. Before cutover, recovery restores source runtime before discarding the destination. Installation material is readable only on its admitted Node while the stored Instance and Process paths match that placement. Account home and group resolve locally through the canonical Process installer.

Preparation for the command parent renders both captured Process placements before cutover. The Instance and Process records remain on the source. Public captures and protected material return separately; serializing the prepared result redacts the material. The parent supplies execution copies through the shared helper. A rendering refusal rolls back new destination port reservations. Preparation performs no account lookup, host action or vault write.

Swamp prepares destination certificates before cutover. The root then posts one bounded cutover callback. In one database transaction, the Gateway changes the Instance placement, relocates Process working directories and explicit environment-file paths, and changes the captured Route placements. Paths outside the source checkout refuse admission; null defaults stay null. The Operation retains its truthful source Node throughout the workflow.

Workload access, PHP, environment URLs, Caddy and DNS run after cutover. They read the current stored placement through canonical Route adapters. URL material stays inside the existing private callback and preserves the archived environment. Each field belongs only to its admitted Node.

After destination publication, Swamp removes unused source certificate and LAN artifacts, then deletes only the source checkout whose native filesystem identity it captured. A replacement path is refused. Linked worktree removal preserves the common repository and sibling worktrees. The Gateway then retires only the replaced primary Route and records completion. Other web-root Route IDs remain unchanged.

## Failure and cancellation

Before cutover, the native DAG restores source publication, drops reserved destination Route intent and discards only a proven destination checkout. Failed cleanup remains visible as failure. It never authorizes deletion of an unproven replacement.

After cutover, the destination is authoritative. Cancellation or failure cannot roll placement back or discard the destination. Publication uses explicit recovery reads to finish the committed placement. A failed forward action retains the destination and source residue for repair.

No transfer action calls orbit-agent. The agent remains frozen.
