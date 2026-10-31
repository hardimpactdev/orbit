---
title: Route mutation steps
description: Capture complete Route changes for a Node parent without worker callbacks.
---

Node enrollment discovers Route changes from the actual stored Node. `NativeRouteNodeMutationInput::capture(Node $actual, NativeRouteNodeRender $candidate, ?int $clusterId)` reserves replacement Route intent through `NativeRouteMutationPlan` before it enters the prospective render context. It keeps the Node's stored address, account and activation unchanged. The capture returns the immutable `route_mutations` plan, participant IDs, admitted certificate leaves and `execution_secrets` descriptors.

Prior publications use stored identity. Forward publications use the admitted address and TLD through `RouteRenderContext`, with the complete proposed Route site catalog. Both sides include Caddy, PHP, DNS, Route LAN, certificate and URL contributions. Secret values go only through the shared `SwampCommandStep::executionCopy` helper. Public workflow inputs contain vault and key names.

`routeMutationSteps(name, intentExpression, mode, guard?)` supplies placed native steps in the owning parent's run. Forward runs after Node role and runtime readiness. Restore runs from the parent's compensation branch using the captured prior native identity. The stored Node identity stays unchanged during execution; no database callback runs before compensation. Official SSH actions from 209 apply public files; validation precedes reload. Script actions handle only the Route-specific gaps. No step reads the Gateway or projects intermediate Route state.

The typed final output names each Route and its completed publication, certificate and URL contributions. A restored hub is a separate contribution. The Node parent records Route state through the canonical mutation projection only when its terminal outcome reports the corresponding forward or restore work complete.

The final singleton native step is expanded to `<name>-0`. Its `outputs` object is `{ready:true, restored:boolean, routes:[{route_id,domain,node_ids,certificate_scopes,ready:true}], contributions:{<child-step-prefix>:<completed-item-count>}}`. Native completion checks every required expanded child before it emits this object. A failed or skipped contribution cannot produce a ready result.

The parent passes the immutable stored `route_mutations` capture and that output object to `NativeRouteMutationPlan::applyTerminal(array $capture, array $outcome): void` in its terminal database transaction. The method records the canonical mutation stages only. Forward applies `workloads-prepared`, `router-prepared`, `commit`, `dns-published`, `withdraw` and `complete`; restoration applies `rollback` and `rollback-complete`. It takes no Operation and makes no host calls. The parent retains responsibility for its Node identity projection and its separate role, runtime and hub outcomes. An incomplete Route restoration must not claim whole-parent restoration.

### Private DNS grace

Private DNS answers have a 30-second TTL. A Route DNS phase keeps the previous target serving for 31 seconds after the new answer is published, then retires its host, certificate and LAN allowance. Restoration first retains both physical targets, publishes the prior DNS answer, waits, then withdraws the candidate. Public authoritative DNS is unaffected.

The orchestrator and Nick approved one exception to the no-wait rule on 2026-10-31: `swamp/workflows/workflow-route-dns-grace.yaml`, job `grace`, step `wait-private-dns-ttl`, placed on `orbit-node-209`, executes exactly `/usr/bin/sleep 31` through core `command/shell`. Pinned Swamp e459 core and the official workflows extension have no elapsed-delay primitive. The canonical gate matches the exact file, job, step, placement and task; no general wait allowance or baseline change applies.

DNS publication reads the current public records and catalog files, applies the captured delta, then uses official SSH file actions, validation and service activation. Like Caddy section composition, forward and restore preserve unrelated observed revisions. No step reads the Gateway.

All Route work runs on Node 209 workers. Operational target actions use the official `@swamp/ssh` model, the admitted existing identity file and pinned target host keys. Public connection descriptors contain `control_node_id:209`, `connection:{nodeHost,nodeUser,nodePort,nodeIdentityFile,become:true}` and `host_keys`. A target Node needs no Swamp binary, worker or enrollment token. Script assets are copied with official SSH copy, root Python actions use `-B`, and cleanup runs as the SSH user. The Node parent keeps its actual resource Node ID; the execution placement is 209.

Route LAN contributions contain one Node-wide `rule:{node_id,rules,migrate}` plan, rather than one allowance per Route. `rules` holds unique public tuples with ensure states and native model names. The capture includes every unrelated published Route when deciding whether a tuple is still needed. Final outputs include `migrated_node_ids`; the canonical terminal application records only IDs admitted for migration. The approved cleanup is temporary and follows official tuple convergence.
