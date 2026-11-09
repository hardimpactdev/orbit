---
title: "Container runtime"
description: "One build plan for ADR 0250: Instance containers with T3, tasks in them driven by Swamp, the nested fleet, the parity gate, and then development and production. With the decisions, tests, live checks, reuse and open questions."
---

# Container runtime

This is the plan for [ADR 0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers#build-order): Instances in containers with a T3 server inside each, tasks in those containers, the nested fleet, the parity gate, and then the switch of development and production. [Instance containers](/reference/development-containers) specifies the target and its charts. This page orders the work. It is a plan, not a contract: each PR moves the part it builds into the owning reference pages.

There is one container kind and one plan. A task runs in an Instance container, and Swamp drives that container's T3; a user drives the same T3 in development. This plan took in the earlier task container plan. Its PRs 1 and 2 are merged and reused below; its base container, start, resume and release PRs are dropped.

Task work is the critical path. The Swamp migration waits until the [parity gate](#parity-gate) passes, and the CI proof becomes a required check again then. Development-only PRs run beside the task PRs where this page says so.

## Decided

These decisions were made on 2026-11-09. The PRs build on them without asking again.

| Topic | Decision |
| --- | --- |
| One kind | A development container and a task container are one Instance container. Only the driver differs: a user in Conn, or Swamp for a task |
| Agent engine | A T3 server runs as `agent` in every development Instance container. Swamp drives it through T3's own API. No Conn or T3 fork, and no Pi server in a container |
| Pi | The Pi server stays only on today's task VMs, until step 9 removes them |
| Tasks | A task starts in a clone of the Project's default Instance, or attaches to an existing Instance |
| Nested fleet | An optional feature, set per Project: the default Instance and every clone carry it. Orbit's Project turns it on for its command proofs |
| Parity gate | Orbit's command proof cases pass in an Orbit Instance container with the nested fleet on, with the same verdicts as on task VMs |
| Swamp's T3 session | A dedicated `Orbit Swamp` session per Instance, kept in the vault and revoked on its own. PR 8 grants it only the scopes the thread tools need |
| Turn cost | Recorded as null. T3 reports no cost per turn |
| Turn failures | Changed by Nick, after review, 2026-11-09. Swamp translates T3's failure into Orbit's provider error codes, as [the map](/reference/development-containers#provider-errors) says. Retry, assistance and reports stay as today |
| Repeated sends | Swamp relies on T3's idempotent `clientRequestId`, keyed per session, and renews the Swamp session only between turn operations. PR 8 proves it across a T3 restart |
| Pause | An explicit pause and resume on the group. A paused group admits no Swamp turn, and the user drives from Conn |
| Task capacity | Each Node has task and CI place counts, with the queue and two-hour wait of task places. The parity gate's peak memory sets the counts |
| Inner Incus | `agent` joins the inner Incus admin group: root-equivalent inside its own container, with no sudo. `orbit` gets no access |
| Nested-fleet egress | Changed by Nick, after review, 2026-11-09. Nested test Nodes get full public internet access through an [Orbit egress proxy](/reference/development-containers#egress-of-the-nested-fleet). `orbit` keeps the shared package path |
| Task Nodes before the switch | Task clones and container CI proofs run on Beast and Shark, as `app-dev` Nodes on the build list, beside their task VMs |
| Models | Through the per-Node [forwarder](/reference/ai-router#container-model-forwarder) to the model router guard. A container holds only the placeholder key |
| Registration | A step in the Instance-create flow issues and checks the T3 sessions. The Gateway records the server from that step's result |
| New Instances | A new thread's Instance, a task's clone, and every new Instance, is a ZFS clone of the Project's default Instance. Its database is cloned too, with `CREATE DATABASE … TEMPLATE` on the same server |
| Web server | FrankenPHP, in development now and in production once it has proved itself. PHP also works on the command line |
| Nodes | No setting to opt in. Every `app-dev` and `app-prod` Node runs its Instances in containers, and the two roles change what they install |
| Shared runtime | PHP, FrankenPHP, Composer, Node, Bun and the other runtime binaries live once per Node in a versioned read-only folder. Every container mounts it, as the agent folder |
| Container contents | Containers install as little as possible |
| Production | Production Instances move into containers too, after development containers work |
| Sleep | A development container sleeps after one hour idle, never while a task runs in it. Development Instances have no Schedules. Production containers never sleep |
| Setup | There are no development setup steps. Only a Project's first default Instance is built from scratch |
| Default Instance | It deploys every merge to the default branch, so clones match it; a clone installs its own dependency changes. There is no separate source container |
| Git | Orbit fetches and pushes from the Node |
| History | T3 history is deleted with its Instance. Swamp records the transcript of each task turn, so task transcripts outlive a clone |
| Migration | Existing checkout Instances move into containers with their code and database |
| Databases | The database family clones every database: a template copy on the same server, a dump and restore across Nodes. Remote database servers are allowed |
| Pairing token | The pairing token that `t3 serve` prints to its journal is accepted |
| Project files | MCP servers come from the project's own files. The agents use their own subagents only |
| Nested Nodes keep `wg-quick` | The fleet build's `bootcmd` turns off the `wg` and `wg-quick` profiles, and each nested container gets the AppArmor-off device. The shared Node converge does not change |
| `LoadCredential` becomes a file only where it fails | Where a shared renderer fails in the nested fleet, PR 10 changes it, for every Node, to a file that only the unit's user reads |
| A slow restore raises the limit | If the proof PR measures a restore over 45 seconds, the limit becomes the measured 95th percentile plus a margin |
| UpCloud must pass the same gate | It is a verified container host and a target. The [UpCloud report](#upcloud-report) holds the spike's findings |

### The PHP command line

The command line runs a static PHP CLI from the same static build as FrankenPHP, in the runtime folder. A `php` shim around `frankenphp php-cli` does not fit yet: FrankenPHP refuses the `-d` options that Composer and test runners pass. Before PHP 8.6 it also cannot run `php -i` or PHP without a script, and Orbit runs PHP 8.5. One static build gives both binaries the same version and extensions, so web requests and commands behave the same. The shim can replace the CLI once FrankenPHP accepts `-d` and Orbit runs PHP 8.6.

## Rules for every PR

Every build PR keeps these rules, so the work in progress never breaks what runs today. Another thread proves commands on the task VM proof fleet at the same time, so a regression on a task VM host stops its work.

| Rule | How a PR keeps it |
| --- | --- |
| Nothing that runs today changes | Checkout Instances and task VMs keep their behavior. Until PR 12 and PR 16, only Nodes on the build list run container Instances, and only an operator starts a task in one |
| The task VM intents stay the same | A Pest snapshot test per task intent shows that a task VM intent is unchanged, apart from fields the PR names |
| The task VM task list changes only by its includes | A gate test compares `ansible-playbook --list-tasks` of the task VM tags with the base branch |
| A changed command starts with its spec | The first commit updates the command's chart, page and cases. The container charts move from `docs/cli/instance/container/` into place when their kind switches |
| Gateway and Swamp ship together | One PR, as the [contributor guide](/contributor-guide) requires |
| Swamp has the shape | Ansible phases do the machine work, steps call the Gateway only to sign or mint, secrets come from the vault. `bin/swamp-shape-gate` passes |
| `incus` gets no stray input | Every task and script that runs `incus` closes standard input, which `incus` reads as YAML when it is not a terminal |
| No Orbit or provider secret enters a container | The placeholder model key and the Instance's own `.env` are all it holds. Git tokens stay on the Node; the T3 sessions go through the vault |
| The checks pass | `bin/swamp-shape-gate`, `bin/check-flows`, `composer docs-lint` and the tests of each changed project |
| Live checks run on Beast | Beast is on the build list as an `app-dev` Node, with a test Laravel Project and, from PR 10, Orbit's own default Instance. Beast's task VMs and checkout Instances keep their work |
| VM hosts prove themselves after the merge | The next release refreshes every task VM host. A failed refresh keeps the host's base, and the PR is reverted |
| Docs move with the code | A PR moves the part it builds from [Instance containers](/reference/development-containers) into the owning page |

The build list, the Gateway setting `instances.container_nodes` (`ORBIT_INSTANCES_CONTAINER_NODES`), exists for the build only. It is not a Node setting, and PR 16 removes it.

## Before the first build PR

These come from other work and must land first.

- **The model forwarder and guard change.** [#3290](https://github.com/hardimpactdev/orbit/pull/3290) built the per-Node forwarder and lets the model router guard accept agent Nodes and the `?beta=true` query. Merged.
- **Container egress for dependencies.** [Step 2c of the rollout](/solutions/agent-runtime-and-manual-mode) names the package path that dependency installs use. PR 4 needs it. The image build of PR 3 has its own way out, for its build container only.
- **Pairing works.** The spike's pairings failed at the vault step. The pairing fix on `main` must pair with a container's server before PR 5 ends.

## Build PRs

The PRs land in this order, and PR 3b before PR 4. The task track, PRs 7 to 12, is the critical path. PR 13 and PR 14 can run beside it after PR 6.

| PR | Part | State |
| --- | --- | --- |
| 1 | The specification | Merged, and replanned by this page |
| 2 | Runtime records and the build list | Merged, [#3291](https://github.com/hardimpactdev/orbit/pull/3291) |
| 3 | Node preparation and the shared folders | Merged, [#3292](https://github.com/hardimpactdev/orbit/pull/3292) |
| 3b | The static PHP build | Open, [#3294](https://github.com/hardimpactdev/orbit/pull/3294) |
| 4 | The first default Instance and its deployments | |
| 5 | Create an Instance as a clone, with T3 and its sessions | |
| 6 | Destroy a container Instance | |
| 7 | Run a task in a clone: start, check, publish, end | |
| 8 | The `t3` task driver: Swamp drives T3 | |
| 9 | Attach a task to an Instance | |
| 10 | The nested fleet | |
| 11 | Command proofs in an Instance container | |
| — | [Parity gate](#parity-gate) | |
| 12 | Switch task work | |
| 13 | Sleep and wake | |
| 14 | Start a thread in an Orbit Project (Conn) | |
| 15 | Migrate checkout Instances | |
| 16 | Switch every `app-dev` Node | |
| 17 | Remove task VMs, the Pi server and the task container setting | |
| 18–22 | [Production Instances](#production-instances) | |

### PR 1: the specification

ADR 0250 records the decisions, [Instance containers](/reference/development-containers) specifies the target, the charts state the flows, and this page orders the work. No code. The replan that merged the task container plan into this one is also a PR with no code.

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

Merged as [#3292](https://github.com/hardimpactdev/orbit/pull/3292). This PR prepares a Node; it creates no Instance.

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

Tests: the Ansible fixture tests for the Instance host phase and the task container host phase, which share their tasks; Pest for the recipe pins, the intent, the image files, the notes, the firewall catalog and the bridge transactions. Live on Beast:

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

Open as [#3294](https://github.com/hardimpactdev/orbit/pull/3294). This PR adds PHP and Vite+ to the runtime folder; it creates no Instance. No published release gives FrankenPHP and a PHP CLI from one build, so Orbit builds them. [Static PHP build](/reference/compute-drivers#static-php-build) holds the contract.

- The recipe's `instances.php` section defines one static-php-cli build per PHP version: PHP 8.5.12, FrankenPHP 1.13.5 and a pinned static-php-cli commit.
- The extensions are the PHP build's, less `ffi` and `pcov`. A fully static binary cannot load them.
- `bin/static-php-build` builds `php` and `frankenphp` together and checks both against the extension list.
- The `Static PHP build` workflow builds and proves each new definition. On main it publishes the archive as a release.
- A second reviewed commit records the release's SHA-256. From then on the runtime folder holds the build as `php/<php-version>/bin`, and its install checks both binaries with the Ansible module `orbit_static_php`.
- The same pin PR names PHP and Composer in the environment notes. Until then the notes name Node, npm, Bun, Vite+ and rg.
- The runtime folder gets Vite+: the part `vite-plus`, the recipe's `vp-cli` release binary as `vp`. The image sets `VP_NO_UPDATE_CHECK=1` for units and login shells.

Tests: Pest for the pins, the folder parts and the image files; Python tests for the build script and the check. In CI, the workflow's proof job:

1. Both binaries are fully static and load exactly the listed extensions, at the pinned PHP release.
2. `php -v`, `php -i`, `php -m` and `php -d memory_limit=1G` work.
3. Composer installs the Gateway with its scripts, and Artisan and `php -d memory_limit=1G vendor/bin/pest` run.
4. `frankenphp php-server` serves the Gateway's `/up`.

Live on Beast, after the pin PR: `php -i`, a `composer install` with scripts and `vp --version` as `orbit` in a test container, and the folder install's extension check.

### PR 4: the first default Instance and its deployments

The spec commit adds [container/create-default.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/create-default.mmd) and [container/deploy-default.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/deploy-default.mmd) to the create and deployment pages and cases.

- The first default Instance of a Project on a Node is a container from the image, with the default branch pushed in from the Node.
- The database family creates its database. Orbit writes `.env` and runs the development deploy steps as `orbit`.
- FrankenPHP and the other development Processes as `orbit` units in the container; the Route to FrankenPHP at the container's bridge address.
- The automatic development deployment of each merge runs in the default container, and starts a sleeping one first.

Tests: Pest for the intent and projection of both flows; Ansible `unit` cases; the cases of both. Live on Beast:

1. The default Instance answers on its Route, served by FrankenPHP as `orbit`, with the PHP Data Bridge toolbar.
2. A merge to the default branch deploys, and the Instance serves the new commit.
3. A merge that changes `composer.lock` installs the change through the deploy steps.
4. T3 does not run in the default container.
5. The PR records the build and deployment times.

### PR 5: create an Instance as a clone of the default

This PR changes `instance:create`, so its spec commit updates the create page and cases with [container/create.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/create.mmd) and [container/t3-register.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/t3-register.mmd).

The create flow gets these parts:

- The clone of the default container and the database clone through the database family: a template copy on the same server, a dump and restore across servers.
- The branch from the Node, the workspace, `.env`, the agent settings, the note links and `/etc/orbit/instance`.
- The first start of T3, the Processes and the Route.
The registration and the container's paths:

- The registration step and the Gateway's record of the server from its result. The record names the Instance.
- The `Orbit Swamp` session beside the Gateway's, kept in the vault, and the T3 project at `/srv/workspace`.
- The lifecycle contributions reused from `task-4193` add a plug-in point, so this PR follows [Add a plug-in point](/reference/extensions#add-a-plug-in-point).
- The container's NIC rule to its Instance's database server and port.
- The T3 proxy device and its route rule.
- The daily renewal run.

Tests: Pest for the intent, the projection, the registration record from a result, `instance.default_missing`; Ansible `unit` cases; tests of the template clone and of the dump path; the `instance:create` cases for a container Node. Live on Beast:

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

Tests: Pest for the refusals, `instance.default_has_clones`, the record removal and the port release. Live on Beast:

1. Destroy refuses during a running turn, and with `--force` removes the Instance.
2. The paired Mac loses the server at once.
3. The container, the database, the proxy device and the firewall rules are gone; the default Instance and the other clones work.
4. Destroying the default Instance refuses while it has clones.

### PR 7: run a task in a clone

This PR runs a task group in an Instance container, without agent turns yet. It changes `task:start`, `task:check`, `task:publish` and the group's end, so its spec commit adds their container charts beside the task VM charts, with their cases.

- **Admission.** An operator `task:start --runtime=container` on a group whose Project has an active default Instance on a Node of the build list. Normal task placement stays on task VMs until PR 12.
- **Start.** `tasks.provision` runs the create flow of PR 5 for the Instance `task-{id}` on branch `task-{id}`, with the task as owner.
- **No task VM.** The group gets no task VM, no Node identity, no WireGuard address and no Pi server.
- **Capacity.** The Node's task places count running task clones, as [Decided](#decided) sets.
- **Workspace actions.** The checks, the Git evidence and the receipt run from the Node through `incus exec` as `agent` in `/srv/workspace`. Swamp reaches the Node over SSH.
- **Publish.** Orbit reads the branch out of the container on the Node and pushes it with a minted write token.
- **End.** Cancel, complete and the sweep destroy the owned clone through the destroy flow of PR 6.
- **Egress log.** The Node's agent observes the Instance bridge as it observes the task container bridge of [#3279](https://github.com/hardimpactdev/orbit/pull/3279), so `task:egress` covers a task in a clone.

Tests: Pest for the admission by runtime, the provision, check, publish and end intents of a container group, the unchanged task VM snapshots, and the owner rules; Ansible fixture tests for the workspace actions in a container. Live on Beast:

1. A test group starts in a clone of the test Project's default Instance.
2. Its baseline check passes in the container, and a handoff check runs on a commit made by hand.
3. A publish pushes `task-{id}` and opens a draft pull request.
4. Complete destroys the clone, its database and its Route; the default and other clones keep working.
5. `task:egress` lists the clone's outside connections.
6. The PR records the start time from admission to a ready workspace.

### PR 8: the `t3` task driver

This PR lets Swamp drive a container's T3 for `task:turn`, as [Swamp drives T3](/reference/development-containers#swamp-drives-t3) specifies. The spec commit updates the `task:turn` chart and cases with a container branch.

- **Gateway.** The task driver `t3` for groups in a container. Its thread row records the T3 thread id, the Node and the driver. It accepts Claude models.
- **Pi.** The `pi` driver stays for task VM groups.
- **Swamp.** The container branch of `operation-task-turn` with the calls of the contract: `t3_project_list`, `t3_thread_launch`, `t3_thread_send`, `t3_thread_wait`, `t3_thread_read` and `t3_thread_interrupt` over MCP, and `orchestration.getThreadProjection` and `orchestration.forceStopThread` over the WebSocket.
- **Projection.** State, the provider error, token metrics and the redacted transcript, by the mappings of the contract.
- **Sessions.** The step reads the Instance's `Orbit Swamp` session from the vault.

Tests: Pest for the driver selection and the state map. Pest for the provider error map: a fixture per adapter code, a Claude 429 with a far `resetAt`, and a code with no mapping. Pest for the metric map with `partial` and `unavailable` usage, and the Claude admission. Swamp tests run the driver against a T3 server in a test container: a repeated `clientRequestId`, a lost wait, a T3 restart in a turn and a cancel. Live on Beast, in a task clone:

1. An implementer turn with a Codex model and one with a Claude model edit files and write receipts.
2. A reviewer turn on a handoff check runs, and Orbit resets the workspace after it.
3. `systemctl restart t3` during a 40-second tool call: the turn continues, and the thread ends `done`.
4. A cancelled turn stops in the container.
5. `task:agent:show` shows the recorded transcript and metrics after the clone is destroyed.
6. The same T3 thread shows the turns in Conn while the clone lives.

### PR 9: attach a task to an Instance

This PR lets a task drive an Instance that a user also drives.

- A task group can name an existing Instance of its Project. The task does not own it, and the group's end leaves it running.
- Its turns go to that Instance's T3 through the driver of PR 8, with `queue`.
- The task's check and publish run in the Instance's container.
- `task:pause` and `task:resume` on the group. A paused group admits no Swamp turn; the user drives from the thread.

Tests: Pest for the attach admission, the ownership rules and the end without destroy. Live: attach a task to an Instance, see its turns in the Conn thread, pause it, send a turn as the user, resume it, and end the group with the Instance still running.

### PR 10: the nested fleet

This PR adds the [nested fleet](/reference/development-containers#nested-fleet) as an optional feature. It reuses the nested topology of the earlier task container plan; the default Instance takes the place of the base container.

- **Setting.** A Project setting turns the nested fleet on. Orbit's own Project turns it on.
- **Node.** The `app-dev` converge adds an Incus project for nested-fleet containers. It allows nesting, interception, isolated ID maps and low-level keys.
- **IDs.** Root's subordinate IDs on the Node hold one isolated range per nested-fleet container.
- **Default Instance.** The create and deploy flows of a nested-fleet Project build the inner Incus, its directory pool `topology-seed`, the network `oe-nested` and the fleet once.
- **Ready copies.** The default keeps the three nested containers stopped, each with its `base` snapshot.
- **Clone.** A clone carries the fleet. The create flow starts its nested containers, brings up `orbit-wg-nested.service` and checks the hub handshake.
- **Refusals.** The create flow refuses a `raw.*` key and any device beyond the root disk, the NIC, the shared folders and the T3 proxy.
- **Fleet rules.** The AppArmor-off device on each nested container, and a fleet check that fails on any `LoadCredential` unit.

The PR also adds the Node's [egress proxy](/reference/development-containers#egress-of-the-nested-fleet): the Process on the Node, the bridge ACL and input rules for a nested-fleet container, the container's forward-only rule, the proxy's egress facts, and the proxy settings of the nested Nodes.

Tests: Pest for the setting, the project choice, the refusals and the proxy rules; Ansible fixture tests for the inner Incus, the tunnel unit, the fleet check and the proxy settings. Live on Beast:

1. Orbit's default Instance builds with the fleet, from scratch.
2. A clone of it starts, and `zfs get written` shows only a few MB.
3. In the clone, each nested container shows `N` at `/sys/module/apparmor/parameters/enabled`, no unit has `LoadCredential`, and the nested hub handshake is recent.
4. As `agent`, `incus list` shows the fleet; as `orbit`, it is refused.
5. A container of the test Project has no nesting and `user.max_user_namespaces=0`.
6. The PR records the clone, nested start and `base` times.
The egress proxy checks follow.

7. A nested Node fetches a public package through the proxy, and the proxy logs the connection.
8. A nested Node with the proxy unset reaches nothing outside, and `orbit` cannot reach the proxy.
9. The proxy refuses `CONNECT` to the Gateway, to `127.0.0.1` and to a `.orbit` name.

### PR 11: command proofs in an Instance container

This PR makes the proof flows run on a nested fleet in an Instance container. It changes `proof` and `proof:run`, so its spec commit updates their charts, pages and cases.

- `proof.verify` runs on the fleet of a task group's Instance container, as it runs on a task VM's fleet today.
- The step "Take base again" stops the nested containers before the snapshot.
- `proof:run` gets the operator options `--node=<node>` and `--runtime=<vm|container>`. With `container`, each shard runs in its own clone of Orbit's default Instance on that Node, destroyed after the shard.
- A `ci-runner` caller cannot use either option until PR 12.

Tests: Pest for the admission of the options and the proof intents; `swamp workflow validate`. Live: `orbit proof firewall:allow --group <group>` on a group in an Orbit clone on Beast, and a short `proof:run --node=beast --runtime=container` with two cases. The PR records the restore times. If their 95th percentile is over 45 seconds, the limit becomes that percentile plus a margin, and the PR says which.

### Parity gate

Step 6 of ADR 0250 passes when every part below holds. All of it runs on Beast. The task VM half takes Beast's VM task places, so it runs while no other proof uses them.

#### Proof cases

Both runs prove the same commit of `main`, picked after PR 11.

1. Run `proof:run --all --node=beast --runtime=container` for that commit. Every case runs in a clone of Orbit's default Instance with the nested fleet on.
2. Run `proof:run --all --node=beast --runtime=vm` for the same commit.
3. Compare the verdicts per case. Every case that passes on a task VM must pass in the container.
4. A case that fails on both runtimes is a case defect and does not block.
5. A case that fails only in the container blocks. After its fix, the whole container run passes again.

#### Real tasks

Real task work runs in clones and in an attached Instance.

1. One real Orbit task group runs in a clone and publishes a pull request.
2. Its turns include a Codex and a Claude implementer turn, a handoff check and a reviewer turn.
3. Its threads show the right states, metrics and transcripts.
4. One T3 restart during a turn does not end the turn.
5. A task attached to an Instance turns, pauses for a user turn in Conn, and resumes.

#### Record

The case table goes on this page, with the times of a start, a restore and a default deployment on both runtimes. The peak memory of one nested-fleet clone goes there too; it sets the Node's task and CI places.

UpCloud passes the same gate before it runs task work.

After the gate, the Swamp migration resumes, and PR 12 makes the CI proof run in containers and a required check again.

### PR 12: switch task work

This PR moves task work from task VMs to Instance containers, Beast first, then Shark, each in a window with no proofs or merges in flight. Running task VMs finish first.

- Normal task placement starts every new group in a clone, on Nodes with places for it.
- CI proof shards run in clones of Orbit's default Instance; the `ci-runner` caller gets the container runtime.
- The CI proof becomes a required check again.
- The host's task VM places go to zero.

Tests: Pest for placement by runtime and the CI proof admission. Live: a normal `task:start` lands in a clone on Beast, and a pull request's CI proof passes in containers. Then the same on Shark.

### PR 13: sleep and wake

The spec commit adds [container/sleep.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/sleep.mmd) and [container/wake.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/wake.mmd) to the hibernation page.

Tests: Pest for the sweep's choices, the wake admission by caller, and the joined wake. Live on Beast:

1. An Instance idle for an hour stops, keep-alive Processes and all; one with a running turn or a running task flow does not.
2. A user who keeps an open thread active in Conn, without a request to the site, keeps the Instance awake.
3. The PR names the T3 read that gives the last client activity. Without one, the PR stops and asks for a decision.
4. A request to its Route shows the progress page and then the app, within 5 s.
5. Conn opening the sleeping server wakes it.
6. A session within seven days of its expiry is renewed at the wake.

### PR 14: start a thread in an Orbit Project (Conn)

This is a Conn PR, with the Orbit parts it needs. It follows [container/thread.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/thread.mmd).

- Conn lists the Orbit Projects that the device can reach.
- A thread in one of them creates an Instance named from the first message, pairs with it and sends that message.
- Conn shows a sleeping Instance's server as asleep and wakes it on open.

Tests: Conn tests for the Project list, the create-then-pair flow, the queued send and the asleep state; Pest for any Orbit endpoint it adds. Live: a thread started on the Mac in an Orbit Project ends in a new clone with its first turn answered.

### PR 15: migrate checkout Instances

The spec commit adds [container/migrate.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/migrate.mmd), the page of the temporary command `instance:migrate` and its cases. [Old paths](/reference/old-paths) lists the command until the last checkout Instance has moved.

- The command moves one checkout Instance into a container: the checkout as it is, its database cloned through the database family, its Route moved.
- A default Instance moves before the other Instances of its Project, and keeps its checkout until its last worktree has moved.
- A non-default checkout is a linked worktree, so the move builds a standalone repository from the Node and copies only the uncommitted and ignored files.
- A non-default Instance before its default refuses with `instance.default_not_migrated`.
- A failure leaves the checkout Instance running as before.

Tests: Pest for the admission, the default-first rule and the projection; Ansible `unit` cases; the cases. Live:

1. On Beast, a checkout default and one checkout feature with uncommitted files move.
2. Both answer on their Routes, with the uncommitted files and matching data.
3. After the default has moved, a feature still on its checkout keeps working until its own move.
4. A migration that fails at the database clone leaves the checkout Instance serving.
5. On every `app-dev` Node, each checkout Instance moves, default first, in a window agreed with the people who use them.

### PR 16: switch every app-dev Node

This PR ends the build list.

- It needs every development checkout Instance moved, on every `app-dev` Node.
- Every `app-dev` Node converges the container setup, and the role stops installing PHP-FPM and checkout folders there.
- The build list goes; `instance:create` makes containers on every `app-dev` Node.
- `instance:clone` reads its candidate from the candidate's container, so production cloning keeps working until PR 20.
- The commands that only checkouts need go: `instance:setup`, the `instance:setup-step:*` commands, `instance:register` and the seed values `ORBIT_SEED_PATH` and `ORBIT_SEED_COMMIT`. [Old paths](/reference/old-paths) lists them.
- Teardown steps stay.
- `instance:transfer` refuses a container Instance until it gets a container path.
- The container charts replace the checkout charts of the same kinds; the first-default and development deploy charts become branches of the create and deploy charts.

Tests: Pest for create without the build list, the candidate read of `instance:clone`, and the removed commands; the role's Molecule cases without PHP-FPM. Live: every `app-dev` Node converges twice without change, a create on each makes a clone, and `instance:clone` makes a production Instance from a container candidate.

### PR 17: remove task VMs, the Pi server and the task container setting

Step 9 of ADR 0250. It needs PR 12 on every task host and PR 16.

- The task VM runtime goes. That is its provision, image, snapshot, power, resume and release workflows, and the base VMs and seeds.
- The `pi` driver, the Pi server, `apps/pi-server` and the Pi artifact pins go.
- The task container setting of [#3273](https://github.com/hardimpactdev/orbit/pull/3273) goes: `settings.task_vms.containers`, `task_vms.container_placement`, the `runtime` columns and the `orbit:task-containers-*` input rules.
- Its host preparation stays, as the container host part of the `app-dev` role.
- [Old paths](/reference/old-paths) lists each removed path until this PR lands.

Tests: Pest for the removed admissions and settings; the task VM tests go with their code. Live: every task host converges without task VM parts, and a task and a CI proof still run in containers.

## Production Instances

Step 10 of ADR 0250 moves production Instances into containers on every `app-prod` Node, after PR 16. The [container plan](/solutions/container-instances-and-releases#production-design) holds the release design that these PRs specify and build.

### Production gate

FrankenPHP reaches production only after it has proved itself in development. This plan proposes the criteria below; they are confirmed before PR 18. The development Instances on FrankenPHP meet them for four weeks:

- No failure that comes from FrankenPHP itself and that PHP-FPM would not have.
- The PHP Data Bridge toolbar and every development Process work as they did on PHP-FPM.
- The median and 95th percentile response times of the default Instances are no worse than on PHP-FPM, measured before and after migration.

When the gate fails, the production web server is decided again before PR 18.

### PR 18: production specification

The release flow in containers: preparation container, candidate clone, activation, readiness, Route switch, drain and retained releases, with FrankenPHP and Schedules inside. The charts of `instance:clone`, `instance:deploy`, `instance:rollback` and the production migration, with their pages and cases. A release pins the runtime folder version it was built with, so a new runtime version reaches production only through a deployment. No code.

### PR 19: prepare an app-prod Node

This PR prepares a Node; it moves no Instance.

- A container branch of the `app-prod` role converge, with the runtime folder and no agent folder.
- The production container image: the user `orbit` only, no T3 unit.

Tests: Molecule `unit` cases and `ansible-lint`. Live on a test `app-prod` Node: the converge runs twice without change; a test container has no `agent` user and no agent folder.

### PR 20: a production Instance in a container

This PR serves one production Instance from a container.

- `instance:clone` makes a production Instance's container from its candidate.
- FrankenPHP serves it, and it never sleeps.
- Schedules stay native Swamp schedules that run their command in the container as `orbit`; the per-minute Laravel scheduler runs inside it.

Tests: Pest for the intent and projection; the cases. Live: a test Laravel Project serves in production from a container, a Schedule runs on time, and the hibernation sweep leaves the container running after an idle hour.

### PR 21: releases and rollback

This PR deploys and rolls back in containers.

- `instance:deploy` builds a release in the preparation container and clones it as the candidate.
- It activates the candidate, checks readiness, switches the Route and drains the old release.
- `instance:rollback` selects a retained release.

Tests: Pest for the release records and the switch projection; the cases. Live:

1. A long request is open. A deploy with a dependency change and a migration starts.
2. The long request finishes on the old release; new requests reach the new one.
3. A bad candidate stays out.
4. A rollback serves the old code.

### PR 22: migrate production Instances and switch app-prod Nodes

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
- The task park and prep changes, which belong to other work. Its proof fleet changes are replaced by PR 10 and PR 11.

Some commits fix shared code: reading an empty Swamp projection, reading rows from select queries, Route workflows without probes, key identifiers in signed TLS leaves, and the sandbox DNS upstream. None is on `main`. Each build PR checks whether `main` still has the defect it touches, and lands the fix as its own small PR if it does.

## Reuse from the task container track

The earlier task container plan built two PRs. This plan keeps what fits one container kind.

| Part | Built in | Reuse |
| --- | --- | --- |
| Host preparation: Incus from the recipe, SCTP check, sysctls, subordinate IDs, ARC cap, project, bridge, ACL, profile, stock image, input rules and the clone probe | [#3279](https://github.com/hardimpactdev/orbit/pull/3279) | PR 3 runs these tasks as the container host part of the `app-dev` converge |
| One egress observer per bridge in the Node agent | [#3279](https://github.com/hardimpactdev/orbit/pull/3279) | PR 7 points it at the Instance bridge |
| The nested topology: inner Incus, directory pool, AppArmor-off device, `wg-nested` with `ip` and `wg`, the `LoadCredential` rule, `base` with stopped containers | Specified, not built | PR 10 and PR 11 |
| `settings.task_vms.containers`, its places, `task_vms.container_placement`, the `runtime` columns, the container bridge and its input rules | [#3273](https://github.com/hardimpactdev/orbit/pull/3273), [#3279](https://github.com/hardimpactdev/orbit/pull/3279) | Not used. PR 17 removes them |

Dropped from that plan: the base container and its refresh, the start by copy of a base snapshot, the resume of a stopped container, the Node identity of a task container, its Pi server, and the five task container charts.

## UpCloud report

A spike on 2026-11-09 ran the nested topology on an UpCloud server, which has no nested KVM. Verdict: it works with changes. UpCloud is a verified container host and a target: an UpCloud host passes the same parity gate before it runs task work.

The spike used `STARTER-4xCPU-8GB` with a separate 20 GB block device for the pool. It ran the outer container, the inner Incus on a directory pool, Docker and WireGuard in nested containers, and the stop, snapshot and copy of the outer container.

| Step | Time |
| --- | --- |
| Outer snapshot, stopped | 0.15 s |
| Outer copy | 0.57 s |
| Copy start to nested systemd ready | 5.3 s |
| Take nested `base` (stop, snapshot, start) | 21.4 s |
| Nested restore, 500 MB changed / small change | 3.9 s / 2.9 s |

| Finding | Change |
| --- | --- |
| A restricted project refuses `security.idmap.size` | `restricted.containers.lowlevel=allow`, and the create flow refuses `raw.*` keys |
| Incus 7 reports empty `kernel_features` | A test copy with `zfs get written` under a few MB replaces the `idmapped_mounts` check. The copy wrote 300 KB |
| `wg-quick` segfaults in the outer container | `ip` and `wg` only for the outer container. Nested Nodes keep `wg-quick`, which works with the AppArmor-off device |
| `LoadCredential` gives the unit no credential and no error | The rule stays: `SetCredential` or a file |
| The template kernel lags and SCTP loads | Host preparation needs a full upgrade and reboot, the SCTP block, a `zfs_arc_max` cap and, on UpCloud, a separate pool device |
| Zabbly stable is 7.6, Beast runs 7.0.1 | The recipe pins one Incus channel for host and inner Incus |
| A directory pool copied the outer container in 55 s, btrfs moved it in 171 s | The host pool must be ZFS |
| Nested restores took 3–4 s | The 45-second limit holds |
| `incus` reads non-terminal standard input as YAML | Ansible tasks and Swamp scripts close standard input |

An idle outer container with two nested containers and Docker used about 0.5 GB, and 2.5 GB of disk. The real fleet is heavier, so one nested-fleet Instance gets `STARTER-4xCPU-16GB` with a 50 GB pool disk until the parity gate measures one.

## Reference page changes

Each PR moves its part into these pages. The specification stays on [Instance containers](/reference/development-containers) until its PR lands.

| Page | Change | PR |
| --- | --- | --- |
| [Concepts](/concepts) | Container Instance, default Instance as clone source, the two drivers | 2, 7 |
| [Compute drivers](/reference/compute-drivers) | The container image, the runtime and agent folders in the recipe, the folder update; the static PHP build; later the task VM runtime goes | 3, 3b, 17 |
| [Node provisioning](/reference/node-provisioning) | What the `app-dev` and `app-prod` roles install; the nested-fleet project | 3, 10, 16, 19, 22 |
| [Database servers](/reference/database-servers) | Access from a container, the template clone and the dump path | 5 |
| [Applications](/domains/applications) | Instances in containers, the default Instance as clone source, migration | 4, 5, 15 |
| [Instance releases](/reference/deployments) | Development deployment in the default container; later production releases in containers | 4, 21 |
| [`instance:create`](/cli/instance/create) | The clone path, the first default, the new errors | 4, 5 |
| [Conn layer](/reference/conn) | Registration by the create flow's result, the Swamp session, the environment's Instance, the asleep state, `conn.environment_asleep` | 5, 13 |
| [Processes and Schedules](/reference/processes-and-schedules) | Processes as `orbit` units in a container; production Schedules inside | 4, 20 |
| [PHP runtime](/reference/php-runtime) | FrankenPHP and the PHP CLI in containers | 4 |
| [Routes](/reference/routes) | An upstream at a container's bridge address | 4 |
| [Environment variables](/reference/environment-variables) | `.env` in a container, owned by `orbit` with group `work` | 4 |
| [`instance:destroy`](/cli/instance/destroy) | The turn check, clones of a default, the loss of thread history | 6 |
| [Tasks](/reference/tasks) | Tasks in a clone or an attached Instance; the `t3` driver and Claude for task agents; later the `pi` driver goes | 7, 8, 9, 17 |
| [Node agent](/reference/node-agent) | The egress observer on the Instance bridge | 7 |
| [`proof`](/cli/proof/proof), [`proof:run`](/cli/proof/run) | Proofs in an Instance container, `--node` and `--runtime` | 11, 12 |
| [Node settings](/reference/node-settings), [Gateway environment](/reference/gateway-environment) | Task places per Node; the task container setting and `task_vms.container_placement` go | 7, 17 |
| [App-dev runtime hibernation](/reference/app-dev-runtime-hibernation) | Whole-container sleep after one hour, the wake callers, no pruning, no sleep during a task | 13 |
| [Old paths](/reference/old-paths) | `instance:migrate` and checkout Instances until the last one moves; the setup-step commands, `instance:register` and the seed values; task VMs and Pi | 15, 16, 17 |
| [Pi server](/reference/pi-server) | Removed | 17 |
| [`instance:clone`](/cli/instance/clone), [`instance:deploy`](/cli/instance/deploy), [`instance:rollback`](/cli/instance/rollback) | Production Instances and releases in containers | 18 to 21 |
