---
title: "Development container runtime"
description: "Plan for step 6 of ADR 0250: the build PRs for development Instances in containers with T3 inside. With tests, live checks, the parts to reuse, the reference page changes and the open questions."
---

# Development container runtime

This is the plan for step 6 of [ADR 0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers#build-order): development Instances in containers, with a T3 server inside each. [Development containers](/reference/development-containers) specifies the target and its charts. This page orders the work. It is a plan, not a contract: each PR moves the part it builds into the owning reference pages.

## Rules for every PR

Every build PR keeps these rules, so the work in progress never breaks what runs today.

| Rule | How a PR keeps it |
| --- | --- |
| Nothing that runs today changes | Checkout Instances, task VMs and task containers keep their behavior. The container path runs only on a Node with `settings.apps.containers` |
| A changed command starts with its spec | The first commit updates the command's chart, page and cases. The container charts move from `docs/cli/instance/container/` into place when the checkout path goes |
| Gateway and Swamp ship together | One PR, as the [contributor guide](/contributor-guide) requires |
| Swamp has the shape | Ansible phases do the machine work, steps call the Gateway only to sign or mint, secrets come from the vault. `bin/swamp-shape-gate` passes |
| `incus` gets no stray input | Every task and script that runs `incus` closes standard input |
| No secret enters a container | Only the placeholder model key. Git tokens stay on the Node; the Gateway's T3 session goes through the vault |
| The checks pass | `bin/swamp-shape-gate`, `bin/check-flows`, `composer docs-lint` and the tests of each changed project |
| Live checks run on Beast | On a container side of Beast that no other work uses, with a test Laravel Project. Beast's checkout Instances keep running |
| Docs move with the code | A PR moves the part it builds from [Development containers](/reference/development-containers) into the owning page |

## Before the first build PR

These come from other work and must land first.

- **The model forwarder and guard change.** A separate PR builds the per-Node forwarder and lets the model router guard accept agent Nodes and the `?beta=true` query. PR 4's live checks need it.
- **Container egress for packages.** Step 2c of ADR 0250 names the package path that setup steps use. PR 3 builds the source container with it.
- **Pairing works.** The spike's pairings failed at the vault step. The pairing fix on `main` must pair with a container's server before PR 4 ends.

## Build PRs

The PRs land in this order. PR 7 can run beside PR 6.

### PR 1: this specification

ADR 0250 records the decisions, [Development containers](/reference/development-containers) specifies the target, the charts state the flows, and this page orders the work. No code.

### PR 2: the container Node setting and the runtime records

This PR adds the setting and the records. Nothing reads them yet.

- `settings.apps.containers` with `cidr`, `project`, `network` and `t3_ports`. `node:update --apps` accepts it; the API, MCP schema, SDK and web types follow.
- Its bridge must not collide with the VPN subnet, the Node's other bridges or another Node's bridges: `node.settings_invalid`.
- A migration adds `instance_runtimes`, one row per container Instance.
- A row holds the container's Incus names, bridge address and T3 port.
- It also holds the agent stack version, the source container and the power state.
- A migration adds `instance_source_containers`, one row per Project and Node, with its commit and state.
- The Conn layer's environment gets a nullable Instance.
- `instance:create` on a container Node refuses with `instance.runtime_unavailable` until PR 4 replaces the refusal.

Tests: Pest for validation, collisions, the port range, the records and the refusal. No live check.

### PR 3: prepare a container Node and the shared agent stack

This PR prepares a Node; it creates no Instance.

- A container branch of the `app-dev` role converge sets up the Incus project, the bridge and the firewall rules for the T3 ports.
- The Node's database server admits the bridge, for Instance roles only.
- The container image `orbit-dev`: Ubuntu 26.04, PHP from the recipe, `agent` 2750, `orbit` 1000, `work` 2760, no sudo, `nftables` with the two uid rules, `hidepid`, `acl`, and the T3 unit with its marker condition.
- The recipe pins T3, Claude Code, Codex, Node and Bun. The converge installs `/opt/orbit-agent/<version>` with its agent-only subtree, the three environment notes and the `current` link.
- An agent stack update installs the new version and flips `current`. Then it restarts T3 in each running container, one after another. It runs with the release refresh of each Node.

Tests: Molecule `unit` cases for the nftables and unit templates and the notes, `ansible-lint`, Pest for the recipe pins. Live on Beast:

1. The converge runs twice; the second changes nothing.
2. In a test container from the image, `orbit` cannot run `t3`, `claude` or `codex`, and cannot read `/home/agent`.
3. As `orbit`, the forwarder port and T3's port reset, `ps` shows no agent process, and `sudo -n true` fails.
4. As `agent`, the three binaries run at the pinned versions.
5. An update to a second version flips the link and restarts T3 in a running container during a `sleep 40` tool call; the turn completes.

### PR 4: create an Instance in a container

This PR changes `instance:create`, so its spec commit updates the create page and cases with [container/create.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/create.mmd) and [container/t3-register.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/t3-register.mmd).

- The source container: built at the first create of a Project on the Node, kept stopped, never starts T3.
- The copy, the branch push from the Node, the workspace, `.env`, the agent settings, the note links and the Instance marker.
- The database: create the owner and database on the Node's server, and copy the default Instance's PostgreSQL database with `pg_dump` and `pg_restore`.
- Setup steps and Processes as `orbit` inside the container; the Route to the web Process at the container's bridge address.
- The T3 server starts for the first time. Its Gateway session goes through the vault.
- The Gateway records the server from the result, with the registration checks. The record names the Instance.
- The daily renewal run.

Tests: Pest for the intent, the projection, the registration checks from a result, and the refusals; Ansible `unit` cases; the `instance:create` cases for a container Node. Live on Beast, with a test Laravel Project:

1. The default Instance answers on its Route, served by PHP-FPM as `orbit`.
2. Conn on a Mac pairs with its T3 server through Orbit. A Claude turn and a Codex turn edit files, and the changed-files view shows them.
3. `zfs get written` on the copy shows only a few MB.
4. A second Instance gets a copy of the default database.
5. A failed setup step leaves a stopped container; the same create resumes it.
6. The PR records the times of the source build, the copy, the first T3 start, the setup and the whole create.

### PR 5: copy a feature with its database

The spec commit adds [container/copy.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/copy.mmd) and the `--from` cases.

- `--from` reads the source Instance's commit and dumps its database while it keeps running.
- The new branch starts at that commit; the database is restored into the new one.

Tests: Pest for the source checks and the intent; the PostgreSQL copy tests. Live on Beast:

1. Rows written in the source before the copy are in the feature; rows written in the feature are not in the source.
2. Requests to the source keep answering during the copy.
3. A local commit in the source is in the feature; an uncommitted file is not.
4. The feature's T3 server has a new environment id; the source's sessions do not work on it.
5. The PR records the dump, restore and copy times for a small and a 1 GB database.

### PR 6: destroy a container Instance

The spec commit adds [container/destroy.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/destroy.mmd) to the destroy page and cases.

Tests: Pest for the refusals, the record removal and the port release. Live on Beast:

1. Destroy refuses during a running turn, and with `--force` removes the Instance.
2. The paired Mac loses the server at once.
3. The container, the database, the port's proxy device and firewall rule are gone; the source container and the other Instances work.

### PR 7: sleep and wake

The spec commit adds [container/sleep.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/sleep.mmd) and [container/wake.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/wake.mmd) to the hibernation page.

Tests: Pest for the sweep's choices, the wake admission by caller, and the joined wake. Live on Beast:

1. An idle Instance stops; one with a running turn does not.
2. A request to its Route shows the progress page and then the app, within 5 s.
3. Conn opening the sleeping server wakes it.
4. A session within seven days of its expiry is renewed at the wake.

### PR 8: start a thread in an Orbit Project (Conn)

This is a Conn PR, with the Orbit parts it needs. It follows [container/thread.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/thread.mmd).

- Conn lists the Orbit Projects that the device can reach.
- A thread in one of them creates an Instance, pairs with it and sends the first message.
- Conn shows a sleeping Instance's server as asleep and wakes it on open.

Live: a thread started on the Mac in an Orbit Project ends in a new Instance with its first turn answered.

### PR 9: attach a task to an Instance

This PR lets the task flow drive an Instance that a user also drives.

- A task group can name an existing Instance.
- Its turns go to that Instance's thread through T3's MCP endpoint: a send with `queue`, a wait and a read.
- The task's check and publish run in the Instance's container; publishing reads the branch out and pushes it from the Node.
- Pausing the task stops Swamp from sending; the user drives from the thread.

Tests: Pest for the attach admission and the turn intent; tests of the Swamp T3 driver against a T3 server. Live: attach a task to an Instance, see its turns in the Conn thread, pause it, send a turn as the user, resume it.

### PR 10: task containers on T3

Task containers lose the Node identity of their temporary exception and the Pi server, and run T3 as Instances do. This removes the exception from ADR 0250. It needs the parity gate of step 4 passed.

## What to reuse from the task-4193 branch

The unpushed branch `task-4193` (bundle `~/apps/orbit-container-plan/.backup/proof-4193.bundle`, head `e1690fe44`, base `9ff162721`) built development containers inside a Project VM with a pause protocol in a Conn fork. Its record, ADR 0249, is not merged. This design keeps its parts that do not depend on the Project VM or the pause.

| Reuse | Where it goes | Change |
| --- | --- | --- |
| `InstanceRuntime`, `InstanceRuntimeKind` and the migration `add_development_runtime_ownership` | PR 2 | Drop the Project VM columns and the state volume |
| `ContainerCreateInput`, `ContainerCreateProjection`, `ContainerDestroyInput`, `ContainerDestroyProjection` | PR 4, PR 6 | Drop the source hold, receipts and compensation admission |
| `ContainerProcessTarget`, `ContainerProcessDefinitions`, `ContainerProcessInspection`, `ContainerProcessObservation`, `RefreshContainerProcessStatus` | PR 4 | The target is an Incus project and container on the Node, not a VM; the directory is `/srv/workspace` |
| `swamp/ansible/tasks/process-systemd.yml`, `process-container.yml`, `process-guest.yml`, `tasks/process-container-target.yml` and their tests | PR 4 | Remove the VM checks; units get `User=orbit` |
| `tasks/container-setup-step.yml`, `tasks/container-feature-branch.yml` | PR 4, PR 5 | Run as `orbit` and `agent`; the branch comes from the Node |
| `library/orbit_postgres_copy.py`, `postgres-copy.yml`, `PostgresCopyDatabase`, `PostgresDatabaseCopyInput`, the PostgreSQL copy in `DatabaseStatements`, and `ansible_postgres_copy_test.py` | PR 4, PR 5 | No writer fence; the dump reads a live database |
| `files/container-delete.py`, parts of `container-disposal-remove.yml` and `container-disposal-teardown.yml`, `container_delete_test.py` | PR 6 | No offline credential path and no state volume |
| `InstanceLifecycleContribution(s)`, the Conn `ConnInstanceLifecycle` and the migration `add_workspace_binding` | PR 2, PR 4 | The binding is the environment's Instance; no `paused` state |
| `--from` in `StoreInstanceRequest`, the CLI and the SDK's `CreateInstanceRequest` | PR 5 | No `--runtime`: the Node decides |
| `swamp/proof/instance/create.cases.yaml`, `destroy.cases.yaml`, `proof/runner/container_runtime.py` | PR 4 to PR 6 | Cases for a container Node without a Project VM |

These parts are dropped:

- The Project VM: `ProjectVm`, the project-runtime phases and the outer VM recipe.
- The pause protocol: the Conn fork RPCs `orbitRuntime.pause`, `status`, `resume` and `retire`, their scripts and the T3 cgroup containment. A copy here never pauses its source.
- Source restoration: `container-copy-receipt`, `ContainerSourceHold`, `ContainerSourceRecovery`, `orbit_postgres_fence.py` and the compensation admission.
- The state volume, the `/workspace/app` path, the Node-local loopback listeners, the `--runtime` option and the `app` user.
- The task park, prep and proof fleet changes, which belong to other work.

Some commits fix shared code: reading an empty Swamp projection, reading rows from select queries, Route workflows without probes, key identifiers in signed TLS leaves, and the sandbox DNS upstream. None is on `main`. Each build PR checks whether `main` still has the defect it touches, and lands the fix as its own small PR if it does.

## Reference page changes

Each PR moves its part into these pages. The specification stays on [Development containers](/reference/development-containers) until its PR lands.

| Page | Change | PR |
| --- | --- | --- |
| [Node settings](/reference/node-settings) | `apps.containers` and its fields | 2 |
| [Concepts](/concepts) | Container Node, source container | 2 |
| [Compute drivers](/reference/compute-drivers) | The container image, the agent stack in the recipe, the stack update | 3 |
| [Database servers](/reference/database-servers) | Access from a container bridge | 3 |
| [Model router](/reference/ai-router) | The forwarder and agent Node admission | Separate PR |
| [Applications](/domains/applications) | Instances on container Nodes, the source container, the copy | 4, 5 |
| [`instance:create`](/cli/instance/create) | The container path, `--from`, the new errors | 4, 5 |
| [Conn layer](/reference/conn) | Registration by Orbit from a run, the environment's Instance, the asleep state, `conn.environment_asleep` | 4, 7 |
| [Processes and Schedules](/reference/processes-and-schedules) | Processes as `orbit` units in a container | 4 |
| [Routes](/reference/routes) | An upstream at a container's bridge address | 4 |
| [Environment variables](/reference/environment-variables) | `.env` in a container, owned by `orbit` with group `work` | 4 |
| [`instance:destroy`](/cli/instance/destroy) | The turn check and the loss of thread history | 6 |
| [App-dev runtime hibernation](/reference/app-dev-runtime-hibernation) | Whole-container sleep, the wake callers, no pruning | 7 |
| [Tasks](/reference/tasks) | Attaching a task to an Instance; the T3 driver | 9 |
| [Pi server](/reference/pi-server) | Scope: task VMs only | 10 |
| [Old paths](/reference/old-paths) | Checkout Instances on container Nodes | 4 |

## Open questions

Each question has options and a recommendation. A PR that depends on one stops until it is decided.

### 1. How Orbit registers a container's T3 server

It blocks PR 4.

- (a) The Gateway records the server from the run's result, with the checks of `POST /conn/environments`.
- (b) A step on the Node calls `POST /conn/environments` as the Node.
- (c) The container calls it.
- Recommendation: (a). (b) breaks the rule that steps call the Gateway only to sign or mint. (c) gives the container its Node's Gateway access.

### 2. How a Node becomes a container Node

It blocks PR 2.

- (a) `settings.apps.containers` per Node: every new Instance there is a container.
- (b) A `--runtime` option per create.
- (c) Every `app-dev` Node at once.
- Recommendation: (a). It switches one Node at a time and keeps one way per Node; (b) gives two ways to create.

### 3. What a new thread's Instance is

It blocks PR 8.

- (a) Conn names it from the first message. It copies the Project's default Instance with `--from`.
- (b) A fresh Instance from the default branch with an empty database.
- (c) Conn asks each time.
- Recommendation: (a). A thread usually needs real data; (c) can come later as an option.

### 4. Web server in the container

It blocks PR 4.

- (a) PHP-FPM and a Caddy inside, both Orbit Processes as `orbit`.
- (b) FrankenPHP in classic mode, one Process.
- (c) `php artisan serve`.
- Recommendation: (a). It matches checkout Instances and keeps the PHP bridge and toolbar as they are. (b) is leaner and matches the production plan, so it is the next step once production uses it.

### 5. Schedules and keep-alive Processes while asleep

It blocks PR 7.

- (a) A container with a keep-alive Process or an enabled Schedule never sleeps.
- (b) It sleeps anyway, and its Schedules miss their runs.
- (c) The Node wakes it for each Schedule.
- Recommendation: (a). It keeps today's promise of keep-alive with no new machinery.

### 6. Side effects of copied data

It blocks PR 5.

- (a) Setup steps run before any Process with `ORBIT_COPIED_FROM` set, and the Project clears what must not run twice. The env template points mail at a local catcher.
- (b) Orbit empties the queue tables of a copy.
- (c) A copy starts no queue Process until the user starts it.
- Recommendation: (a). Orbit cannot know a Project's side effects.

### 7. How Git leaves a container

It blocks PR 9.

- (a) An Orbit operation reads the branch out and pushes it from the Node, as for task VMs.
- (b) A forwarder on the Node swaps a placeholder for a minted token, so `git push` works in the container.
- (c) GitHub egress with a token in the container.
- Recommendation: (a) now. (b) gives the better experience but needs a minting credential on the Node, so it waits for its own design. (c) breaks the placeholder rule.

### 8. When the source container is rebuilt

It blocks PR 4.

- (a) At the next create, when the default branch's lockfiles changed since the build.
- (b) On a daily schedule.
- (c) Never; setup reconciles the dependencies.
- Recommendation: (a). A copy stays fast. The rebuild happens only where it saves setup time.

### 9. Thread history on destroy

It blocks PR 6.

- (a) It goes with the container; destroy refuses during a running turn.
- (b) Orbit exports the transcripts to Project Documents first.
- Recommendation: (a) now, with the loss stated on the destroy page. (b) can follow.

### 10. Checkout Instances on a Node that becomes a container Node

It blocks PR 4.

- (a) None move: create new Instances as containers and destroy the old ones.
- (b) `instance:transfer` moves a checkout into a container.
- Recommendation: (a). There are few, and a copy with `--from` covers the data once a container default exists.

### 11. Database servers on other Nodes

It blocks PR 4.

- (a) Refuse them with `instance.database_server_remote`.
- (b) Reach them over WireGuard, with the Node's address as the source.
- Recommendation: (a). Every container Node has a local server, and (b) admits every container of the Node.

### 12. Pairing token in T3's journal

It blocks PR 3.

- (a) Start T3 with `--bootstrap-fd`, so it prints no token.
- (b) Accept it: only root and `agent` read that journal.
- Recommendation: (a) when the pinned T3 supports it, otherwise (b).

### 13. When task containers move to T3

It blocks PR 10.

- (a) After PR 9, before step 7.
- (b) With step 7.
- Recommendation: (a). One engine in all containers is simpler, and step 7 then removes only the task VM runtime.
