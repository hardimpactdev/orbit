---
title: "ADR 0260: Share CI places between runners and proof copies"
sidebarTitle: "0260 Shared CI places"
description: "In progress. Each CI Node offers a fixed number of CI places. A place runs one runner job or one proof copy. A proof spreads its shards over all free places, so another place makes CI faster. Orbit shows what each place is doing."
---

# ADR 0260: Share CI places between runners and proof copies

The CI Nodes form one pool. Each offers a fixed number of CI places, its `ci_places`. A place runs one runner job or one proof copy. A proof spreads its shards over every free place on every CI Node, so another place makes the proof faster. Each CI Node keeps its own seed of the proof container. Orbit's web app shows what every place is doing.

## Status

In progress.

Decided by the owner on 2026-11-15.

Principle: [Lean](/mission#principles): one pool and one count per Node, instead of host-specific labels, separate proof capacity and a fixed copy limit. It also serves [Agents operate, humans steer](/mission#principles): the runner view lets a human see what CI is doing.

Open:

- How Orbit keeps the count when a runner takes a job by itself: count the busy runners and start a proof copy only in a free place, or stop runner services while a proof holds their places.

## Context

- A workflow can pin its jobs to a host-specific runner label. The after-merge shards do this, so one host's runners take all of that work while other CI Nodes stay idle.
- A proof takes at most `proof.copies` copies, all on the Node of the proof Project's default, and never the last two copy places of that Node. Its time does not drop when CI Nodes are added.
- Runners and proof copies are Incus containers since [ADR 0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers). A container takes only the memory it uses, and most of a runner's memory peak is page cache. On a full CI Node, CPU is contended long before memory runs out.

## Decision

The CI Nodes share one pool of places. Runner jobs and proof copies take places from it, and Orbit shows how they are used.

### One pool of places

Each CI Node has one count, its `ci_places`. A place runs one runner job or one proof copy. A Node can limit its places to proof copies, so that a Node with other work keeps CI to a small share. An operator sets each count from the CPU pressure of the full Node, not from container memory limits.

The count replaces the `ci_places` setting from the task VM era and the proof's `proof.copies` limit. Task copies do not use CI places, so the reservation of the last two copy places for task groups no longer concerns the proof.

Runners carry one shared label and no host label. A workflow never names a host. Private repositories' jobs run in this pool.

### A proof uses every free place

`proof:run` places its shards on free places across all CI Nodes, up to one shard per free place, instead of at most `proof.copies` copies on the default's Node. The balance of whole batches by measured time stays. A batch stays on one copy, so the longest batch sets the floor. Copies also start one at a time today, so their start-up grows with the number of shards.

The workflow job that calls Orbit runs on the host-level proof runner, which holds no place.

Each CI Node keeps its own seed of the proof Project's default. The refresh after each release, which already refreshes the nested-fleet default ([Development containers](/reference/development-containers#refresh-after-a-release)), refreshes the seed on every CI Node. A copy starts from its own Node's seed.

A CI Node takes only CI work. Its `ci-runner` role prepares what a nested proof copy needs: the Instance Incus projects, the inner volume pool and the nested-fleet image ([Proof copies on a CI Node](/reference/node-provisioning#proof-copies-on-a-ci-node)). It never gets the `app-dev` role.

Incus moves the seed from the default's Node to each CI Node over WireGuard, with `incus copy --refresh --mode=push` for the container and `incus storage volume copy --refresh --mode=push` for its inner volume ([The seed on each CI Node](/reference/development-containers#the-seed-on-each-ci-node)). `--refresh` sends only the snapshots that changed. In push mode only the target listens. So each CI Node's Incus API listens on its WireGuard address, port 8444, and nowhere else. It trusts one restricted client certificate: root's Incus client certificate on the default's Node, limited to the project `orbit-instances-nested`. Two firewall rules in front of the WireGuard member trust admit the port from the default's Node and deny it from every other source. The default's Node gets no listener and no change to its Incus daemon: only a client remote for each CI Node. This is the one trust between Nodes that this decision adds.

### Orbit shows what each place is doing

The web app has a runner view:

- per CI Node: its places, and whether each is free, running a runner job or holding a proof copy; its CPU pressure, memory and disk;
- per runner: running or stopped, idle or busy, its current job and repository, its runner version;
- per runner over time: jobs, queue wait, run time and failures.

Orbit reads the runner containers on each CI Node for their state, version and busy flag. It reads the Actions job lists of the repositories where its GitHub App is installed for job, repository and timing; each job names its runner. The App already has Actions access, so this adds no GitHub permission. The organization's own runner list needs another organization permission, and the view does without it.

### Build order

1. Remove host labels from workflows and runners; one shared label on every runner.
2. Measure a proof copy under load, then set each CI Node's `ci_places`.
3. Let a CI Node host proof copies.
4. Keep a proof seed on each CI Node, refreshed after each release.
5. Change `proof:run` to place shards on free places across CI Nodes, with the agreed way to keep the count. Its chart changes first, in that pull request.
6. Build the runner view in the Gateway and the web app.
7. Absorb this ADR into [Node provisioning](/reference/node-provisioning#ci-runner-role), [proof:run](/cli/proof/run) and [Implementation loop](/reference/implementation-loop#ci).

## Rejected alternatives

- Separate fixed capacity for runners and for proof: idle places in one stay unused while the other queues.
- Size places from container memory limits: containers use far less than their limits, and CPU runs out first.
- One seed on one Node that every other Node copies from at start: each copy would cross the network and wait for it.
- A ZFS stream of the seed through the Swamp worker, with bookmarks of Orbit's own: the worker is on another host and short of CPU, and Incus already sends the change between snapshots.
- Use GitHub-hosted runners for the proof: the proof needs an enrolled Node that reaches the Gateway.
- Read the organization runner list from GitHub: it needs a new organization permission, and the CI Nodes already report the same state.

## Consequences

- Another place on any CI Node makes a proof faster. A busy pool makes it slower, and the view shows why.
- Private repositories and the proof compete for the same places.
- Each CI Node stores a seed of the proof container and refreshes it after each release.

## Affects

- Components: apps/gateway, apps/web, apps/docs
- ADRs: [0213](/decisions/0213-run-ci-runners-through-a-ci-runner-role), [0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers), [0262](/decisions/0262-prove-changed-operations-on-the-pull-request)
- Detail: [Node provisioning: ci-runner role](/reference/node-provisioning#ci-runner-role); [Proof copies on a CI Node](/reference/node-provisioning#proof-copies-on-a-ci-node); [The seed on each CI Node](/reference/development-containers#the-seed-on-each-ci-node); [proof:run](/cli/proof/run); [Implementation loop: CI](/reference/implementation-loop#ci)
- Verify: a proof with more batches than one Node's places runs copies on several CI Nodes and merges one result; the count holds while runner jobs and proof copies start together; the runner view lists each Node's places and each runner's current job
