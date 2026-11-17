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

Send a bodyless POST to `/api/v1/nodes/{node}/base-inventory`. The Gateway requires an active WireGuard caller with target access and accepts only Nodes 4, 17 and 209. It returns an Operation. Read its result through the Operations API. Use a new idempotency key for each capture.

The Gateway freezes the released base file digests and the existing pinned target descriptor. A Swamp worker runs a fixed collector with noninteractive privilege over the recorded SSH host key. The worker gets the pinned route and verifier from the same operation-bound execution copy. It refuses a mismatch before it reaches the Node. Only the target compares the installed secret to it. The result includes only the comparison outcome and the secret file metadata.

## How it works

<div className="flow-at" data-chart="node/base-inventory"></div>

The collector reads the platform, boot and uptime, agent and base policy files, packages and timers, managed CLI paths and executor presence. It reads service state, PID, monotonic start and restart count for the target's fixed role scope. The router snapshot adds normalized WireGuard and firewall and publication digests. The Swamp snapshot includes serve readiness, permanent worker health and renewal activity. The Services snapshot includes the four protected PowerSync workloads, the executor footprint and socket, the WebSocket unit and loopback health, and active database and analytics backing services.

Take a capture immediately before admission and another after settlement. Match the observed public file digests and effective kernel policy against the released intent. Compare protected service starts and normalized network and publication digests between the two captures. Use the existing Caddy and PHP-FPM serving probes for the router.

The receipt includes the Gateway’s targeted Doctor and agent liveness with timestamps and freshness relative to the host capture. Doctor uses retained host, unit and firewall facts. Their age is reported separately from the agent heartbeat. Treat missing or unreadable required items as unresolved evidence. The collector performs no rollout, service, package, firewall or file changes.
