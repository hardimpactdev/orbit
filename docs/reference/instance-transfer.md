---
title: "Instance transfer"
description: "Move an active host-checkout development Instance to another app-dev Node while preserving its Instance ID and Project."
covers:
  - apps/gateway/app/Domain/Instances/NativeInstanceTransferProjection.php
  - apps/gateway/app/Actions/Instances/StartInstanceTransferAction.php
  - apps/cli/app/Commands/Instances/TransferInstanceCommand.php
  - apps/cli/app/Commands/Concerns/RendersCheckoutWarnings.php
  - swamp/{workflows/workflow-operation-instance-transfer.yaml,extensions/behaviors/operations/instance-source-command/**}
---

# Instance transfer

`instance:transfer` moves an active host-checkout development Instance to another `app-dev` Node while keeping its Instance ID and Project. Container Instances, production Instances, and SQLite transfers are currently refused with `operation.kind_unavailable`.

```bash
orbit instance:transfer <instance> <node> [--name=NAME] [--sqlite-source-path=PATH] [--force] [--idempotency-key=KEY] [--json]
```

The CLI calls `POST /api/v1/instances/{instance}/transfer` and follows the returned Operation (`Idempotency-Key` replays admission after a lost response). `--force` is a CLI-only confirmation flag for the transfer downtime and old-checkout deletion (required with `--json` or noninteractive calls; omitting it returns `instance.confirmation_required`).

## Prerequisites and refusals

Both Nodes must be distinct active Linux Nodes with active `app-dev` roles in active Clusters. The destination Node must also have a stored absolute `apps.path`.

The source Instance must be active and have a recorded source profile and stored environment (run [`env:import`](/cli/env/import) first if missing). It must own one authoritative Route with no active domain change and pass the [Git worktree check](/reference/instance-removal). Unreadable unrelated checkouts emit warnings in human and JSON output.

| Error code | HTTP | Cause |
| --- | --- | --- |
| `operation.kind_unavailable` | 409 / 503 | Target is a container Instance, requests a SQLite copy, or destination lacks `apps.path`. |
| `instance.production_refused` | 409 | Production Instances cannot be transferred. |
| `instance.lifecycle_conflict` | 409 | Instance is not active, lacks an authoritative Route, or has a domain change in progress. |
| `instance.removal_conflict` | 409 | Instance is being removed. |
| `instance.same_node` | 409 | Destination Node matches the source Node. |
| `instance.node_inactive`, `instance.node_not_app_dev`, `instance.standalone_unsupported` | 409 | Destination Node is inactive, lacks `app-dev`, or is not in an active Cluster. |
| `instance.identity_conflict`, `instance.destination_exists` | 409 | Destination Instance name or path is already taken. |
| `instance.source_profile_missing` | 409 | Source Instance lacks a recorded source profile. |
| `env.configuration_missing` | 409 | Instance has no stored environment configuration on the Gateway. |
| `route.domain_conflict` | 409 | Proposed destination domain collides with another Route. |
| `instance.transfer_source_router_unknown` | 409 | Source Cluster Router cannot be resolved. |
| `instance.transfer_conflict`, `instance.transfer_retry_conflict`, `instance.transfer_recovery_pending` | 409 | Conflicting or incomplete transfer already exists. |
| `operation.execution_forbidden` | 422 | Project setup steps changed after admission. |

## Source and archive

Orbit archives the source checkout (excluding `.env`), transfers it to the destination, and extracts it safely with checkout hooks and filesystem monitors disabled (rejecting paths or symlinks escaping the checkout). A linked worktree becomes an independent checkout on the destination while keeping its branch, history, staged index, unstaged edits, and untracked files.

Orbit then renders the destination `.env` from stored configuration (mode `0640`, group `orbit-env`) and runs the Project's setup steps. Any failing setup step restores the source.

## Placement and publication

Orbit stops source Processes and removes Schedule triggers before archiving. On the destination, it starts desired-running Processes, checks Vite listeners, and publishes the primary Route and active web-root Routes (public web-root Routes cannot move across Clusters). See the [`instance:transfer` chart](/cli/instance/transfer).

After a TLS probe confirms the destination serves the Route, Orbit updates the Instance placement, Process paths, Schedules, and Routes atomically. It then retires unused source certificates and LAN rules, deletes the source checkout (or prunes only the worktree entry for a linked worktree), and retires any replaced primary Route. External database bindings stay attached with their network preference reset to WireGuard.

## Failure and cancellation

If a failure happens before destination Route publication, Orbit restores the source runtime, Route, and Schedules (`instance.transfer_failed`). It also drops reserved destination Route intent and removes only a proven destination checkout. If a failure or cancellation happens after publication, the destination stays authoritative and source residue remains for manual repair.
