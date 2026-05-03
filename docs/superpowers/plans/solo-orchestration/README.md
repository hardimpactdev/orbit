# Solo Orchestration Loop

Source of truth for Orbit's Solo orchestration loop.

## Core Shape

- One persistent loop clock exists only to wake, read `loop-clock.md`, spawn
  the configured orchestrator agent, and set the next timer.
- Every orchestrator is a fresh one-shot Solo agent. It runs one cycle,
  records what happened, and exits.
- Helper roles are one-shot Solo agents spawned by the orchestrator.
- Runtime knobs live in the unarchived scratchpad named exactly
  `Solo Orchestration Control`. Resolve by name every wake/cycle. Never
  hardcode the scratchpad id.
- Worker todo shape lives in the unarchived scratchpad named exactly
  `Solo Worker Todo Template`. Resolve by name when creating or validating
  worker todos.
- Dispatch KV is deprecated. Use tags, locks, blockers, process state, timers,
  and lifecycle comments.
- There is no kickstarter role, long-running orchestrator, or loop improver.

## Roles

- `loop-clock.md`: persistent clock. No orchestration decisions.
- `orchestrator.md`: one fresh cycle. Reconcile state, dispatch at most one
  bounded unit per category, close consumed helpers, report, exit.
- `pipeline-filler.md`: create or refresh draft todos and scout them.
- `todo-scout.md`: validate exactly one draft todo before it can become
  `worker-ready`.
- `implementer.md`: complete exactly one todo, including focused tests and the
  declared E2E lane.
- `reviewer.md`: review exactly one `review-ready` todo.
- `rubber-duck.md`: independently resolve one blocker, in a pair.
- `e2e.md`: rerun one gate todo's declared E2E lane in a clean state.

The implementer/E2E boundary is strict: implementers author and pass E2E
coverage locally; E2E only verifies the committed result in a clean state.

## Initiating The Loop

Use this section when the user asks to start or resume the loop.

1. Resolve the unarchived control scratchpad named
   `Solo Orchestration Control`.
2. If it is missing, create it from the default below. If more than one
   unarchived match exists, stop with `NEEDS_DIRECTION`.
3. Resolve the unarchived worker template scratchpad named
   `Solo Worker Todo Template`.
4. If it is missing, create it from the current worker todo requirements. If
   more than one unarchived match exists, stop with `NEEDS_DIRECTION`.
5. Ensure `coordination_todo` in the control scratchpad points at an open Solo
   todo. Create one and write its id into the scratchpad if needed.
6. Start or resume exactly one loop-clock agent named `LOOP-CLOCK <run_id>`.
7. Send it only this prompt:

   ```text
   You are the Orbit Solo loop clock. Read docs/superpowers/plans/solo-orchestration/loop-clock.md before any other action.
   ```

The loop clock handles orchestrator spawn and the next timer from the control
scratchpad.

## Control Scratchpad

Default scratchpad name:

```text
Solo Orchestration Control
```

Default content:

````markdown
# Solo Orchestration Control

Human-editable control surface for the Orbit Solo orchestration loop.

## Control Values

```yaml
run_id: <active-run-id>
coordination_todo: <active coordination todo id>
porting_tracker: docs/PORTING.md

loop_clock:
  enabled: true
  interval_minutes: 10

pipeline:
  ready_target: 2
  filler_enabled: true

concurrency:
  max_active_implementers: 1
  reviewer_dispatch_enabled: true
  e2e_dispatch_enabled: true

agents:
  orchestrator: claude-sonnet
  pipeline_filler: claude-opus
  todo_scout: gemini-3.1-pro-preview
  reviewer: gemini-3.1-pro-preview
  implementation: opencode-kimi-k2.6
  rubber_duck_1: gemini-3.1-pro-preview
  rubber_duck_2: claude-opus
  e2e: claude-opus

safety:
  standing_infrastructure: not-a-test-lane
  destructive_flows: ephemeral-only
```
````

The old `solo-orchestration/run-config` KV key is deprecated. Do not write
runtime control values to KV.

## Role Bootstrap Shape

Loop clock bootstrap is intentionally tiny:

```text
You are the Orbit Solo loop clock. Read docs/superpowers/plans/solo-orchestration/loop-clock.md before any other action.
```

The loop clock spawns `agents.orchestrator` as a Solo agent named
`ORCH-CYCLE <run_id> <YYYYMMDD-HHMMSS>`, then sends this prompt:

```text
You are the Orbit Solo orchestrator. Read docs/superpowers/plans/solo-orchestration/orchestrator.md and execute exactly one orchestration cycle.
```

Helper role bootstraps should be compact pointers plus assignment data:

```text
You are the <role> for Solo project orbit. Read:
- docs/superpowers/plans/solo-orchestration/README.md
- docs/superpowers/plans/solo-orchestration/<role>.md
- the unarchived Solo Orchestration Control scratchpad
- the assigned todo/comment/process context below

Assignment:
- ...
```

If required prompt files, scratchpads, or assignment context are missing, the
role stops with `NEEDS_DIRECTION`.

## Shared Authority

Use this evidence order when product or architecture choices fork:

1. Current docs as product authority.
2. `docs/PORTING.md` for migration order and tracker state.
3. `../orbit-old-may` as legacy implementation evidence.
4. Current code, tests, and todo comments as implementation evidence.

Current docs decide product behavior. Current implementation and the old repo
are evidence, not automatic authority.

## Solo State Model

Structured state wins over comments when they disagree.

Read these together:

1. Control scratchpad: run id, queue target, clock interval, pause switches,
   and configured agents.
2. Completion state: `completed`, `completed_at`.
3. Locks: `locked_by` and keyed lease locks.
4. Tags: writable phase and attention state.
5. Blockers: `blocker_ids`, `is_blocked`, `unresolved_blocker_count`.
6. Processes: names, status, output, and agent state.
7. Timers: whether the loop clock has a future wake-up.
8. Lifecycle comments: append-only audit and cross-cycle memory.

If a comment is stale, record one short correction and act on structured state.
Read Solo state through Solo tools. Do not inspect CLI internals such as
`.claude/**`, tool-result files, or memory stores.
When comment history matters, read the specific todo's comments with
`todo_comment_list`; avoid unrelated history dumps.

## Tags

Exactly one open-work phase tag should be present:

- `draft`: candidate not dispatchable.
- `worker-ready`: open, unblocked, scoped, scout-approved.
- `in-progress`: implementer is actively working.
- `review-ready`: worker handed off, reviewer not yet approved.
- `needs-direction`: product, architecture, safety, or sequencing decision
  required.
- `verified`: reviewer approved; orchestrator close-out remains.

Attention tags may coexist:

- `changes-requested`: reviewer found in-scope fixes.
- `e2e-failed`: E2E gate failed and needs routing.

Tag mutation belongs to the actor holding the todo lock.

## Lifecycle Labels

Use exact labels so future cycles can resume from durable evidence:

- `CYCLE_STARTED process=<id>`
- `CYCLE_DONE status=DONE|BLOCKED|NEEDS_DIRECTION`
- `PIPELINE_READY`
- `SCOUT_REPORT status=READY|BLOCKED|NEEDS_DOCS|SCOPE_TOO_BROAD|NEEDS_DIRECTION`
- `PIPELINE_FILL_STARTED process=<id>`
- `PIPELINE_FILL_DONE status=DONE|BLOCKED|NEEDS_DIRECTION`
- `WORKER_STARTED process=<id>`
- `WORKER_DONE status=DONE|DONE_WITH_CONCERNS|BLOCKED|NEEDS_DIRECTION`
- `REVIEW_APPROVED`
- `CHANGES_REQUESTED`
- `RUBBER_DUCK_PROPOSAL agent=<name> verdict=PATH|NEEDS_USER_DIRECTION`
- `RUBBER_DUCK_RESOLVED status=AGREED|ESCALATED`
- `E2E_DISPATCHED process=<id> lane=<ephemeral|none>`
- `E2E_DONE status=PASSED|FAILED|SKIPPED lane=<name>`
- `ORCHESTRATOR_CLOSED`
- `PROMPT_RECOVERY status=STALLED|RETRIED|REPLACED process=<id>`
- `PROCESS_CLOSED process=<id> reason=<role>`
- `SCOPE_DRIFT`
- `LOCK_STALE`
- `TEMPLATE_FRICTION`
- `NEEDS_DIRECTION`

## Process Names

- `LOOP-CLOCK <run_id>`
- `ORCH-CYCLE <run_id> <YYYYMMDD-HHMMSS>`
- `PIPELINE-FILLER <run_id> <timestamp>`
- `SCOUT-<todo_id> <short-title>`
- `IMPLEMENTER-<todo_id> <short-title>`
- `REVIEWER-<todo_id> <short-title>`
- `DUCK-<todo_id>-1 <short-title>`
- `DUCK-<todo_id>-2 <short-title>`
- `E2E-<todo_id> <command>`

Reconcile process names with locks, tags, lifecycle comments, and process
liveness before replacing anything.

## Solo Tooling Rules

- Use `scratchpad_list` and `scratchpad_read` to resolve named scratchpads.
- Use `list_agent_tools` before spawning configured helper roles.
- Use `list_processes`, `get_process_status`, `get_process_output`, and
  `search_output` for liveness and prompt-delivery evidence.
- Use `send_input` only after a spawned helper is ready to receive input.
- Use `timer_set` only for loop-clock wake-ups.
- Use idle timers for waiting; do not busy sleep.
- Call `close_process` only after posting durable `PROCESS_CLOSED` evidence.

`spawn_process` only starts a process. It does not prove the CLI is ready or
that a prompt landed.

## Queue Rules

- Keep at most `pipeline.ready_target` dispatchable `worker-ready` todos.
- The pipeline filler creates or refreshes draft todos, then scouts them.
- A todo becomes `worker-ready` only after `SCOUT_REPORT status=READY`.
- Existing ready implementation work takes precedence over filling future
  queue capacity.
- Every command port ends with one E2E gate todo. Implementers author and run
  the lane; the E2E role reruns it after commit.

## Commit Boundary

- No role creates commits unless the user or assigned todo explicitly gives it
  commit ownership.
- The E2E stage requires an existing committed batch on `main`.
- If reviewed work is ready but uncommitted, the orchestrator reports that
  state on the coordination todo and does not dispatch E2E.

## Quality Gates

Implementers run the focused gate on their todo.

If PHP files changed, also run:

```bash
vendor/bin/pint --dirty --format agent
```

Batch close-out, when assigned, requires evidence for:

```bash
composer rector
composer analyse
composer format
composer test
```

Do not replace a focused gate with a broader gate unless the todo says so.

## Safety

- Standing infrastructure is not a test lane.
- Destructive, provisioning, host-mutation, repair, adoption, and live transport
  verification flows are ephemeral-only per `TESTING.md`.
- Do not use `git stash`, `git reset --hard`, `git checkout --`, broad
  `git restore`, or hidden reverts.
- Shared worktree diffs may belong to another role or the user. Verify
  ownership before changing or reverting anything.

## Completion

The loop is complete only when:

- scoped todos are completed or explicitly deferred with evidence;
- each completed implementation todo has reviewer approval;
- intended changes are committed to `main`;
- required E2E has passed or a tracked blocker explains why it cannot run;
- follow-up work is captured in Solo and, when durable, `docs/PORTING.md`.
