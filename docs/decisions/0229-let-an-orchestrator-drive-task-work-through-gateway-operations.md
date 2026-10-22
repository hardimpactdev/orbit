---
title: "ADR 0229: Let an orchestrator drive task work through Gateway operations"
sidebarTitle: "0229 Orchestrator operations"
description: "In progress. An external orchestrator owns the task lifecycle; the Gateway performs each effect as an idempotent operation; Tasks keeps the board, records, and agent sessions."
---

# ADR 0229: Let an orchestrator drive task work through Gateway operations

An external orchestrator owns the lifecycle of task work: its stages, gates, budgets, approvals, and every state change. The Gateway performs each side effect as an operation that takes an `Idempotency-Key`. Tasks keeps the board, the task records, assistance, and the agent sessions. Once agent turns move to Gateway operations too, the state machine in `tasks:tick` retires, and Orbit no longer runs a workflow engine of its own.

## Status

In progress.

The shared operation-start idempotency key is built for deploy and rollback. [Operations](/reference/operations#retry-a-start-with-a-key) owns its contract and reasons. Development Instance publish is built; [GitHub App](/reference/github-app#publish-a-development-instance) owns its contract and reasons. The workspace, command, and task steps remain.

Nick decided this on 2026-10-22.

Principle: [Lean](/mission#principles). Orbit stops owning a hand-rolled workflow engine. A general orchestrator holds the lifecycle as data, and Orbit keeps only the effects that it alone can perform. It also serves [Deterministic first](/mission#principles), because stages, gates, and budgets become declared data with typed records. Exceptions to [One way, one name](/mission#principles) and [No exceptions and no legacy](/mission#principles): while this ADR is being built, task work has two drivers, `tasks:tick` and an orchestrator, and Orbit keeps the tick's engine until its replacement works. Both exceptions end when step 3 removes the state machine from `tasks:tick`.

## Context

Tasks drives its work with an engine of its own, and the Gateway offers no effect that an outside orchestrator can call.

### Tasks runs its own engine

`tasks:tick` claims groups, runs baseline and handoff checks, and sends implementer and reviewer turns. It counts reminders, consults, and failed checks, and it raises assistance ([Tasks](/reference/tasks)).

### A spike drove the loop from outside

A spike with the Swamp workflow engine drove the same loop from outside. It ran Orbit dependency upgrades as work items of a Swamp `software-factory` definition. The definition expressed the consult limit, the failed-check budget with its reset on a pass, cycle limits, and operator overrides as stages and gates. The spike ran `packages/php-sdk` and `apps/gateway` to approval. It survived SIGKILL tests without a duplicate commit or dispatch. Its effects ran on a scratch checkout, because the Gateway has no route for them.

### What the Gateway lacks

The Gateway has no route to run a command in an Instance. Instance create takes a branch but no commit. For a monorepo, the dependency update runs at the repository root only, and per-sub-project updates are out of scope ([Instance dependencies](/reference/instance-dependencies#update-a-development-instance)). No route pushes a commit as a branch or pull request.

Only the WebSocket app routes accept `Idempotency-Key` today. Their key is scoped to the calling Node, and a conflict is `websocket.idempotency_conflict` ([WebSocket apps](/reference/websocket-apps)).

### Decisions this builds on

[ADR 0209](/decisions/0209-run-long-operations-as-durable-queued-operations) plans to make `instance.create` an operation, and [ADR 0218](/decisions/0218-take-long-commands-off-the-request-path) plans `instance.setup` and `instance_dependencies.update`. ADR 0218 also keeps only the newest 50 finished operations of each target. Today only `instance.deploy` and `instance.rollback` are operations. One job runs a whole operation and holds its target, an operation runs at most 1,600 seconds, and the `operations` queue has one worker ([Operations](/reference/operations#how-an-operation-runs)).

[ADR 0224](/decisions/0224-give-each-task-group-one-holder-and-one-sender) makes `tasks:tick` the only place that starts an actor or sends a turn. It keeps detached supervision only for checks and topologies. [ADR 0225](/decisions/0225-make-node-changes-through-agent-commands) moves Node execution into typed Rust agent commands and freezes Gateway scripts. Its runtime build starts after ADR 0224 is built, and it claims no exactly-once execution.

### People need to see the work

The board shows which work is in the backlog, ready, in progress, and done. A person can open a task and read its agent sessions. That view stays useful whoever drives the work.

## Decision

The orchestrator decides what happens next, and the Gateway does it. Three steps build this: workspace and command operations, then publish, then orchestrated task records with agent turns.

### Who owns what

- The orchestrator owns the lifecycle and every state change.
- The Gateway owns every effect. A key starts each operation once, and a step that cannot run again safely never runs twice.
- Tasks owns the visible record: board states, task records, assistance, and agent sessions.
- The operations are orchestrator-neutral. Any caller with the needed [access grant](/cli/node) can use them.
- An orchestrator never runs a command, an agent, or a Git push itself.

### Workspaces

In steps 1 and 2, a work item's workspace is a development Instance on an `app-dev` Node. Its work is Composer updates, checks, and tests, and publishing a commit. In step 3, an agent turn runs in an isolated task VM per work item, as task agents do today ([Tasks](/reference/tasks)).

### One idempotency key for every operation start

A caller may send an `Idempotency-Key` header to any route that starts an operation. The header has 1 to 128 characters from `A-Z a-z 0-9 . _ : -`.

- The key is scoped to the operation kind, not to the calling Node, so an orchestrator that moves to another Node keeps its keys.
- The Gateway stores the key on the operation, with a unique index on kind and key. This amends ADR 0218's retention: an operation that carries a key is never pruned, so a key never expires.
- The Gateway checks the key before the request validates further or creates a target, so a replay of `instance.create` creates nothing.
- A repeat with the same key, the same target, and the same input answers `202` with the existing operation, whatever its status.
- A repeat with the same key and a different target or input answers `409 operation.idempotency_conflict` and starts nothing.
- The CLI passes the key with `--idempotency-key`, and each MCP tool that starts an operation takes an `Idempotency-Key` argument.
- The WebSocket app routes move to this rule when ADR 0218 makes their changes operations. Until then they keep their own rule.

An orchestrator derives the key from its work item, stage, and cycle, such as `bump-apps-gateway.handoff.c2`.

### Step 1: workspace and command operations

Step 1 builds after ADR 0209 and ADR 0218 make Instance create, setup, and the dependency update operations, and after ADR 0225's agent command foundation, which itself follows ADR 0224.

| Operation | Change | Refusal |
| --- | --- | --- |
| `instance.create` | Adds `base_commit`, a full SHA reachable from the Project remote. The new branch starts at it instead of at the fetched default branch | See below |
| `instance.setup` | None | — |
| `instance_dependencies.update` | Adds `directory`, a monorepo sub-project that the [monorepo scan](/reference/instance-dependencies#monorepo-sub-projects) qualifies. This reverses "per-sub-project updates are out of scope" | `422 dependencies.directory_unsupported` for a Project that is not a monorepo; `422 dependencies.directory_not_qualified` for a directory the scan does not qualify |
| `instance.run` | New, below | — |

`instance.create` checks `base_commit` in two places. When the operation starts, it answers `422 instance.base_commit_default` for the `default` Instance and `422` for a malformed SHA. The fetch step fails with `instance.base_commit_missing` when the remote does not have the commit. It fails with `instance.base_commit_conflict` when the branch exists on the remote or in the Node's repository at another commit.

#### Run a command in an Instance

`instance.run` takes `command`, `directory` relative to the checkout, and `timeout_seconds` up to 3,600. Its result holds `exit_code`, `timed_out`, a redacted output tail of at most 16 KiB, `head`, the working-tree hash, and the start and end times. Its step log keeps up to 128 KiB. The CLI command is `instance:run`.

The operation succeeds when the command ran to an end, whatever its exit code: a failing test suite is a result, not an operation failure. A command that reaches `timeout_seconds` also ends: the service's runtime bound stops it, and the operation succeeds with `timed_out: true` and exit code 124. The operation fails only when Orbit cannot start the command or read its result.

- It runs only on a development Instance. A production Instance answers `422 instance_run.production_forbidden`.
- It refuses a task workspace with `422 instance_run.task_workspace`, so it never bypasses a task group's one holder.
- It runs the command in a login shell, as the Node's managed user, in the given directory, with the environment of an Instance setup step. No GitHub token is present.
- The [check output](/reference/tasks#check-output) rule redacts the output. The working-tree hash is the one the [Project check](/reference/tasks#project-check) records.

`instance.run` is the agent command `instances.run` of [ADR 0225](/decisions/0225-make-node-changes-through-agent-commands). It starts the command in a transient system service under a run ID, returns at once, and answers status reads by run ID. The start step records the run ID before it calls the agent, so a resumed start adopts that run instead of starting a second one. This amends ADR 0225, whose detached supervision so far serves only checks and topologies. It adds no Gateway script.

#### A waiting step

This amends [ADR 0209](/decisions/0209-run-long-operations-as-durable-queued-operations). An operation gains the non-terminal status `waiting`, and its current step the same status. A waiting operation has no running job and keeps its target. A delayed job reads the run's status again and claims the operation with one conditional update from `waiting` to `running`. The API, the list filter, and the MCP enums gain the status. The run's own `timeout_seconds` bounds the wait, and the wait does not count toward the 1,600-second deadline. So a long test suite never holds the `operations` worker.

- A status read that finds the run still going counts no attempt.
- The sweep's free-lease rule for `running` operations skips a `waiting` operation. Only one rule applies to it: the sweep queues it again when its next read is more than twice its delay overdue, so a lost delayed job cannot strand it.
- A delayed status read does not count as a waiting job in the sweep's check for stale `queued` operations.

The target stays held across reads. Another operation on the Instance answers `409 operation.target_busy`. This also amends the rule for other actions that take the Instance operation lock, such as `env:sync`: while an operation on that Instance is `waiting`, they answer `409 operation.target_busy` instead of waiting or answering `env.operation_busy`. A workspace Instance belongs to one work item, so the hold blocks no other work.

This also amends the cancel rule of [Operations](/reference/operations#cancel-an-operation), which finishes the running step. A cancel of a waiting step stops the service and all its descendants at once, and the operation ends `cancelled`. When Orbit cannot confirm the stop, the cancel request answers `502 instance_run.stop_unconfirmed`. The operation then stays `waiting` with its target and run ID, and the next status read tries the stop again.

#### Security

A Node access grant already lets its holder run any command on that Node, for example through a Node [Process](/reference/processes-and-schedules), and the managed user has passwordless sudo. `instance.run` adds no power that the grant does not already give. Under [Security fits the real threat model](/mission#principles), it adds no further defense layer.

### Step 2: publish

`instance.publish` pushes a commit of a development Instance to a branch through the [GitHub App](/reference/github-app). It can also open or update a pull request. The CLI command is `instance:publish`.

| Input | Contract |
| --- | --- |
| `commit` | A full SHA in the Instance checkout |
| `branch` | The target branch |
| `pull_request` | Optional `{ base, title, body }` |

The Gateway fetches the commit from the Instance into its mirror and pushes it from there. Publish needs the App's `Contents: write` and `Pull requests: write` permissions, and `Workflows: write` only for a commit that changes a workflow file. The agent and the Instance never hold a GitHub token. The result holds the branch, the pushed commit, and the pull request number and URL. Start checks answer `422`; a push or GitHub failure fails the step.

| Case | Result |
| --- | --- |
| `branch` is the Project's default branch, a branch a production Instance of the Project deploys from, or a `task-*` branch | `422 instance_publish.branch_forbidden` |
| The Project reads its repository with `gh_cli` | `422 instance_publish.source_access_unsupported` |
| The branch already exists and is not an ancestor of `commit` | The step fails with `instance_publish.not_fast_forward`. A push is never forced |
| The branch already points at `commit` | Succeeds without a push |
| An open pull request for `branch` has another base | The step fails with `instance_publish.pull_request_base_mismatch` |
| The last pull request for `branch` is closed or merged | A new pull request opens |
| `commit` changes a file under `.github/workflows/` and the App lacks `Workflows: write` | `422 instance_publish.workflows_permission` |

Before it opens a pull request, the step looks for an open one for the branch. A resumed step therefore never opens a second one.

### Step 3: orchestrated task records and agent turns

An amendment to this ADR decides this step. An evaluation of the Pi Durable harness (`@earendil-works/pi-durable`) is underway, and its result decides how a turn survives a crash. The intended shape:

- A task group gains a driver: `tick` or `orchestrator`. `tasks:tick` does not claim or advance an orchestrated group.
- The orchestrator changes a group or subtask state through task routes with an `Idempotency-Key`. Tasks records the state, shows it on the board, and records assistance.
- An agent turn becomes an operation on the work item's task VM, with a role, a prompt, and an optional model. The operation records a turn request, and `tasks:tick` sends it through its one dispatcher, so [ADR 0224](/decisions/0224-give-each-task-group-one-holder-and-one-sender)'s one sender stays. The turn's agent session attaches to its task.
- The operation key is also the turn's submission key, so a retry never starts a second turn.
- When every task runs this way, Orbit removes the state machine of `tasks:tick`, its budgets, reminders, and consult limit. The board, task records, assistance, and agent sessions stay. The amendment also decides which of ADR 0224's remaining build steps still apply.

## Rejected alternatives

- **The orchestrator above whole task groups.** One work item would be one task group, and `tasks:tick` would keep the inner loop. That keeps the hand-rolled engine, so Orbit's scope does not shrink.
- **The orchestrator as a second sender.** It would call Pi or the task drivers directly. That breaks the one-sender rule of [ADR 0224](/decisions/0224-give-each-task-group-one-holder-and-one-sender).
- **A plain remote-exec route without an operation.** It has no durable record, no idempotency, and a request time limit.
- **`instance.run` as one blocking step.** A 15-minute test suite would hold the single `operations` worker and stall every deploy.
- **Per-step jobs for every operation.** The target would be free between steps. Only a waiting step ends its job, and it keeps the target.
- **Lifting the Tasks check scripts out of Tasks.** It is faster, but [ADR 0225](/decisions/0225-make-node-changes-through-agent-commands) freezes Gateway scripts and moves Node execution into agent commands.
- **Endpoints named after one orchestrator.** They would tie Orbit to one tool.
- **A key scoped to the calling Node, or one that expires.** A moved orchestrator would lose its keys, and a replay after expiry would start the work a second time.

## Consequences

- Orbit's task engine can be deleted after step 3, which removes most of the Tasks extension's state logic.
- The board and agent sessions stay, so a person still sees what is happening.
- Retries become one rule for every operation: the key.
- Until step 3, task work has two drivers, as the Principle line states.
- Step 1 waits for the Instance operations of ADR 0209 and ADR 0218, and for ADR 0225's agent command foundation after ADR 0224.
- The orchestrator becomes a dependency for task work. Its availability, licensing, and storage are outside Orbit.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk, apps/web, apps/docs
- ADRs: [0209](/decisions/0209-run-long-operations-as-durable-queued-operations), which gains the waiting step and its cancel rule; [0218](/decisions/0218-take-long-commands-off-the-request-path), whose Instance operations gain inputs and whose retention spares keyed operations; [0224](/decisions/0224-give-each-task-group-one-holder-and-one-sender), whose one sender step 3 keeps and whose remaining build steps the step 3 amendment revisits; [0225](/decisions/0225-make-node-changes-through-agent-commands), which gains `instances.run` and its detached supervision
- Detail: the agent command `instances.run` in `apps/agent`; [Operations](/reference/operations) for the idempotency key and the waiting status; [Environment variables](/reference/environment-variables) and [Deployments](/reference/deployments) for `409 operation.target_busy` during a wait; [Instance dependencies](/reference/instance-dependencies#update-a-development-instance) for `directory`; a new page `/reference/instance-runs` for `instance.run`; [GitHub App](/reference/github-app) for publish; [Tasks](/reference/tasks) for step 3
- Verify: Gateway feature tests for each operation, its idempotency conflict, and each refusal; an Incus E2E that runs a test suite through `instance.run`, kills the Gateway worker during the waiting step, and reads the same result after recovery
