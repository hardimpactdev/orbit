---
title: Same-root Route mutation steps
description: Route rename and private placement changes composed inside an owning parent.
---

A Cluster parent captures Route changes through `NativeRouteMutationPlan::capture($changes)` inside its admission transaction. The input is the reconciler's list of `route`, `domain` and optional `placement` entries. The helper validates the complete batch and reserves domain replacements. It starts no Operation and performs no host reads or effects. Tracking Routes use the Analytics owner's plan instead of this app Route lower.

The parent stores the returned value as `input.route_mutations` and admits its `node_ids` before starting one native DAG. Its public view calls `publicInput($operation, $participantNodeId)`. Each fresh view contains `route_mutations` entries keyed by original `route_id`, with workload, certificate and cleanup input for that participant. Whole-Node Caddy, PHP and DNS input stays at the top level and is rendered from stored state inside the canonical publication lock. Parent kind, run and root Node remain unchanged.

`checkpoint($captured, $stage)` changes only stored Route state. The stages are `workloads-prepared`, `router-prepared`, `commit`, `dns-published`, `withdraw`, `complete`, `rollback` and `rollback-complete`. The parent passes its persisted captured input, never callback-supplied proposals. Call `workloads-prepared` after staging leaves exist and before publishing candidate workload sites. Call `router-prepared` after staging Router leaves exist and before candidate Router publication. Verify the candidate before `commit`. Publish fresh DNS, record `dns-published`, then wait the native 31-second DNS grace once for the batch before `withdraw`. Issue the live leaves and publish the desired configuration before removing fresh-filtered obsolete scopes and calling `complete`.

Before commit, recovery calls `rollback`, republishes the original Caddy and DNS state, waits the DNS grace, removes only fresh-filtered candidate scopes, then calls `rollback-complete`. After commit the parent recovers forward. Rollback refuses a committed change. Cleanup excludes every scope still loaded by any current site on that Node.

`environmentMaterial($operation, $participantNodeId)` supplies existing private Instance environment writes through the parent's declared private binding. The parent owns that binding and its custody validation. No new secret source is introduced.
