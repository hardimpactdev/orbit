# Phase 3 design: Swamp on the Orbit Gateway

Status: Nick chose option B, built as B1 then B2 (2026-10-22). The Orbit side is ADR 0229 in hardimpactdev/orbit#1455 (docs only); it supersedes the operation list below where they differ.

## Settled

- Swamp keeps the state machine (`orbit-subtasks`); the Gateway performs every
  side effect and owns exactly-once per idempotency key.
- Orbit runs agent turns on the Pi driver. Swamp never runs an agent itself.
- GitHub pushes and pull requests are Orbit's job (its GitHub App). Swamp
  never touches GitHub.
- `swamp serve` is the orchestrator, on the Team plan with a collective token
  and `SWAMP_SIGNIN_TOKEN`.

## What the Gateway offers today

Source: Orbit docs and `docs/openapi.json` at `8bd95343a`.

| Effect | Today | Gap |
| --- | --- | --- |
| Workspace per work item | `POST /instances`, `POST /instances/{id}/setup`, `DELETE /instances/{id}`. Synchronous; a retry with the same name resumes. | Branch only, no base commit. Setup runs inside one request (570 s limit). Task-VM workspaces exist only through a task-group claim. |
| Run a command (`composer update`, `check`, `test`) | Not found. Schedules give only success/error; task checks run only inside a group. | New async run-command operation. |
| Sub-project dependency update | `POST /instances/{id}/dependencies/update`, repository root only. | A `directory` input, async. |
| Pi agent turn | Only inside a task group (one subtask at a time), driven by `tasks:tick`: VM, baseline, implementer, handoff check, reviewer, push, PR. | No standalone turn. The caller cannot set the prompt or model. |
| Model key per run | Keys exist per task VM and die with the VM. | No issue or revoke route. |
| Branch / PR handoff | The Gateway pushes `task-{id}` (or `task-{id}-handoff`) itself and opens the PR on approval. | No "publish this workspace commit" route. |
| Assistance | Task comments `assistance_requested` / `resolution`, task questions. | Only for task subtasks. |
| Notifications | Reverb `task_group.created/updated` on the private `orbit` channel. | No outbound webhooks. |
| Idempotency keys | Only the websocket-app routes accept `Idempotency-Key`. | All effectful routes above. |

Two accepted ADRs constrain any outside orchestrator:

- **ADR 0223**: one Gateway gate per handoff. The full suite runs in CI on the
  pushed handoff commit, and CI `Required checks` is the only integration gate.
- **ADR 0224**: `tasks:tick` is the only place that starts an actor or sends a
  turn. Requests only record intent and wake the tick. An outside caller
  cannot be a second sender or holder for a group.

## Two ways to do phase 3

### A. Swamp above Orbit tasks (outer loop)

One factory work item maps to one Orbit task group with one subtask.
Swamp plans, fans out, gates approvals and records the outcome. Orbit runs the
inner loop it already has: VM, baseline, implementer, handoff, review, push, PR.

- The factory shrinks to `plan → create group → wait → outcome`. Swamp
  creates the group in `todo` with deliverables (`fails_on_base` command
  deliverables for the sub-project's check), reads `GET /task-groups/{id}` on
  each tick or on Reverb notices, and records evidence from its status.
- Assistance stays in Orbit (task comments). Swamp's human gates cover only
  the plan approval (majors) and the final outcome.
- Gateway change: small, possibly none. Useful additions: an `Idempotency-Key`
  on `POST /task-groups`, and an external reference field on a group so Swamp
  can find its group after a crash.
- What it answers: Swamp can plan and coordinate many Orbit tasks. It does
  **not** replace the hand-rolled orchestration inside Orbit tasks, which is
  what the spike set out to test.

### B. Swamp replaces the inner loop (primitives)

The Gateway exposes primitives, and Swamp's stage workflows call them in place
of local git, Composer and agents. To respect ADR 0224, every primitive is an
operation the Gateway queues and its own worker or tick executes. Swamp records
intent and polls; it never sends a turn itself.

New Gateway operations (each async, `202` + `operation-show`, each with an
`Idempotency-Key`):

1. `POST /instances` with `base_commit` (or `start_ref`), and async setup.
2. `POST /instances/{id}/runs` {command, directory, timeout}: exit code, a
   redacted 16 KiB output tail, cancel. This replaces `@nckrtl/composer`.
3. `dependencies/update` with a `directory`, for one sub-project.
4. `POST /instances/{id}/agent-turns` {role, prompt, model?}: one Pi turn in
   the workspace, read with a transcript cursor, cancel. Queued for the tick
   or worker, so the tick stays the only sender. Today Pi runs only on task
   VMs, so this needs a decision: Pi on app-dev Instances, or the workspace is
   a task VM.
5. A per-run AI Router key bound to the turn (issued and revoked by the
   Gateway; Swamp never sees it).
6. `POST /instances/{id}/publish` {commit, branch, pr?}: push through the App
   and open or update the PR.
7. Assistance and status outside task groups, or Swamp's own human gates only
   (`approve` through the serve API), with the Gateway mirroring them read-only.
8. Notifications: Reverb events for these operations (no webhooks needed if
   serve can subscribe; otherwise a signed webhook to `swamp serve`).

Swamp side: the five stage workflows keep their shape. `@nckrtl/composer`,
`@swamp/git` and `@swamp/agent-runner` steps become one `@nckrtl/orbit-gateway`
behavior (`create_workspace`, `run`, `agent_turn`, `publish`, `remove`), each
call keyed `<workItem>.<stage>.c<cycle>`. The guards and the driver stay. The
local scratch worktrees and the CLIProxyAPI stopgap go away.

What it answers: whether Swamp can run Orbit's task loop. Cost: eight Gateway
operations, plus the Pi-on-Instance decision, through Orbit's normal PR flow.
It also makes two orchestrators of the same loop until one is retired.

## Layers with Pi Durable

A parallel spike (`hardimpactdev/orbit-pi-durable`, thread "How Swamp Fits
Into Orbit and Pi") tests `@earendil-works/pi-durable`: one agent
conversation that survives crashes (atomic commit log, `requestId` dedup,
stored retry sleeps, crash-loop parking) and `pi-env` (tools run in the VM over
SSH, model credentials stay on the host). It is the layer below Swamp, not a
second orchestrator:

| Layer | Owns | Replaces in Orbit |
| --- | --- | --- |
| Swamp (`orbit-subtasks`) | Subtask lifecycle: stages, gates, budgets, approvals, journal | `tasks:tick` state machine, caps, assistance flow |
| Gateway primitives | Every effect, exactly-once per key | Task-specific workspace, check and push paths |
| Pi Durable (+ `pi-env`) | One agent turn, crash-proof | Send keys, restart re-orient (2 resumes), provider retry 1/2/4/8 min, `pi-server` store |

- Swamp's key `<workItem>.<stage>.c<cycle>` is the Gateway operation key and
  Pi Durable's `requestId`.
- With `pi-env`, the model key never enters the workspace, so operation 5
  (per-run model key) may shrink to "the Gateway's Pi host holds the key".
- Pi Durable's own task graph is not used for the lifecycle; one orchestrator.
- Caveat: Pi Durable is experimental (API changes without notice; 17 versions
  in a month) and needs Pi 1.4 (`apps/pi-server` is on 1.2).

B1 does not depend on Pi Durable. B2's agent-turn operation should be built on
it if its spike holds.

## Aligned with the Pi Durable thread (2026-10-22)

- Pi on Durable runs inside the task VM behind pi-server's current HTTP API
  (option A). The send key of `POST /sessions/{id}/messages` becomes Durable's
  `requestId`, so the Swamp key passes through unchanged. A repeat returns
  `duplicate: true`.
- Durable dedupes only within one VM's storage. Across a lost VM, the
  Gateway's keyed, never-pruned operation is the guard.
- The step 3 amendment must state three changes:
  1. A pi-server restart no longer fails the turn; Durable resumes it. Drop
     the Gateway's restart-resume path (2 resumes, re-orient), or it sends a
     duplicate turn. Durable's crash-loop parking (5 restarts) surfaces as
     failed, then assistance.
  2. Provider retry happens in one place: Durable, set to Orbit's budget,
     instead of the Gateway's 1/2/4/8-minute retry.
  3. Checks stay orchestrator operations (`instance.run`). The model did not
     rerun an interrupted check in the Pi spike.
- Both threads stop running agents on the host: everything task-specific
  stays in the VM.
- Decided (Nick, 2026-10-22): every step runs in the group's task VM. ADR
  0229 was amended on #1455 (fd9aa1e5e): four operations on externally
  orchestrated groups (`tasks.provision`, `tasks.check`, `tasks.turn`,
  `tasks.publish`) wrap code Tasks already has. The dev-Instance operations
  in the table above are dropped.

## ADR 0229 follow-ups (from the first live run, 2026-10-23)

Found while Swamp drove Orbit group 2046 (nckrtl/website#7) end to end:

1. A Gateway route to raise and resolve group-level assistance after all
   subtasks have ended (needed for the publish-retry and republish gates). The
   comment route needs a child task and clears the group only when that child
   owns the request.
2. A failed check is an operation that succeeded with `outcome: failed`; the
   board shows a parked external group only through the orchestrator's own
   assistance comment.
3. The check output is a short redacted tail, so in a long run the root cause
   can fall out of it.
4. No API shows which pi-server artifact new task VMs get
   (`ORBIT_TASK_VMS_PI_ARTIFACT_PATH` / `_SHA256`).
5. The orchestrator re-derives the current subtask from the Gateway's own rule
   (lowest open position).
6. A merge is seen only by polling `watched_pr_state`.
7. `tasks:complete` is a synchronous request that tears down the workspace and
   task VM before it answers. In the second run (group 2049) the client's first
   call timed out after 60 s while the Gateway went on to complete the group;
   the next tick only saw the result in the group status. Unlike the four
   operations it takes no `Idempotency-Key` and returns no operation to read.
   Make completion an operation, or answer before the teardown.
8. A release-age gate exception is invisible to the orchestrator. In group 2049
   the implementer said it used the project's documented exception for
   vite-plus 1.4.0 (released that day), but nothing in the repo or the receipt
   shows how. A turn receipt or the check should record any exception it used.

Closed in orbit-swamp #3: pre-approved gates without a live turn, board
assistance at subtask gates, and the driver ignoring evidence from before a
reset. Settled: the last approval in an external group needs no `--pr-*`
flags.

## Recommendation

Do **B** (chosen), in two steps:

1. **B1, no agents** (operations 1, 2, 3, 6 and idempotency keys): rerun
   php-sdk and gateway with mechanical, handoff and publish on the Gateway.
   Review becomes a human gate in B1, so no agent primitive is needed yet.
   This proves Gateway-side exactly-once, the kill and recover tests against
   the Gateway, and serve.
2. **B2, agents** (operations 4 and 5): implement, consult and review as Pi
   turns, backed by Pi Durable with `requestId` = the Swamp key, after the
   Pi-on-Instance decision and the Pi Durable spike.

If the answer Nick wants is only "can Swamp coordinate Orbit tasks", **A** is
enough and needs almost no Orbit change.

## Keys (B)

- **Swamp:** a collective token and `SWAMP_SIGNIN_TOKEN` for serve; Swamp
  vault for serve's own secrets.
- **Swamp → Gateway:** WireGuard identity of the serve Node plus a node access
  grant to the app-dev Node (today's model); no API token.
- **Gateway → Swamp** (only if webhooks): a serve token with grants for
  `subtasks-driver` run and `approve`, and a webhook signing secret.
- **Per agent turn:** an AI Router key issued and revoked by the Gateway.
- **GitHub:** Orbit's App, inside the Gateway only.

## Where serve runs

Beast for the spike, as a user systemd unit with `~/.config/swamp/env.sh`, a
1-minute `trigger.schedule` on `subtasks-driver`, and `--auto-resume`. A Node
for production is a later decision.

## Open decisions for Nick

1. ~~A or B?~~ B.
2. ~~B1 first?~~ Yes, then B2.
3. For B2: Pi on app-dev Instances, or agent turns only in task VMs (with
   `pi-env`, tools could run in either while keys stay on the host)?
4. Gateway → Swamp notification: Reverb subscription, or a signed webhook to
   serve?
5. How the B1 Gateway work is run: Orbit docs/ADR first, then implementation
   PRs, through Orbit's usual flow.
