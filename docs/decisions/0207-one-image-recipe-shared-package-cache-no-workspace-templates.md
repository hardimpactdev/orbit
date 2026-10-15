---
title: "ADR 0207: One image recipe, shared package cache, no workspace templates"
sidebarTitle: "0207 One image recipe"
description: "In progress. One versioned recipe builds immutable task and role images and drives nightly production convergence; workspaces clone and install with a read-only host package cache."
---

# ADR 0207: One image recipe, shared package cache, no workspace templates

Orbit uses one versioned recipe for machine software in development and production. Workspaces always clone and run Project setup, using a shared read-only package cache rather than per-Project templates. This record holds the design agreed with Nick on 2026-10-14 while it is being built; it does not describe delivered behavior.

## Status

In progress.

Principle: [One way, one name](/mission#principles) and [Lean](/mission#principles). One recipe replaces separate image and production software paths; fresh claims replace the warm pool and workspace templates. [Security fits the real threat model](/mission#principles): agents are root inside task VMs, so they must not write a cache shared with other Projects. No principle exception is proposed.

This partly supersedes [ADR 0206](/decisions/0206-build-task-vm-images-as-chains-of-small-jobs): its image-sync and workspace-template next steps and fixed nightly image-build trigger give way to host-local recipe builds, no templates, and recipe-triggered builds. Its small queued build steps, `Bus::chain`, `task_vm_image_builds` records, and enrollment trusting the image remain the baseline. This proposal also replaces the incremental/weekly-from-stock image policy where it conflicts with one immutable recipe version: a previous image's history must not determine the new recipe's contents.

## Context

[Compute drivers](/reference/compute-drivers) describes the current base image, workspace templates, warm pool, claims, and topology preparation. [Incus topologies](/reference/incus-topologies) describes role images and their composition. Today a topology group builds its own role images inside its task VM, spending about 7 to 9 minutes on repeated work. Templates and warm VMs save setup time but add capacity reservations, churn, and separate lifecycle code.

Before step 6a, Instance setup delivered CI test caches through `@orbit/ci-caches` as downloaded artifacts sent in base64. Project dependencies and test caches are not machine software. Baking them into workspace templates couples image readiness to each Project's setup and dependency changes.

Task VM host configuration comes from `ORBIT_TASK_VMS_INCUS_HOSTS`. Being an `app-dev` Node does not by itself mean that a Node is an operator-approved task VM host. [Node settings](/reference/node-settings), [Tools](/reference/tools), and [Fleet rollout](/reference/gateway-recovery#fleet-rollout) provide the baseline for explicit host intent, per-Node tool inventory, and rollout. The recipe must connect development images to production maintenance rather than leaving production on a separate version path.

## Decision

The eleven decisions below form one contract. The Gateway owns trusted builds, cache fills, and orchestration; task groups consume their results. This proposal does not implement code, change task group 1479's branch, or decide the separate topics of Recall as a production Instance and its services role.

### 1. Two layers: base image and workspace

There are two layers: the base image holds the OS and machine toolchain; the workspace holds the Project checkout and the result of its setup steps. Package and CI test caches accelerate workspace setup without becoming a third workspace-image layer.

### 2. No per-Project workspace templates

Every workspace is a clone from the Gateway mirror followed by the Project's setup steps, every time. Orbit removes workspace templates for each Project. It does not retain template versions or introduce another snapshot shortcut.

### 3. CI test caches travel through a Git ref

Test caches come from CI through `refs/orbit/ci-cache/<sha>`, bound to the full tested code SHA, not the cache commit's object ID. The workspace receives the selected cache ref with the code from the Gateway mirror. This replaces the artifact download sent as base64 by `@orbit/ci-caches`; test caches remain distinct from the shared package cache.

Step 6a settles publication and retention in [CI cache refs](/reference/implementation-loop#ci-cache-refs) and selection and fallback in [Instance setup](/reference/instance-setup#import-ci-main-caches). The `publish-ci-cache` job runs only after main's required checks succeed, using a job-scoped `GITHUB_TOKEN` with `contents: write`. The Gateway reads refs with its App's `Contents: read` token; the workspace gets no credential. The ref suffix, manifest, and graph must match the selected tested baseline SHA. Missing, stale, incompatible, or invalid refs leave the workspace cold, without substituting another SHA. The publisher retains refs for 14 days plus the newest successful main commit's ref; pruning does not remove caches already imported by running workspaces.

### 4. One read-only shared package cache per task VM host

Each task VM host has one shared package cache for Composer and npm/bun. Task VMs mount it read-only. A trusted Gateway job fills it and records an inventory: package, version, integrity hash, and which Projects use it. Untrusted agents are root in their VM; allowing them to write this shared cache would allow cross-Project poisoning. The inventory answers “which Projects use package X at version Y” during an incident.

When a Project's default branch moves in the Gateway mirror, the Gateway cheaply checks its lockfile blob hashes. Changed hashes trigger a fill on each task VM host. Bursts coalesce, and filling only adds what is missing. Package changes do not rebuild machine images or create workspace templates.

### 5. A Node setting marks task VM hosts

`settings.task_vms` marks task VM hosts, not the `app-dev` role. It holds today's per-host configuration from `ORBIT_TASK_VMS_INCUS_HOSTS`, including pool and image alias. The list remains operator-owned. Placement, image sync, the package cache, and capacity follow this setting.

### 6. One base image is built once per recipe change per host

Step 3 implements the [host-local build and publication contract](/reference/compute-drivers#build-the-base-image). Step 4 implements [current-recipe claims and nested imports](/reference/compute-drivers#prepare-the-topology).

Each task VM host builds and smoke-tests only the base image once per recipe change. Topology groups import that single image into nested Incus and launch gateway, app-dev, app-prod, and operator VMs from the same fingerprint. This removes about 7 to 9 minutes of repeated image building per group; transfer and convergence still take time.

Nick approved this simplification for PR #1240 after an audit found all four role tool sets were subsets of base. The chain drops from 54 to 14 jobs per host, including the final pruning step, and each topology VM receives one archive instead of four. Add separate role images only when a role needs software absent from base. Gateway and VPN programs run during topology convergence so they follow the checkout's product enrollment path. Before deployment, drain or cancel pre-recipe groups and release their topologies; the harness builder and operator-container fallback are removed.

### 7. One recipe, one rule

A versioned recipe in the repository holds the Ubuntu snapshot date from `snapshot.ubuntu.com`, pinned tool versions, and which tools each role gets. A recipe change rebuilds the base image and converges production Nodes to that same recipe. There are no input-fingerprint partial builds.

The recipe changes nightly through an automated snapshot-date bump, or at any time through a task group PR, such as a new Caddy version. A merged recipe change starts the image build immediately; production follows nightly. Images are immutable: one image is exactly one recipe version, identical for development and production. Role-specific tool sets do not imply separate version recipes. Experiments happen on hosts, never in images.

### 8. Production follows the recipe nightly

Production Nodes follow the recipe nightly. Each Node gets only the tools of its roles, checked against Orbit's per-Node tool inventory. An image build is not permission to install all development tools on every production Node.

### 9. Claims require the current recipe's image

A claim takes only a VM built from the current recipe's image. During a build, new groups wait with a clear status; running groups keep their VM. There is no full-enrollment fallback. Keeping the previous images after a failed build does not make them eligible for new claims against a newer current recipe.

### 10. Remove the warm pool after group 1505 merges

A fresh VM from the current image is ready in about 30 to 60 seconds. Orbit removes the warm pool because it held capacity and caused churn and code. Task group 1505's warm-claim rework must merge first as a restore point; warm-pool removal does not precede that merge.

### 11. One nightly run at 03:00 Europe/Amsterdam

The nightly run follows this order:

1. Bump the recipe's Ubuntu snapshot date. Stop if nothing changed.
2. Build the base image on the task VM hosts. Stop on failure and keep the previous images.
3. Smoke-test a topology composed from the new base image before touching production.
4. Converge production one Node at a time: app-dev Nodes, then services, then production Nodes (app-prod, then vpn), and the Gateway last. Use apt from the snapshot and only the tools of the Node's roles. Restart only services whose packages changed. Run a health check and Doctor after each Node.
5. Stop at the first failure and notify the operator. There is no automatic rollback. Manual rollback is pinning the previous snapshot date.
6. Report what changed.

The local time zone is part of the schedule, not a fixed UTC offset. A build or smoke failure prevents production convergence. A Node failure stops the sequence at that Node, leaving Nodes after the failure untouched; it does not undo earlier Nodes.

### Worked examples

#### A Project adds a Composer package

A task group's PR adds a Composer package and updates `composer.lock`. When the PR merges, the Project's default branch moves in the Gateway mirror and the lockfile blob hash changes. The Gateway coalesces pending checks and queues a trusted fill on each task VM host, adding only missing packages and recording package, version, integrity hash, and Project use. The next workspace clones from the mirror and runs Project setup; Composer installs from the read-only host cache. No base image or workspace template is built, and the agent cannot publish cache contents to other Projects.

#### A task group bumps Caddy

A task group changes the pinned Caddy version in the recipe. Its PR merges, making that recipe current and immediately starting base image builds on the task VM hosts. New claims wait with a clear status until the current recipe's image is available; running groups keep their VM. The base rebuilds as one unit, including when only one role uses the changed tool. Production converges to the recipe that night, with only each Node's role tools and the ordered checks and service restarts of the nightly run.

#### A normal night

At 03:00 Europe/Amsterdam the automated run bumps the snapshot date. The task VM hosts rebuild the base image, and a smoke topology composed from the new base image passes. Production convergence then visits app-dev Nodes, services, app-prod Nodes, vpn Nodes, and the Gateway last, one Node at a time. Each uses apt from the new snapshot and its role tools; only services whose packages changed restart. Health checks and Doctor pass after every Node, and the run reports the changes.

#### A Node fails during the night

The snapshot bump, image builds, and smoke topology pass. The run converges the app-dev Nodes and then a services Node fails its health check or Doctor. The run stops at that Node and notifies the operator. Earlier Nodes remain on the new recipe; the failing Node may be partly changed. Services and production Nodes after the failed Node, including the Gateway, stay on yesterday's recipe. There is no automatic rollback. The operator can pin the previous snapshot date for manual rollback and investigate before continuing.

## Rejected alternatives

- **A fixed nightly rebuild with no recipe:** time alone does not identify the intended OS and tool versions or connect development images to production. A versioned recipe makes both nightly snapshot updates and merged tool changes follow one rule.
- **Input fingerprints with partial builds:** per-input invalidation adds another correctness model and can leave different image roles on different inputs. A recipe change rebuilds the base as one unit.
- **Groups converging and publishing images themselves:** untrusted groups would write a shared machine-software result, and concurrent groups can race to publish it. Trusted Gateway orchestration owns builds and publication instead.
- **Per-Project templates kept at most two versions:** a retention limit reduces storage but keeps template builds, invalidation, and setup coupling. Clone plus Project setup with package and CI caches replaces templates entirely.
- **A writable shared cache:** root agents can poison dependency contents for another Project. Only the trusted fill job writes; task VMs receive a read-only mount and the inventory supports incident investigation.
- **Keeping the warm pool:** its capacity reservation, churn, and lifecycle code are not worth avoiding a fresh boot of roughly 30 to 60 seconds. Group 1505 merges first to provide a restore point before removal.

## Consequences

- Machine software has one reviewable source of intent. Development images and nightly production maintenance use the same recipe versions and role tool sets.
- Workspace setup always runs. There is no template shortcut, but package downloads and CI test-cache delivery no longer require per-Project images or base64 artifact transport.
- Host-local builds and shared caches use host storage and trusted job capacity. Inventory maintenance must preserve accurate Project usage even though fills only add missing content.
- New groups wait during current-recipe builds or failures instead of silently using yesterday's image. Running groups remain stable and can temporarily differ from the current recipe.
- Production now has nightly maintenance and service restarts when packages change. Serial checks limit the failure spread, but do not provide zero downtime or an atomic fleet upgrade.
- A failure can leave a mixed-recipe fleet and a partly converged Node. The operator receives the stopping point and chooses recovery; manual snapshot pinning, not automatic rollback, is the agreed recovery path.
- Removing the warm pool frees held capacity and deletes lifecycle code, at the cost of fresh boot latency. Removing templates makes successful Project setup important on every workspace creation.
- The recipe and package inventory improve traceability, but they do not by themselves settle credentials, cache retention, or every manager's integrity and version-pinning details.

### Open questions

These questions concern implementation details, not alternatives to the eleven decisions. Step 5 settles the package-cache parts in [Shared package cache](/reference/compute-drivers#shared-package-cache): read-only consumption, private-registry scope, integrity, inventory removal, and storage limits. CI cache refs and image retention remain separate work:

- **Settled for step 1:** [Machine software recipe](/reference/compute-drivers#machine-software-recipe) defines schema version 1, a canonical JSON SHA-256 identity shared by all profiles, explicit role tool membership, and structural plus online availability/integrity validation. Third-party non-snapshot tools use exact HTTPS release artifacts and recorded digests, not moving installer channels. Orbit-built binaries may instead use a content-addressed Gateway-local pin with source commit and digest; the operator approved this exception for Pi at `e5933d4c8696` on amd64 only and does not select a new agent baseline. A local directory resolves bytes, not identity. Validation rejects missing files, digest mismatches, and profiles requesting unsupported architectures; installers retain pre-install hash verification. Steps 3 and 8 add image/Node identity recording; step 3 fences build publication.
- How does the trusted nightly snapshot bump enter repository history, and how are concurrent nightly and task group recipe changes serialized so that an older build cannot publish as current?
- How are builds and readiness tracked across multiple task VM hosts, including a host added during a build? Claims expose `Task VM: waiting for recipe <12-character digest> images on <host>.` Failed builds stay blocked until a successful retry; running groups keep their recorded recipe.
- How do Composer and npm/bun consume a read-only shared cache while keeping writable temporary state private to the workspace? What is the cache-miss behavior before a fill completes, especially for dependency changes still on a task branch?
- How does trusted filling support private registries without exposing credentials or executing untrusted Project setup? How are integrity failures and suspect cached packages quarantined, and how does inventory usage change when a Project removes a package?
- What storage limits and retention rules apply to package caches and previous images without violating additive fills or running-group stability? CI cache refs use the [step 6a retention policy](/reference/implementation-loop#cache-ref-retention).
- Which health checks and Doctor results gate each Node, how is Node order chosen within each tier, and how does a multi-role Node fit the agreed tier order? What gates the smoke topology and handles an interrupted run?
- How is a previous snapshot pin applied during manual recovery, including a tool-version change or partly converged Node that snapshot pinning alone may not repair? What evidence does the final or stopped-run report retain?

### Implementation plan

The owner must review this ADR on its pull request before any implementation group starts. Implement the reviewed design through focused, reviewable task groups in dependency order. Each group updates its owning reference documentation and tests its failure behavior; this ADR remains In progress until the completed decision is absorbed. No implementation is part of this documentation subtask.

1. **Recipe contract and validation.** Define the versioned snapshot/tool/role recipe, its identity, and validation. Settle pinning and concurrency details before consumers depend on the format. Prove invalid and unavailable inputs fail clearly.
2. **Task VM host settings.** Move the operator-owned per-host configuration into `settings.task_vms`; make placement, image sync, capacity, and cache targeting use it rather than the app-dev role or environment list. Verify host selection and preserve existing operator intent.
3. **Recipe-driven base builds.** Build on groups 1 and 2 and ADR 0206's small-job records. Build and smoke-test one immutable base image once per recipe change per task VM host. Record recipe identity, preserve previous images on failure, and prevent stale publication. Verify merge-triggered builds and host-local failure handling.
4. **Current-recipe claims and topology imports.** Build on group 3. Gate claims on the current recipe, expose waiting status, preserve running groups, remove full-enrollment fallback, and import one base image for every role into nested Incus. Prove that groups do not rebuild role images or claim stale images.
5. **Trusted shared package cache.** Build on group 2. Implement lockfile-blob checks on default-branch mirror moves, coalesced additive fills for Composer and npm/bun on each host, integrity verification, inventory, and read-only VM mounts. Split manager adapters into separate reviewable groups if needed; each must prove the root-agent write boundary and Project-usage reporting.
6. **CI cache refs and template-free workspaces.** First deliver CI publication and mirror/workspace fetching of SHA-bound test-cache refs, replacing base64 artifact delivery. Then, with group 5 available, deliver clone-plus-setup workspaces and remove per-Project templates. The template-free implementation is documented in [Clone and set up every workspace](/reference/compute-drivers#clone-and-set-up-every-workspace), including manual legacy cleanup and preservation of existing groups. Keep these as separate reviewable groups so cache transport and template removal each have complete tests.
7. **Fresh claims and warm-pool removal.** Require both group 4 and the merge of task group 1505's warm-claim rework as a restore point. Remove the pool only after fresh current-image claims are proved; verify latency, capacity accounting, and running-group preservation.
8. **Recipe-based Node convergence.** Build on group 1. Converge apt from the snapshot and only each Node's role tools, check the per-Node tool inventory, restart only services with changed packages, and gate each Node on health checks and Doctor. Prove failure stops advancement and does not roll back automatically.
9. **Nightly orchestration and operator reporting.** Build on groups 3, 4, and 8. At 03:00 Europe/Amsterdam, bump the snapshot date, stop on no change, build images, smoke-test the composed topology, and converge the fleet in the agreed order with the Gateway last. Verify build, smoke, and Node failures, notifications, reports, and manual previous-snapshot pinning. Include the four worked examples as end-to-end acceptance scenarios.
10. **Complete the migration and absorb the decision.** After the preceding groups are verified, remove remaining superseded triggers and paths, record operational recovery instructions, and absorb the implemented contract and lasting reasons into the owning reference pages. Retire this ADR and update ADR 0206's overlapping text only as its implementation contract allows.

## Affects

- Components: apps/gateway, apps/e2e, apps/cli, apps/docs
- ADRs: Partly supersedes [ADR 0206](/decisions/0206-build-task-vm-images-as-chains-of-small-jobs), including its image-sync/workspace-template next steps and fixed nightly trigger; changes the image, workspace, and claim policy of [ADR 0200](/decisions/0200-run-each-task-group-in-its-own-sandbox-vm).
- Detail: [Compute drivers](/reference/compute-drivers), [Incus topologies](/reference/incus-topologies), [Instance setup](/reference/instance-setup), [Node settings](/reference/node-settings), [Tools](/reference/tools), and [Fleet rollout](/reference/gateway-recovery#fleet-rollout) receive the implemented behavior and reasons when the decision is absorbed.
- Verify: `composer docs-lint` checks the ADR. Implementation groups must test recipe changes, read-only cache isolation and inventory, SHA-bound CI refs, fresh current-image claims, an imported base image for all roles, serial convergence, first-failure stopping, and the four worked examples above.
