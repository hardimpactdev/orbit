---
title: "Native Instance provisioning"
description: "Create and clone execute as native Swamp roots."
---

# Native Instance provisioning

`instance.create` and `instance.clone` each admit one native Swamp workflow.
The Gateway records the checkout, Node account, repository, agent pin and
Project step identities. It does not create child Operations or run commands.
Swamp prepares the source, resolves its branch, classifies its application,
synchronizes the environment and runs setup in placed steps of that root.
Only public source metadata is projected between phases. Committed environment
templates use the existing protected output store; Git access and rendered
environment values stay inside the existing bound private callbacks.

Development uses the configured Node account frozen at admission. Production
clone uses the Project's `orbit-app-ID` account and private home. Caller input
cannot select an account or execution path. Every job targets both the exact
`orbit-node-ID` worker and its string Node label.

Create requires an explicit stored `apps.path`. Swamp resolves the configured account and its primary group locally before it prepares source. Clone inspects its candidate on that candidate’s admitted Node and binds the public commit to target resolution. Both Nodes are immutable participants in the same root. The existing Git private field is readable only by those admitted participants.

The first native cutover supports unrouted Instances without managed databases,
VM source transport, or runtime definition installation. Unsupported combinations
return `operation.kind_unavailable` before reservation or native submission.
They do not fall back to the removed Gateway executor. This is an accepted
management outage until those owners supply reusable native steps.

A failed root keeps the admitted Instance and its source facts for retry.
Success marks the Instance active only after environment synchronization and
setup finish. A native failure records its phase and leaves the Instance
available for an explicit retry or destroy. It never starts a rollback child.

## Register an existing checkout

`instance.register` runs one native root on its admitted development Node. The Gateway reserves the Instance without reading the Node. Swamp resolves the configured account locally, checks the source owner and repository, adopts the checkout in place and closes its environment file permissions. The final result records source metadata and marks the Instance active. It never creates a child Operation.

This cutover requires an explicit existing non-web Project and a single ordinary checkout already at `apps.path/PROJECT/NAME`, using the Node's configured `apps.path`. Project inference, relocation, linked worktrees, setup and Route publication return `operation.kind_unavailable` before reservation. A failed adoption keeps its reserved Instance. A later explicit registration can reuse that reservation; it cannot adopt an unrelated or partially created Instance.

## Record a checked-out branch

`instance.rename` runs one native root on the Instance's development Node. It resolves the configured account locally and reads the checkout with the existing agent's idle check. Swamp checks repository identity and the requested branch before the Gateway records that branch and its override. This command does not change Git HEAD. Domain replacement returns `operation.kind_unavailable` before admission until the native Route replacement parent is available.
