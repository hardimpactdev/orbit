---
title: "Development container runtime"
description: "Plan for steps 6 and 8 of ADR 0250: the build PRs for development and then production Instances in containers. With the decisions, tests, live checks, the parts to reuse and the reference page changes."
---

# Development container runtime

This is the plan for step 6 of [ADR 0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers#build-order), development Instances in containers with a T3 server inside each, and for step 8, production Instances in containers. [Development containers](/reference/development-containers) specifies the target and its charts. This page orders the work. It is a plan, not a contract: each PR moves the part it builds into the owning reference pages.

## Decided

These decisions were made on 2026-11-09. The PRs build on them without asking again.

| Date | Topic | Decision |
| --- | --- | --- |
| 2026-11-09 | Registration | A step in the Instance-create flow issues and checks the T3 session. The Gateway records the server from that step's result |
| 2026-11-09 | New Instances | A new thread's Instance, and every new Instance, is a ZFS clone of the Project's default Instance. Its database is cloned too, with `CREATE DATABASE … TEMPLATE` on the same server |
| 2026-11-09 | Web server | FrankenPHP, in development now and in production once it has proved itself. PHP also works on the command line |
| 2026-11-09 | Nodes | No setting to opt in. Every `app-dev` and `app-prod` Node runs its Instances in containers, and the two roles change what they install |
| 2026-11-09 | Shared runtime | PHP, FrankenPHP, Composer, Node, Bun and the other runtime binaries live once per Node in a versioned read-only folder. Every container mounts it, as the agent folder |
| 2026-11-09 | Container contents | Containers install as little as possible |
| 2026-11-09 | Production | Production Instances move into containers too, after development containers work |
| 2026-11-09 | Sleep | A development container sleeps after one hour idle. Development Instances have no Schedules. Production containers never sleep |
| 2026-11-09 | Setup | There are no development setup steps. Only a Project's first default Instance is built from scratch |
| 2026-11-09 | Default Instance | It deploys every merge to the default branch, so clones match it; a clone installs its own dependency changes. There is no separate source container |
| 2026-11-09 | Git | Orbit fetches and pushes from the Node |
| 2026-11-09 | History | T3 history is deleted with its Instance. An archive comes later |
| 2026-11-09 | Migration | Existing checkout Instances move into containers with their code and database |
| 2026-11-09 | Databases | The database family clones every database: a template copy on the same server, a dump and restore across Nodes. Remote database servers are allowed |
| 2026-11-09 | Pairing token | The pairing token that `t3 serve` prints to its journal is accepted |
| 2026-11-09 | Task containers | Task containers move to T3 after development containers work through PR 9, before step 7 of ADR 0250 |
| 2026-11-09 | Project files | MCP servers come from the project's own files. The agents use their own subagents only |

### The PHP command line

The command line runs a static PHP CLI from the same static build as FrankenPHP, in the runtime folder. A `php` shim around `frankenphp php-cli` does not fit yet: FrankenPHP refuses the `-d` options that Composer and test runners pass. Before PHP 8.6 it also cannot run `php -i` or PHP without a script, and Orbit runs PHP 8.5. One static build gives both binaries the same version and extensions, so web requests and commands behave the same. The shim can replace the CLI once FrankenPHP accepts `-d` and Orbit runs PHP 8.6.

## Rules for every PR

Every build PR keeps these rules, so the work in progress never breaks what runs today.

| Rule | How a PR keeps it |
| --- | --- |
| Nothing that runs today changes | Checkout Instances, task VMs and task containers keep their behavior. Until PR 11 switches the Nodes, only Nodes on the Gateway's build list run container Instances |
| A changed command starts with its spec | The first commit updates the command's chart, page and cases. The container charts move from `docs/cli/instance/container/` into place when PR 11 switches the Nodes |
| Gateway and Swamp ship together | One PR, as the [contributor guide](/contributor-guide) requires |
| Swamp has the shape | Ansible phases do the machine work, steps call the Gateway only to sign or mint, secrets come from the vault. `bin/swamp-shape-gate` passes |
| `incus` gets no stray input | Every task and script that runs `incus` closes standard input |
| No Orbit or provider secret enters a container | The placeholder model key and the Instance's own `.env` are all it holds. Git tokens stay on the Node; the Gateway's T3 session goes through the vault |
| The checks pass | `bin/swamp-shape-gate`, `bin/check-flows`, `composer docs-lint` and the tests of each changed project |
| Live checks use a test Node | An `app-dev` test Node on the build list, with a test Laravel Project. Beast's checkout Instances keep running until PR 10 moves them |
| Docs move with the code | A PR moves the part it builds from [Development containers](/reference/development-containers) into the owning page |

The build list, the Gateway setting `instances.container_nodes` (`ORBIT_INSTANCES_CONTAINER_NODES`), exists for the build only. It is not a Node setting, and PR 11 removes it.

## Before the first build PR

These come from other work and must land first.

- **The model forwarder and guard change.** A separate PR builds the per-Node forwarder and lets the model router guard accept agent Nodes and the `?beta=true` query. The live checks of PR 3 and later need it.
- **Container egress for dependencies.** Step 2c of ADR 0250 names the package path that dependency installs use. PR 4 needs it. The image build of PR 3 has its own way out, for its build container only.
- **Pairing works.** The spike's pairings failed at the vault step. The pairing fix on `main` must pair with a container's server before PR 5 ends.

## Build PRs

The PRs land in this order. PR 3b lands before PR 4, PR 7 can run beside PR 6, and PR 12 can land any time after PR 9.

### PR 1: this specification

ADR 0250 records the decisions, [Development containers](/reference/development-containers) specifies the target, the charts state the flows, and this page orders the work. No code.

### PR 2: the runtime records and the build list

This PR adds the records. No Node runs a container yet, and no flow writes the records; PR 3 and later write them. [Records](/reference/development-containers#records) on the reference page holds the contract.

- A migration adds `instance_runtimes`, one row per container Instance.
- A row holds the container's Incus names, bridge address and T3 port.
- It also holds the runtime and agent folder versions, the default Instance it was cloned from, and the power state.
- Database triggers keep the rules of the row. A Node's container, address and port each belong to one row.
- A clone source is the default Instance of the same Project and Node. The default Instance has no T3 port.

The Node, Conn and build list records:

- Each Node gets a bridge network: a `/24` of `10.253.0.0/16` that overlaps no other bridge and not the VPN subnet.
- The Node gets its bridge on the first allocation and keeps it. PR 3's converge makes that call.
- `node:update` refuses a task VM host bridge that overlaps an Instance bridge.
- The Conn layer's environment gets a nullable Instance.
- A Node cannot register an environment that names an Instance. Only the Instance's create flow registers it, from PR 5.
- The Gateway setting `instances.container_nodes` lists the Nodes that run container Instances during the build.
- The setting is Gateway configuration, as `task_vms.container_placement` is: `ORBIT_INSTANCES_CONTAINER_NODES`, a JSON list of Node ids.

Tests: Pest for the records, the bridge allocation and its collisions, the self-registration refusal, and the build list. No live check: the PR changes only Gateway records.

These points stay open after PR 2. Each named PR settles its point.

| Point | Fact | PR |
| --- | --- | --- |
| One default per Project | Instance names are unique per Project, so a Project has one `default` in the fleet. Its clones must share its Node. PR 4 keeps or changes that | 4 |
| Registration race | Registration reads the environment's Instance only before its T3 calls. The create flow bumps the cleanup revision, or registration reads it again in its lock | 5 |
| Destroy order | An environment that names an Instance keeps the Instance from deletion. Destroy removes the environment through the Conn contribution first | 6 |

### PR 3: prepare a Node and the shared folders

This PR prepares a Node; it creates no Instance.

The Gateway decides which Node gets the branch:

- A container branch of the `app-dev` role converge runs in `node-role-runtime.yml`.
- It runs only for a Node of the build list that is not a task VM. Every other Node gets the same intent as before.
- The admission of a role add or converge allocates the Node's Instance bridge. The capture only reads it.
- The allocation and a task host's new bridges are each one transaction, which the connection begins IMMEDIATE, so they cannot interleave.

The host side reuses the container host tasks of the task containers (#3279):

- Incus from the recipe, the SCTP check, the sysctl floors, the subordinate IDs and the ARC cap.
- The project, bridge, ACL, profile, stock image, input rules and clone probe.
- The Node provides the Incus storage pool `orbit-instances`: ZFS, or a directory pool on a nested proof Node. The converge refuses a Node without it.
- The project mounts only the two folders, read-only and idmapped, and allows proxy devices for T3.
- Every container gets an isolated ID map. The bridge `orbitinst0` reaches only the model forwarder by default.

The image and the folders:

- The image: Ubuntu 26.04, `agent` 2750, `orbit` 1000, `work` 2760, no sudo, `nftables` with the two uid rules, `hidepid`, `acl`, and the T3 unit.
- The T3 unit has its condition on `/etc/orbit/instance`.
- The image's alias is a digest of its definition. The stable alias `orbit-instance` names the newest.
- The build container alone reaches the internet. It takes the bridge's last address, with its own ACL and a route rule that the build removes.
- The recipe pins the runtime folder: Composer, Node, Bun and rg. Node and Bun reuse the recipe's own pins.
- The recipe pins the agent folder: T3, Claude Code and Codex. The Gateway adds the three environment notes.
- The converge installs both folders with their `current` links. A folder's version is a digest of its content.

A folder update installs the new version beside the old one and flips `current`. Then it restarts T3 or the Processes (`orbit-*.service`) in each running container, one after another. It runs with the converge of each Node. A version that no process maps goes.

Tests: the Ansible fixture tests for the Instance host phase and the task container host phase, which share their tasks; Pest for the recipe pins, the intent, the image files, the notes, the firewall catalog and the bridge transactions. Live on the test Node:

1. The converge runs twice; the second changes nothing.
2. In a test container, `orbit` cannot run `t3`, `claude` or `codex`, and cannot read `/home/agent`.
3. As `orbit`, the forwarder port and T3's port reset, `ps` shows no agent process, and `sudo -n true` fails.
4. As `agent`, the three binaries run at the pinned versions.

The isolation and update checks follow.

5. A second container with another isolated ID map shows the same: `agent` runs `t3`, `orbit` cannot.
6. From the second container, the first container's bridge address is unreachable.
7. An agent folder update during a `sleep 40` tool call restarts T3, and the turn completes.
8. A nested `app-dev` Node of the proof fleet runs a container on its directory pool, where a copy is a full copy.
9. The PR stops and asks where the `instance:*` proof cases run, if check 8 fails.

No fixture can prove these claims; only real Incus on the first test Node can.

10. As `orbit` in a test container, `ps -u agent` lists nothing: `hidepid=invisible` holds after a container restart.
11. The restricted project accepts the two folder disks, read-only and shifted, under `restricted.devices.disk.paths`.
12. The build container reaches the internet through its own NIC ACL. A test container on the bridge reaches only the forwarder.
13. As `orbit`, `10.253.x.1:8319` and T3's port 3773 answer with a reset; as `agent`, both answer.
14. The published image has no `ubuntu` user and no sudo grant.
15. Cloud-init stays off in a new container.

Three points of the first plan moved, because the code showed they belong elsewhere:

- **The static PHP build** moved to PR 3b. No published artifact gives FrankenPHP and a PHP CLI from one build.
- **Database access** moved to PR 5. A container reaches only its Instance's own database server, which only the create flow knows. That access is a rule on the container's NIC.
- **T3's published port** moved to PR 5, with the proxy device of each container.

The firewall uses no Molecule cases: the repository tests Ansible with fixture tests that run the shipped tasks against a stateful Incus stand-in.

### PR 3b: the static PHP build

This PR adds PHP to the runtime folder; it creates no Instance.

- A build job makes `frankenphp` and `php` from one static-php-cli build per PHP version that Orbit supports, with the recipe's extension list. It publishes both to the artifact mirror.
- The recipe pins them in the runtime folder as `php/<php-version>/bin`, with the extension list.
- The converge checks that both binaries load exactly the listed extensions.
- The environment notes name PHP and Composer from the runtime folder. Until then they name only Node, npm, Bun and rg.

Tests: Pest for the pins and the extension check; a fixture test for the check. Live on the test Node:

1. `php -v`, `php -i`, `php -d memory_limit=1G -r 1;` and a `composer install` with scripts all work in a test container.
2. FrankenPHP's and the CLI's extension lists match.

### PR 4: the first default Instance and its deployments

The spec commit adds [container/create-default.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/create-default.mmd) and [container/deploy-default.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/deploy-default.mmd) to the create and deployment pages and cases.

- The first default Instance of a Project on a Node is a container from the image, with the default branch pushed in from the Node.
- The database family creates its database. Orbit writes `.env` and runs the development deploy steps as `orbit`.
- FrankenPHP and the other development Processes as `orbit` units in the container; the Route to FrankenPHP at the container's bridge address.
- The automatic development deployment of each merge runs in the default container, and starts a sleeping one first.

Tests: Pest for the intent and projection of both flows; Ansible `unit` cases; the cases of both. Live on the test Node:

1. The default Instance answers on its Route, served by FrankenPHP as `orbit`, with the PHP Data Bridge toolbar.
2. A merge to the default branch deploys, and the Instance serves the new commit.
3. A merge that changes `composer.lock` installs the change through the deploy steps.
4. T3 does not run in the default container.
5. The PR records the build and deployment times.

### PR 5: create an Instance as a clone of the default

This PR changes `instance:create`, so its spec commit updates the create page and cases with [container/create.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/create.mmd) and [container/t3-register.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/t3-register.mmd).

- The clone of the default container and the database clone through the database family: a template copy on the same server, a dump and restore across servers.
- The branch from the Node, the workspace, `.env`, the agent settings, the note links and `/etc/orbit/instance`.
- The first start of T3, the Processes and the Route.
- The registration step and the Gateway's record of the server from its result. The record names the Instance.
- The container's NIC rule to its Instance's database server and port.
- The T3 proxy device and its route rule.
- The daily renewal run.

Tests: Pest for the intent, the projection, the registration record from a result, `instance.default_missing`; Ansible `unit` cases; tests of the template clone and of the dump path; the `instance:create` cases for a container Node. Live on the test Node:

1. A clone answers on its Route, with the default's data and its own `.env`.
2. Rows written in the clone are not in the default; the default answers again right after the template copy.
3. A create with `--database-server` naming a server on another Node gets a dump and restore.
4. A template copy that fails leaves the default database open for connections again.
5. Conn on a Mac pairs with the clone's T3 server through Orbit. A Claude turn and a Codex turn edit files, and the changed-files view shows them.
6. The clone's T3 server has its own environment id.

The disk, agent and failure checks follow.

7. `zfs get written` on the clone shows only a few MB.
8. An agent's `npm run dev` in the container shows in Conn's browser through T3's port preview. Without that, the PR stops and asks for a decision.
9. A message that the user sends in Conn during a turn is queued, not steered.
10. A failed step leaves a stopped container; the same create resumes it.
11. The PR records the clone, database clone, first T3 start and whole create times, for a small and a 1 GB database.
12. The clone reaches its database server on its port, on the Node and on another Node. Every other address on those Nodes is dropped.
13. T3's published port answers from a WireGuard peer and refuses a task VM.

### PR 6: destroy a container Instance

The spec commit adds [container/destroy.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/destroy.mmd) to the destroy page and cases.

Tests: Pest for the refusals, `instance.default_has_clones`, the record removal and the port release. Live on the test Node:

1. Destroy refuses during a running turn, and with `--force` removes the Instance.
2. The paired Mac loses the server at once.
3. The container, the database, the proxy device and the firewall rules are gone; the default Instance and the other clones work.
4. Destroying the default Instance refuses while it has clones.

### PR 7: sleep and wake

The spec commit adds [container/sleep.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/sleep.mmd) and [container/wake.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/wake.mmd) to the hibernation page.

Tests: Pest for the sweep's choices, the wake admission by caller, and the joined wake. Live on the test Node:

1. An Instance idle for an hour stops, keep-alive Processes and all; one with a running turn does not.
2. A user who keeps an open thread active in Conn, without a request to the site, keeps the Instance awake.
3. The PR names the T3 read that gives the last client activity. Without one, the PR stops and asks for a decision.
4. A request to its Route shows the progress page and then the app, within 5 s.
5. Conn opening the sleeping server wakes it.
6. A session within seven days of its expiry is renewed at the wake.

### PR 8: start a thread in an Orbit Project (Conn)

This is a Conn PR, with the Orbit parts it needs. It follows [container/thread.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/thread.mmd).

- Conn lists the Orbit Projects that the device can reach.
- A thread in one of them creates an Instance named from the first message, pairs with it and sends that message.
- Conn shows a sleeping Instance's server as asleep and wakes it on open.

Tests: Conn tests for the Project list, the create-then-pair flow, the queued send and the asleep state; Pest for any Orbit endpoint it adds. Live: a thread started on the Mac in an Orbit Project ends in a new clone with its first turn answered.

### PR 9: attach a task to an Instance

This PR lets the task flow drive an Instance that a user also drives.

- A task group can name an existing Instance.
- Its turns go to that Instance's thread through T3's MCP endpoint: a send with `queue`, a wait and a read.
- The task's check and publish run in the Instance's container; publishing reads the branch out and pushes it from the Node.
- Pausing the task stops Swamp from sending; the user drives from the thread.

Tests: Pest for the attach admission and the turn intent; tests of the Swamp T3 driver against a T3 server. Live: attach a task to an Instance, see its turns in the Conn thread, pause it, send a turn as the user, resume it.

### PR 10: migrate checkout Instances

The spec commit adds [container/migrate.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/migrate.mmd), the page of the temporary command `instance:migrate` and its cases. [Old paths](/reference/old-paths) lists the command until the last checkout Instance has moved.

- The command moves one checkout Instance into a container: the checkout as it is, its database cloned through the database family, its Route moved.
- A default Instance moves before the other Instances of its Project, and keeps its checkout until its last worktree has moved.
- A non-default checkout is a linked worktree, so the move builds a standalone repository from the Node and copies only the uncommitted and ignored files.
- A non-default Instance before its default refuses with `instance.default_not_migrated`.
- A failure leaves the checkout Instance running as before.

Tests: Pest for the admission, the default-first rule and the projection; Ansible `unit` cases; the cases. Live:

1. On the test Node, a checkout default and one checkout feature with uncommitted files move.
2. Both answer on their Routes, with the uncommitted files and matching data.
3. After the default has moved, a feature still on its checkout keeps working until its own move.
4. A migration that fails at the database clone leaves the checkout Instance serving.
5. On every `app-dev` Node, each checkout Instance moves, default first, in a window agreed with the people who use them.

### PR 11: switch every app-dev Node

This PR ends the build list.

- It needs every development checkout Instance moved, on every `app-dev` Node.
- Every `app-dev` Node except task Nodes converges the container setup, and the role stops installing PHP-FPM and checkout folders there.
- The build list goes; `instance:create` makes containers on every `app-dev` Node.
- `instance:clone` reads its candidate from the candidate's container, so production cloning keeps working until PR 15.
- The commands that only checkouts need go: `instance:setup`, the `instance:setup-step:*` commands, `instance:register` and the seed values `ORBIT_SEED_PATH` and `ORBIT_SEED_COMMIT`. [Old paths](/reference/old-paths) lists them.
- Teardown steps stay.
- `instance:transfer` refuses a container Instance until it gets a container path.
- The container charts replace the checkout charts of the same kinds; the first-default and development deploy charts become branches of the create and deploy charts.

Tests: Pest for create without the build list, the candidate read of `instance:clone`, and the removed commands; the role's Molecule cases without PHP-FPM. Live: every `app-dev` Node converges twice without change, a create on each makes a clone, and `instance:clone` makes a production Instance from a container candidate.

### PR 12: task containers on T3

Task containers lose the Node identity of their temporary exception and the Pi server, and run T3 as development containers do. This removes the exception from ADR 0250, before its step 7. It needs PR 9 and the parity gate of step 4.

Tests: Pest for the task intents without a Node identity, and the Swamp T3 driver tests of PR 9. Live: the parity gate runs again on task containers with T3 and gives the same verdicts as before; one real task group starts, turns, checks and publishes.

## Production Instances

Step 8 of ADR 0250 moves production Instances into containers on every `app-prod` Node, after PR 11. The [container plan](/solutions/container-instances-and-releases#production-design) holds the release design that these PRs specify and build.

### Production gate

FrankenPHP reaches production only after it has proved itself in development. This plan proposes the criteria below; they are confirmed before PR 13. The development Instances on FrankenPHP meet them for four weeks:

- No failure that comes from FrankenPHP itself and that PHP-FPM would not have.
- The PHP Data Bridge toolbar and every development Process work as they did on PHP-FPM.
- The median and 95th percentile response times of the default Instances are no worse than on PHP-FPM, measured before and after migration.

When the gate fails, the production web server is decided again before PR 13.

### PR 13: production specification

The release flow in containers: preparation container, candidate clone, activation, readiness, Route switch, drain and retained releases, with FrankenPHP and Schedules inside. The charts of `instance:clone`, `instance:deploy`, `instance:rollback` and the production migration, with their pages and cases. A release pins the runtime folder version it was built with, so a new runtime version reaches production only through a deployment. No code.

### PR 14: prepare an app-prod Node

This PR prepares a Node; it moves no Instance.

- A container branch of the `app-prod` role converge, with the runtime folder and no agent folder.
- The production container image: the user `orbit` only, no T3 unit.

Tests: Molecule `unit` cases and `ansible-lint`. Live on a test `app-prod` Node: the converge runs twice without change; a test container has no `agent` user and no agent folder.

### PR 15: a production Instance in a container

This PR serves one production Instance from a container.

- `instance:clone` makes a production Instance's container from its candidate.
- FrankenPHP serves it, and it never sleeps.
- Schedules stay native Swamp schedules that run their command in the container as `orbit`; the per-minute Laravel scheduler runs inside it.

Tests: Pest for the intent and projection; the cases. Live: a test Laravel Project serves in production from a container, a Schedule runs on time, and the hibernation sweep leaves the container running after an idle hour.

### PR 16: releases and rollback

This PR deploys and rolls back in containers.

- `instance:deploy` builds a release in the preparation container and clones it as the candidate.
- It activates the candidate, checks readiness, switches the Route and drains the old release.
- `instance:rollback` selects a retained release.

Tests: Pest for the release records and the switch projection; the cases. Live:

1. A long request is open. A deploy with a dependency change and a migration starts.
2. The long request finishes on the old release; new requests reach the new one.
3. A bad candidate stays out.
4. A rollback serves the old code.

### PR 17: migrate production Instances and switch app-prod Nodes

This PR ends checkouts in production.

- Each production checkout Instance moves into a container with its code and database, in a window agreed for that Instance.
- The `app-prod` role stops installing PHP-FPM once its Node has no checkout Instance.

Tests: Pest and the cases of the production migration. Live: each production Instance answers on its domain after the move, with its data, and its Schedules run.

## What to reuse from the task-4193 branch

The unpushed branch `task-4193` (bundle `~/apps/orbit-container-plan/.backup/proof-4193.bundle`, head `e1690fe44`, base `9ff162721`) built development containers inside a Project VM with a pause protocol in a Conn fork. Its record, ADR 0249, is not merged. This design keeps its parts that do not depend on the Project VM or the pause.

| Reuse | Where it goes | Change |
| --- | --- | --- |
| `InstanceRuntime` and the migration `add_development_runtime_ownership` | PR 2 | Drop the Project VM columns and the state volume. `InstanceRuntimeKind` and `instances.runtime_kind` are dropped: a runtime row marks a container Instance |
| `ContainerCreateInput`, `ContainerCreateProjection`, `ContainerDestroyInput`, `ContainerDestroyProjection` | PR 5, PR 6 | Drop the source hold, receipts and compensation admission |
| `ContainerProcessTarget`, `ContainerProcessDefinitions`, `ContainerProcessInspection`, `ContainerProcessObservation`, `RefreshContainerProcessStatus` | PR 4 | The target is an Incus project and container on the Node, not a VM; the directory is `/srv/workspace` |
| `swamp/ansible/tasks/process-systemd.yml`, `swamp/ansible/process-container.yml`, `swamp/ansible/process-guest.yml`, `swamp/ansible/tasks/process-container-target.yml` and their tests | PR 4 | Remove the VM checks; units get `User=orbit` |
| `swamp/ansible/tasks/container-feature-branch.yml` | PR 5 | The branch comes from the Node |
| `swamp/ansible/library/orbit_postgres_copy.py`, `swamp/ansible/postgres-copy.yml` with `swamp/ansible/tasks/postgres-copy.yml`, `PostgresCopyDatabase`, `PostgresDatabaseCopyInput`, the PostgreSQL copy in `DatabaseStatements`, and `ansible_postgres_copy_test.py` | PR 5 | The dump path across servers; the database family adds the template copy on one server |
| `swamp/ansible/files/container-delete.py`, parts of `swamp/ansible/tasks/container-disposal-remove.yml` and `container-disposal-teardown.yml`, `swamp/tests/container_delete_test.py` | PR 6 | No offline credential path and no state volume |
| `InstanceLifecycleContribution(s)`, the Conn `ConnInstanceLifecycle` and the migration `add_workspace_binding` | PR 5 | The binding is the environment's Instance, which PR 2 adds as a column; no `paused` state. The contributions come with their first caller, the create flow |
| `swamp/proof/instance/create.cases.yaml`, `destroy.cases.yaml`, `proof/runner/container_runtime.py` | PR 4 to PR 6 | Cases for a container Node without a Project VM |

These parts are dropped:

- The Project VM: `ProjectVm`, the project-runtime phases and the outer VM recipe.
- The pause protocol: the Conn fork RPCs `orbitRuntime.pause`, `status`, `resume` and `retire`, their scripts and the T3 cgroup containment.
- Source restoration: `container-copy-receipt`, `ContainerSourceHold`, `ContainerSourceRecovery`, `orbit_postgres_fence.py` and the compensation admission.
- `--from`, the setup step task `container-setup-step.yml`, the state volume, the `/workspace/app` path, the Node-local loopback listeners, the `--runtime` option and the `app` user.
- The task park, prep and proof fleet changes, which belong to other work.

Some commits fix shared code: reading an empty Swamp projection, reading rows from select queries, Route workflows without probes, key identifiers in signed TLS leaves, and the sandbox DNS upstream. None is on `main`. Each build PR checks whether `main` still has the defect it touches, and lands the fix as its own small PR if it does.

## Reference page changes

Each PR moves its part into these pages. The specification stays on [Development containers](/reference/development-containers) until its PR lands.

| Page | Change | PR |
| --- | --- | --- |
| [Concepts](/concepts) | Container Instance, default Instance as clone source | 2 |
| [Compute drivers](/reference/compute-drivers) | The container image, the runtime and agent folders in the recipe, the folder update; the static PHP build | 3, 3b |
| [Node provisioning](/reference/node-provisioning) | What the `app-dev` and `app-prod` roles install | 3, 11, 14, 17 |
| [Database servers](/reference/database-servers) | Access from a container, the template clone and the dump path | 5 |
| [Model router](/reference/ai-router) | The forwarder and agent Node admission | Separate PR |
| [Applications](/domains/applications) | Instances in containers, the default Instance as clone source, migration | 4, 5, 10 |
| [Instance releases](/reference/deployments) | Development deployment in the default container; later production releases in containers | 4, 16 |
| [`instance:create`](/cli/instance/create) | The clone path, the first default, the new errors | 4, 5 |
| [Conn layer](/reference/conn) | Registration by the create flow's result, the environment's Instance, the asleep state, `conn.environment_asleep` | 5, 7 |
| [Processes and Schedules](/reference/processes-and-schedules) | Processes as `orbit` units in a container; production Schedules inside | 4, 15 |
| [PHP runtime](/reference/php-runtime) | FrankenPHP and the PHP CLI in containers | 4 |
| [Routes](/reference/routes) | An upstream at a container's bridge address | 4 |
| [Environment variables](/reference/environment-variables) | `.env` in a container, owned by `orbit` with group `work` | 4 |
| [`instance:destroy`](/cli/instance/destroy) | The turn check, clones of a default, the loss of thread history | 6 |
| [App-dev runtime hibernation](/reference/app-dev-runtime-hibernation) | Whole-container sleep after one hour, the wake callers, no pruning | 7 |
| [Tasks](/reference/tasks) | Attaching a task to an Instance; the T3 driver | 9, 12 |
| [Old paths](/reference/old-paths) | `instance:migrate` and checkout Instances until the last one moves; the setup-step commands, `instance:register` and the seed values | 10, 11 |
| [Pi server](/reference/pi-server) | Scope: task VMs only | 12 |
| [`instance:clone`](/cli/instance/clone), [`instance:deploy`](/cli/instance/deploy), [`instance:rollback`](/cli/instance/rollback) | Production Instances and releases in containers | 13 to 16 |
