---
title: "Read the rollout base inventory"
description: "Collect fixed, read-only evidence before and after a bounded Node base rollout."
covers:
  - apps/gateway/app/{Actions,Domain}/Nodes/*BaseInventory*
  - apps/gateway/app/Http/Controllers/Api/NodeBaseInventoryController.php
  - swamp/workflows/workflow-operation-node-base-inventory.yaml
  - swamp/extensions/behaviors/operations/node-base-inventory/**
---

# Read the rollout base inventory

Send a bodyless `POST /api/v1/nodes/{node}/base-inventory` to collect read-only evidence around a bounded Node base rollout. Any query string or request body returns `422 validation.failed`. The endpoint requires an active WireGuard caller with access to the target Node. It accepts only the fixed fleet allowlist (Nodes `4`, `17`, and `209`; others return `422 node.base_inventory_unsupported`). It returns HTTP `202` with an [Operation](/reference/operations), or `503 node.base_inventory_unavailable` if SSH host keys, `agent_secret_hash`, or the WebSocket port are missing. Use a new `Idempotency-Key` per capture.

## How it works

<div className="flow-at" data-chart="node/base-inventory"></div>

The Gateway freezes expected base file digests and the pinned SSH target. It runs a read-only collector over pinned SSH and compares the installed agent secret without exposing secret bytes.

The collector records platform, boot uptime, agent and base policy files, packages, timers, managed CLI paths, executor presence, and role-scoped service state. Router captures add WireGuard, firewall, and publication digests. Swamp captures add serve readiness, worker health, and renewal activity. Services captures add protected workloads, executor socket, WebSocket unit health, and active database or analytics services.

Take one capture before admission and one after settlement. Then compare public file digests, kernel policy, service starts, and network or publication digests.

## Read the Doctor evidence

The Operation result includes targeted Doctor and agent liveness evidence with original observation and confirmation timestamps. On host facts, `confirmed_at` uses `root_confirmed_at` and `observed_at` uses the `/` mount sample.

Facts count as fresh (`doctor_fresh_for_capture`) when all hold:

- Observed within 11 minutes (660 seconds) of host capture.
- `agent_state` is `online`.
- `agent_seen_at` and `checked_at` are within `AgentStateView::FreshSeconds` of capture.
- `doctor.unverifiable` is `0`.

Treat missing or unreadable required items as unresolved evidence; the collector performs no host mutations.
