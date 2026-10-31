---
title: Native Route creation and convergence
description: Route creation and recovery through one placed Swamp workflow.
---

Route creation records one Operation and starts one native Swamp workflow. The Gateway validates the Route, its recorded targets and its participant Nodes. Swamp prepares the workloads, requests and publishes certificates, converges PHP and LAN access, and publishes Caddy and DNS through placed steps in that run.

The admitted input freezes the participating Nodes, workloads and certificate scopes. Each participant reads its own fresh public publication plan through the shared direct Operation endpoint. The parent keeps its recorded coordinator Node. A workload that moves from its admitted Node or leaves the Route target set is refused before a new public workload plan is returned.

The native root records only the domain transitions that affect subsequent public rendering: sites published, public activation and initial rollback. These transitions update stored Route intent. They do not record execution receipts or validate Swamp's steps. The terminal callback records the Operation result and Route state in one transaction.

An initial failure withdraws the candidate sites before Swamp republishes the remaining configuration. The captured rollback inventory removes only the new Route's LAN allowance and Route-specific certificate scopes. An unchanged retry of an active Route records the existing Route without republishing it. Failed and cancelled Operations retain the Route and target associations.

Instance environment material uses the existing declared private path. It is rendered only for the bound participant and passed directly to the native effect callback. Certificate signing runs on the canonical control worker; the Gateway does not execute a signer or store a CSR checkpoint. The frozen agent performs no Route actions.

See [Routes](/reference/routes) and [Operations](/reference/operations) for the public command contracts.

## Converge an existing Route

`POST /api/v1/routes/{route}/converge` accepts an empty body and returns a `route.converge` Operation. Its native workflow is `operation-route-converge`. It republishes the existing admitted intent; the caller cannot supply another domain, target or placement. Pending, failed and active app or custom proxy Routes use the same canonical workload and publication steps as creation. A failed Route keeps its failure until the native result records recovery.

The Gateway refuses convergence of a retiring Route or a Route with a replacement, placement transition or target-set intent in progress. Those intents finish through their recorded withdrawal or update. Analytics tracking hosts remain owned by their Analytics parent. Authorization uses the Route's actual serving Nodes, and participant callbacks stay bound to the one native root.
