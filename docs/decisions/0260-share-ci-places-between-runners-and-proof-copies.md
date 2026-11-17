---
title: "ADR 0260: Share CI places between runners and proof copies"
sidebarTitle: "0260 Shared CI places"
description: "In progress: build steps 5 and 8 in work. Each CI Node offers a fixed number of CI places. A place runs one runner job or one proof copy. A proof spreads its shards over all free places, so another place makes CI faster. A small pool of warm proof copies saves the copy create. Orbit shows what each place is doing."
---

# ADR 0260: Share CI places between runners and proof copies

The CI Nodes form one pool. Each offers a fixed number of CI places, its `ci_places`. A place runs one runner job or one proof copy. A proof spreads its shards over every free place on every CI Node, so another place makes the proof faster. Each CI Node keeps its own seed of the proof container. Orbit's web app shows what every place is doing.

## Status

In progress.

Build steps 5 and 8 are in work.

Decided by the owner on 2026-11-15. The owner answered the open question on 2026-11-16. The owner added the warm proof copies on 2026-11-17 ([Warm proof copies](#warm-proof-copies)).

Principle: [Lean](/mission#principles): one pool and one count per Node, instead of host-specific labels, separate proof capacity and a fixed copy limit. It also serves [Agents operate, humans steer](/mission#principles): the runner view lets a human see what CI is doing.

Answered:

1. How Orbit keeps the count when a runner takes a job by itself. Answer: while a proof holds places on a CI Node, Orbit stops that Node's idle runner services. Busy runners finish their job and are never stopped. The stopped runners start again when the proof's copies on that Node end, also when the proof fails or is cancelled. See [Runners stop while a proof holds places](#runners-stop-while-a-proof-holds-places).

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

`proof:run` places its shards on free places across all CI Nodes, up to one shard per free place, instead of at most `proof.copies` copies on the default's Node. `proof.copies` is gone. The balance of whole batches by measured time stays.

The free places of a Node are its `ci_places` minus its busy runners minus the proof copies already on it, of any proof. Task copies take no place. A copy takes a place on a Node only when the Node's available memory (Linux `MemAvailable`) also holds one copy's peak, the Gateway setting `proof.copy_memory`. So a Node takes at most as many copies as its available memory holds whole peaks. A Node short of memory offers fewer places, and the proof uses other Nodes or waits. The Gateway reads the busy runners and the available memory of each Node from Prometheus before admission. A Node without a fresh read offers no place. The CPU count stays the main limit; the memory check only keeps a copy off a Node that cannot hold it. A batch stays on one copy, so the longest batch sets the floor. Copies also start one at a time today, so their start-up grows with the number of shards.

The workflow job that calls Orbit runs on the host-level proof runner, which holds no place.

### Runners stop while a proof holds places

A runner takes a job by itself, when GitHub gives it one. So while a proof holds places on a CI Node, Orbit stops the Node's idle runner services, and the one count stays true. Each proof copy's create holds its place on the Node and stops idle runners only down to a limit: the running runners must not exceed the Node's `ci_places` minus the proof holds on it. It records exactly the runners it stops. A runner is busy while a `Runner.Worker` process runs; a busy runner finishes its job and is never stopped. Each copy's destroy drops its hold, and the destroy of the last copy on the Node starts exactly the recorded runners again. A run end of any kind asks for the destroy of its copy, so the runners start again after a failure or a cancel too, once that destroy succeeds. A failed destroy keeps them stopped while the watchdog asks again.

The `ci-runner` role installs the script that holds and releases, and the copy's own Ansible phases call it over SSH. The Node agent has no part in it ([Proof places on a CI Node](/reference/node-provisioning#proof-places-on-a-ci-node)).

A runner can take a job in the short moment between the busy test and the stop. It keeps the job, and the Node runs one more job than its places until the job ends. Orbit accepts that short overlap rather than kill a job.

A copy on a CI Node clones that Node's seed holder. Its runtime still names the default as its clone source, and the database accepts a clone on another Node than its default only when that Node holds the default's current seed. The copy needs no Git mirror of the Project on its Node: it fetches the commit under proof by its SHA from the public repository, as every proof copy does.

Each CI Node keeps its own seed of the proof Project's default. The refresh after each release, which already refreshes the nested-fleet default ([Development containers](/reference/development-containers#refresh-after-a-release)), refreshes the seed on every CI Node. A copy starts from its own Node's seed.

A CI Node takes only CI work. Its `ci-runner` role prepares what a nested proof copy needs: the Instance Incus projects, the inner volume pool and the nested-fleet image ([Proof copies on a CI Node](/reference/node-provisioning#proof-copies-on-a-ci-node)). It never gets the `app-dev` role.

Incus moves the seed from the default's Node to each CI Node over WireGuard, with `incus copy --refresh --mode=push` for the container and `incus storage volume copy --refresh --mode=push` for its inner volume ([The seed on each CI Node](/reference/development-containers#the-seed-on-each-ci-node)). `--refresh` sends only the snapshots that changed. In push mode only the target listens. So each CI Node's Incus API listens on its WireGuard address, port 8444, and nowhere else. It trusts one restricted client certificate: root's Incus client certificate on the default's Node, limited to the project `orbit-instances-nested`. Two firewall rules in front of the WireGuard member trust admit the port from the default's Node and deny it from every other source. The default's Node gets no listener and no change to its Incus daemon: only a client remote for each CI Node. This is the one trust between Nodes that this decision adds.

### Warm proof copies

A proof must end within 5 minutes from the push. The create of its copies is a large part of the fixed time before the first case. So each CI Node can keep a small pool of warm proof copies: copies of the proof Project's default whose create has ended, made from the Node's current seed. A warm copy waits for a proof. It has no commit under proof and runs no case.

- **Size.** The Gateway setting `proof.warm_copies` is the number of warm copies across all CI Nodes. 0 turns the pool off, and 0 is the default: no release makes warm copies until the owner sets a small number. The pool spreads its copies over the Nodes that take proof copies, in turns, as a proof spreads its shards.
- **Cost.** A warm copy holds its place while it waits. It is a proof copy on its Node: it takes one of the Node's `ci_places`, one slot of the task copy cap of that Node and one copy's memory peak (`proof.copy_memory`), as a running copy does. The CPU of a Node is its limit, so the pool stays small. Idle runners stop for a warm copy's place, as for any proof copy ([Runners stop while a proof holds places](#runners-stop-while-a-proof-holds-places)).
- **Take.** At admission a proof takes the warm copies on each Node of its places first, and starts a new copy only for the rest of its shards. A taken copy is the shard's copy from then on, and the shard's run starts as soon as admission records it. The run keeps its first reset of the inner fleet: the reset migrates the nested Gateway on the commit under proof, so the fleet runs the tested code.
- **After use.** A used copy is destroyed at its run's end, as every proof copy is. Orbit never puts a used copy back in the pool. The pool then makes a new warm copy.
- **Refill.** After a warm copy is taken or destroyed, and after each refresh of the seed on a Node, the pool makes warm copies until it holds `proof.warm_copies`. A refill takes a free place only. It never waits for one. It starts no warm copy while a proof waits for its admission, so that proof takes the free places first. A drain and a refill take the same lock, so no refill starts a warm copy after a drain.
- **Stale seed.** A warm copy records the seed it was made from. When the Node's seed is refreshed, each warm copy from an older seed is destroyed and replaced. A proof never takes one.
- **Drain.** [`orbit proof:pool:drain`](/cli/proof/pool-drain) is the one command for a maintenance window that needs every copy of the default gone. It destroys the idle warm copies and stops every refill. Running proofs finish and destroy their copies as usual. [`orbit proof:pool:resume`](/cli/proof/pool-resume) lets the pool refill again. A drain lasts until the resume, across Gateway restarts.

### Orbit shows what each place is doing

The web app has a [runner view](/reference/ci-runner-view):

- per CI Node: its places, and whether each is free, running a runner job or holding a proof copy; its CPU pressure, memory and disk;
- per runner: running or stopped, idle or busy, its current job and repository, its runner version;
- per runner over time: jobs, queue wait, run time and failures.

Orbit reads the runner containers on each CI Node for their state, version and busy flag. It reads the Actions job lists of the repositories where its GitHub App is installed for job, repository and timing; each job names its runner. The App already has Actions access, so this adds no GitHub permission. The organization's own runner list needs another organization permission, and the view does without it.

### Build order

1. Remove host labels from workflows and runners; one shared label on every runner.
2. Measure a proof copy under load, then set each CI Node's `ci_places`.
3. Let a CI Node host proof copies.
4. Keep a proof seed on each CI Node, refreshed after each release.
5. Change `proof:run` to place shards on free places across CI Nodes, stop idle runners while a proof holds their places, and check each Node's available memory. Its chart changes first, in that pull request. In work.
6. Build the runner view in the Gateway and the web app.
7. Absorb this ADR into [Node provisioning](/reference/node-provisioning#ci-runner-role), [proof:run](/cli/proof/run) and [Implementation loop](/reference/implementation-loop#ci).
8. Keep a pool of warm proof copies: the setting `proof.warm_copies`, refill after use and after each seed refresh, replacement of a stale warm copy, `proof:pool:drain` and `proof:pool:resume`, and `proof:run` takes warm copies first. Its charts change first, in that pull request. In work.

## Rejected alternatives

- Separate fixed capacity for runners and for proof: idle places in one stay unused while the other queues.
- Size places from container memory limits: containers use far less than their limits, and CPU runs out first.
- Keep runners running and count only busy runners: an idle runner can take a job at any time, so the count would not hold while a proof runs.
- Stop busy runners too: that kills a job that a pull request waits for.
- Keep a Git mirror of the Project on each CI Node, or carry a bundle of the commit: the copy already fetches the commit by its SHA, so either one adds a second way in for no gain.
- One seed on one Node that every other Node copies from at start: each copy would cross the network and wait for it.
- A ZFS stream of the seed through the Swamp worker, with bookmarks of Orbit's own: the worker is on another host and short of CPU, and Incus already sends the change between snapshots.
- Use GitHub-hosted runners for the proof: the proof needs an enrolled Node that reaches the Gateway.
- Read the organization runner list from GitHub: it needs a new organization permission, and the CI Nodes already report the same state.
- Warm copies outside the place count: the Node would run more copies than its CPU carries, and the one count would not hold.
- Reuse a copy after its proof, with a reset: a run leaves the commit under proof, its checkout and its evidence in the copy. A clean copy for each run is simpler to trust than a cleanup.
- Skip the first reset in a warm copy: the nested Gateway in the copy would run the seed's code, not the commit under proof.
- A drain that also stops running proofs: a maintenance window can wait for them, and a stopped proof fails a pull request for no fault of its own.

## Consequences

- Another place on any CI Node makes a proof faster. A busy pool makes it slower, and the view shows why.
- Private repositories and the proof compete for the same places.
- Each CI Node stores a seed of the proof container and refreshes it after each release.
- While a proof runs on a CI Node, that Node's idle runners are stopped. Runner jobs that arrive then wait for a place on another Node or for the proof to end.
- Each warm copy holds one place, one task copy slot and one copy's memory peak on its Node while it waits. Runner jobs and task copies have that much less room.
- A proof that finds warm copies starts its cases without the copy create. A proof that finds none is as fast as before.
- A maintenance window that needs every copy of the default gone runs one drain, then waits for the running proofs.

## Affects

- Components: apps/gateway, apps/web, apps/docs
- ADRs: [0213](/decisions/0213-run-ci-runners-through-a-ci-runner-role), [0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers), [0262](/decisions/0262-prove-changed-operations-on-the-pull-request)
- Detail: [proof:pool:drain](/cli/proof/pool-drain); [proof:pool:resume](/cli/proof/pool-resume); [Node provisioning: ci-runner role](/reference/node-provisioning#ci-runner-role); [Proof copies on a CI Node](/reference/node-provisioning#proof-copies-on-a-ci-node); [The seed on each CI Node](/reference/development-containers#the-seed-on-each-ci-node); [proof:run](/cli/proof/run); [Implementation loop: CI](/reference/implementation-loop#ci)
- Verify: a proof with more batches than one Node's places runs copies on several CI Nodes, each from its own Node's seed, and merges one result; idle runners stop while a proof holds places and start again when its copies end, and busy runners are left alone; a Node without memory for a copy's peak takes no copy; the runner view lists each Node's places and each runner's current job; with `proof.warm_copies` above 0 the pool holds that many warm copies, a proof takes them first and each used copy is replaced by a new one; a seed refresh replaces each older warm copy; a drain destroys the idle warm copies, starts no new one and lets a running proof finish, and a resume refills the pool
