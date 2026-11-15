---
title: "ADR 0260: Share CI places between runners and proof copies"
sidebarTitle: "0260 Shared CI places"
description: "In progress. Sabre, Shark and Beast offer a fixed number of CI places. A place runs one runner job or one proof copy. A proof spreads its shards over all free places, so another place makes CI faster. Orbit shows what each place is doing."
---

# ADR 0260: Share CI places between runners and proof copies

Sabre, Shark and Beast form one CI pool. Each offers a fixed number of places: Sabre 5, Shark 5, Beast 2. A place runs one runner job or one proof copy. A proof spreads its shards over every free place on every CI Node, so adding a place makes the proof faster. Each Node keeps its own seed of the proof container. Orbit's web app shows what every place is doing.

## Status

In progress.

Decided by the owner on 2026-11-15.

Principle: [Lean](/mission#principles): one pool and one count per Node, instead of host-specific labels, separate proof capacity and a fixed copy limit. It also serves [Agents operate, humans steer](/mission#principles): the runner view lets a human see what CI is doing.

Open:

- How Orbit keeps the limit when a runner takes a job by itself: count the busy runners and start a proof copy only in a free place, or stop runner services while a proof holds their places.
- The CPU and memory of a proof copy while it runs cases. The count per Node is checked against it before rollout.
- What Sabre and Shark need to host a proof copy. A copy is an Instance created from a seed, and today they carry only the `ci-runner` role.

## Context

Between 13 and 15 November 2026:

- The after-merge shards asked for `[self-hosted, orbit-shard, sabre]`. All 13 organization runners carry `orbit-shard`, but only Sabre's 5 carry `sabre`, so Sabre ran all 765 shard jobs while Shark's runners sat idle and Beast's were stopped.
- A proof takes at most `proof.copies` (2) copies, all on the Node of the proof Project's default (Beast), and never the last two copy places of that Node. A proof run took up to 44 minutes.
- Runners and proof copies are Incus containers since [ADR 0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers); the counts and sizes still came from the VM era. With all 5 runners busy, Sabre used 5 GiB of 30 GiB and its CPU pressure was 39 to 51 percent. A runner's 6 GiB memory peak was mostly page cache. CPU, not memory, limits a CI Node.

## Decision

The CI Nodes share one pool of places. Runner jobs and proof copies take places from it, and Orbit shows how they are used.

### One pool of places

Each CI Node has one place count. A place runs one runner job or one proof copy:

| Node | Places | Runs |
| --- | --- | --- |
| Sabre | 5 | runner jobs and proof copies; CI only |
| Shark | 5 | runner jobs and proof copies; CI only |
| Beast | 2 | proof copies only; development comes first |

A count changes when a full Node's CPU pressure shows room or contention, not from memory limits.

The count is the Node's `ci_places`. It replaces the setting of that name from the task VM era and the proof's `proof.copies` limit. Task copies do not use CI places; Beast's 2 places come on top of the copy places that its task groups use, so the reservation of the last two copy places for task groups no longer concerns the proof.

Runners carry one shared label and no host label. A workflow never names a host. The private repositories' jobs run in this pool.

### A proof uses every free place

`proof:run` places its shards on free places across all CI Nodes, up to one shard per free place, instead of at most `proof.copies` copies on the default's Node. The balance of whole batches by measured time stays. With 12 free places, 44 minutes of batches run in about 4 minutes, plus start-up: today copies start one at a time, 20 seconds apart. A batch stays on one copy, so the longest batch sets the floor. The workflow job that calls Orbit runs on the host-level proof runner, which holds no place.

Each CI Node keeps its own seed of the proof Project's default. The refresh after each release, which already refreshes the nested-fleet default ([Development containers](/reference/development-containers#refresh-after-a-release)), refreshes the seed on every CI Node. A copy starts from its own Node's seed.

Proof copies are CI work, not development.

### Orbit shows what each place is doing

The web app has a runner view:

- per Node: its places, and whether each is free, running a runner job or holding a proof copy; its CPU pressure, memory and disk;
- per runner: running or stopped, idle or busy, its current job and repository, its runner version;
- per runner over time: jobs, queue wait, run time and failures.

Orbit reads the runner containers on each CI Node for their state, version and busy flag. It reads the Actions job lists of the repositories where its GitHub App is installed for job, repository and timing; each job names its runner. The App already has Actions read, so this adds no GitHub permission. The organization's own runner list needs another organization permission, and the view does without it.

### Build order

1. Remove the `sabre` label from workflows and runners; one shared label on every runner.
2. Measure a proof copy under load, then record each Node's `ci_places`.
3. Let Sabre and Shark host proof copies.
4. Keep a proof seed on each CI Node, refreshed after each release.
5. Change `proof:run` to place shards on free places across CI Nodes, with the agreed way to keep the limit. Its chart changes first, in that pull request.
6. Build the runner view in the Gateway and the web app.
7. Absorb this ADR into [Node provisioning](/reference/node-provisioning#ci-runner-role), [proof:run](/cli/proof/run) and [Implementation loop](/reference/implementation-loop#ci).

## Rejected alternatives

- Separate fixed capacity for runners and for proof: idle places in one stay unused while the other queues.
- Double the places because containers are lighter than VMs: memory allows it, but Sabre's CPU is already contended with 5 busy jobs.
- One seed on Beast that every Node copies from at start: each copy would cross the network and wait for it.
- Use the GitHub-hosted runners for the proof: the proof needs an enrolled Node that reaches the Gateway.
- Read the organization runner list from GitHub: it needs a new organization permission, and the CI Nodes already report the same state.

## Consequences

- Another place on any CI Node makes a proof faster. A busy pool makes it slower, and the view shows why.
- Private repositories and Orbit's proof compete for the same places.
- Each CI Node stores a seed of the proof container and refreshes it after each release.
- Beast's runner containers take no jobs; Beast gives its 2 places to proof copies.
- Shark becomes a CI-only Node, like Sabre: it no longer takes task work.

## Affects

- Components: apps/gateway, apps/web, apps/docs
- ADRs: [0213](/decisions/0213-run-ci-runners-through-a-ci-runner-role), [0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers), [0259](/decisions/0259-prove-changed-operations-on-the-pull-request)
- Detail: [Node provisioning: ci-runner role](/reference/node-provisioning#ci-runner-role); [proof:run](/cli/proof/run); [Implementation loop: CI](/reference/implementation-loop#ci)
- Verify: a proof with more batches than one Node's places runs copies on several CI Nodes and merges one result; the place count holds while runner jobs and proof copies start together; the runner view lists each Node's places and each runner's current job
