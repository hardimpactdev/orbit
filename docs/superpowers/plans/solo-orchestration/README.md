# Solo Orchestration Loop

This directory is the complete source for the Solo orchestration loop used to
drive Orbit porting work.

Do not use `../00-plan-implementation-prompt-solo.md` as an input for this
loop. That file is deprecated and exists only to point back here.

## Role Map

1. **Kickstarter** is a coordinator-run procedure, not a Solo agent role. The
   current human-facing agent executes `kickstarter.md` directly to start or
   resume the loop.
2. **Orchestrator** is a cheap scheduler. It checks queue/process state, spawns
   one-shot fillers, spawns or restarts one implementer, asks the tailer for
   verification, and closes todos.
3. **Pipeline Filler** is a one-shot role spawned by the orchestrator when the
   ready queue is below target. It reads `docs/PORTING.md` and creates the next
   single-worker todos.
4. **Tailer** is the ongoing reviewer. It supervises active agents, locks,
   scope, git state, focused gates, final diffs, and template friction on a
   5-minute check-in cadence. It owns scratchpad `131`.
5. **Loop Improver** is a long-running improvement role. It watches the loop
   across cycles and keeps orchestration docs plus scratchpad `132`
   self-correcting.
6. **Implementer** owns exactly one todo.

## Shared Inputs

Every role reads only the context it needs:

- this `README.md`
- `kickstarter.md` for the resolved run configuration when agent/model choices
  are needed
- the role-specific prompt file
- `docs/PORTING.md`
- relevant `docs/commands/**`
- `docs/BLUEPRINT.md`
- `docs/BUILDING-BLOCKS.md`
- `docs/MISSION.md` when scope or capability questions arise
- `../orbit-old-may` only as legacy implementation evidence
- Solo scratchpad `131` for the worker todo template
- Solo scratchpad `132` for the one-shot pipeline filler prompt

Current docs are product authority. Current implementation and the old repo are
evidence only.

## Startup Procedure

The coordinator runs `kickstarter.md` directly. Do not spawn a Solo process
whose only job is to read `kickstarter.md`.

The kickstarter procedure:

1. resolves the configuration in `kickstarter.md`;
2. confirms the Solo project, process list, agent tools, todo state, scratchpad
   state, and git status;
3. spawns or resumes exactly the long-running roles needed by the loop:
   orchestrator, tailer, and loop improver;
4. uses the startup handshake for each spawned role;
5. records the active process ids and queue state on the coordination todo;
6. exits from coordination mode once those long-running roles are active.

After that, the orchestrator owns assignment, the tailer owns implementation
supervision and scratchpad `131`, and the loop improver owns loop-level prompt
improvements and scratchpad `132`.

## Solo Tooling

Use Solo's process tools as the loop's source of runtime truth:

- `list_processes` to detect active, exited, duplicate, or stale processes.
- `get_process_status` to check `status`, `pid`, `uptime_seconds`, and
  `agent_state` before deciding an agent is stalled.
- `get_process_output` or `search_output` to prove that a prompt landed, a
  lifecycle label was posted, or a worker is only showing a startup screen.
- `send_input` with `wait_ms` to deliver prompts and immediately read back the
  first rendered output.
- `timer_set` for 5-minute role check-ins.
- `timer_fire_when_idle_any` or `timer_fire_when_idle_all` to wait for newly
  spawned agents or active workers to become idle without sleep loops.
- `close_process` only after recording why a duplicate, stale, or completed
  terminal/agent process is being removed.

Do not treat `spawn_process` as proof that the agent is ready to receive a
prompt. A new process can be running while the CLI is still booting, drawing its
welcome screen, or waiting for the first user input.

## Startup Handshake

Every spawned agent uses this handshake before the loop assumes it is active:

1. Spawn the process and record its process id in the relevant todo or
   coordination comment.
2. Allow a normal startup window. Use `get_process_status` and
   `get_process_output`; do not recover just because the output still shows a
   welcome screen during the first moments after spawn.
3. When the process is running and able to receive input, send the role prompt
   with `send_input`.
4. Verify prompt delivery by checking rendered output, an `ASSIGNED` comment, a
   `WORKER_STARTED` comment, or the role-specific lifecycle label.
5. If prompt-delivery evidence is absent, schedule an idle-triggered timer or
   one short follow-up check before retrying.
6. Retry prompt delivery once when the process is alive and input-capable but no
   prompt evidence appears.
7. Only after the retry/grace window fails, post `PROMPT_RECOVERY`, close the
   stale process when replacement is required, and spawn one replacement.

Keep the loop calm and self-correcting: observe first, wait while
status/output still show startup, recover only when evidence shows a real
stall.

## Decision Evidence Stack

When a fork appears, do not let an implementation worker choose between broad
architecture paths mid-stream. Pause the implementation todo and create a
focused decision/audit todo.

The decision worker must resolve the fork from this evidence stack, in order:

1. current docs as product authority;
2. `docs/PORTING.md` for migration order and current tracker state;
3. old repo evidence from `../orbit-old-may`;
4. existing code, tests, and todo comments as implementation evidence.

The worker may choose a path only when that stack clearly supports one option as
simpler, safer, and aligned with the clean rebuild. If the stack does not decide
the fork, the worker must stop with `NEEDS_DIRECTION`.

Agents must not pick the option that sounds best merely to keep the pipeline
moving.

## Lifecycle Labels

Use these exact labels in Solo comments so work can resume after compaction:

- `PIPELINE_READY`: todo is unblocked, scoped, and ready for assignment.
- `ASSIGNED process=<id>`: orchestrator assigned a worker process.
- `PROMPT_DELIVERED process=<id>`: orchestrator or tailer verified that the
  process received the role prompt.
- `PROMPT_DELIVERY_STALLED process=<id>`: tailer observed that startup has had
  a fair chance and prompt delivery still has no evidence.
- `WORKER_STARTED`: worker confirmed task scope, dependencies, and gate.
- `WORKER_DONE status=DONE|DONE_WITH_CONCERNS|BLOCKED|NEEDS_DIRECTION`:
  worker handoff result.
- `TAILER_VERIFIED`: tailer verified lifecycle, gate evidence, scope, and locks.
- `CHANGES_REQUESTED`: tailer found in-scope issues for the implementer to fix.
- `ORCHESTRATOR_CLOSED`: orchestrator closed the todo lifecycle.
- `PROMPT_RECOVERY`: prompt delivery or stalled-process recovery was performed.
- `PIPELINE_FILL_STARTED process=<id>`: one-shot pipeline filler was spawned.
- `PIPELINE_FILL_DONE status=DONE|BLOCKED|NEEDS_DIRECTION`: pipeline filler
  finished queue work.
- `SCOPE_DRIFT`: worker or supervising agent touched/proposed out-of-scope
  work.
- `LOCK_STALE`: a Solo lock is stale or externally owned and needs recovery.
- `TEMPLATE_FRICTION`: repeated todo-shape issue requiring improvement to
  scratchpad `131`, scratchpad `132`, or the role prompts.
- `LOOP_IMPROVEMENT`: loop-improver change or recommendation that makes the
  orchestration loop more self-correcting.
- `NEEDS_DIRECTION`: product or architecture decision needs human input.

## Improvement Ownership

The loop is self-correcting, but each durable artifact has one owner:

- Tailer owns scratchpad `131`, the worker todo template. It may edit `131`
  when repeated worker friction shows a todo-shape problem.
- Loop improver owns scratchpad `132` and repo orchestration prompts in this
  directory. It may update them when process, pipeline, or role-boundary
  friction repeats.
- Pipeline filler may report `TEMPLATE_FRICTION`, but does not edit `131`.
- Orchestrator may report scheduling or process-recovery friction, but does not
  edit templates.

When the loop improver finds a worker-template issue that belongs in `131`, it
records a `TEMPLATE_FRICTION` comment for the tailer instead of editing `131`.
When the tailer finds pipeline-filler or role-prompt friction, it may record it
for the loop improver instead of expanding its own scope.

## Todo Pipeline Rules

- Keep a bounded queue of unblocked `PIPELINE_READY` todos.
- The orchestrator must spawn a one-shot pipeline filler on timer ticks when
  the queue has fewer than `PIPELINE_READY_TARGET` unblocked `PIPELINE_READY`
  todos.
- The pipeline filler reads `docs/PORTING.md` first, then checks relevant
  command docs, current todos, blockers, and completed work.
- Prefer docs-first and decision/audit todos when docs or architecture contain
  multiple unresolved paths.
- Do not create todos for phases or command-group headings. Command groups are
  sequencing context, not assignable work.
- Every todo must state objective, sequencing rules, dependencies, product
  authority, legacy evidence, owned files/domains, non-goals, quality gate,
  tailer verification requirements, escalation and stop conditions, lock
  hygiene, and reporting requirements.
- A todo is worker-ready only when it has a single implementation or decision
  path. If it contains alternatives, create a decision todo first.

## Quality Gates

Each implementer must run the exact focused gate listed on its todo.

If PHP files changed, also run:

```bash
vendor/bin/pint --dirty --format agent
```

Before batch close-out, the orchestrator and tailer must ensure these gates have
passed:

```bash
composer rector
composer analyse
composer format
composer test
```

Use `composer quality-check` only when the plan or todo explicitly accepts the
combined gate. Do not replace a todo's focused gate with a broader gate unless
the todo says so.

## Safety Rules

- Do not run destructive, provisioning, host-mutation, or repair/adoption flows
  against standing live nodes.
- Standing live-node checks on gateway, beast, and mini must stay read-only or
  idempotent.
- Provisioning and destructive validation must use only the ephemeral nodes or
  VMs described in `TESTING.md`.
- Agents must not use `git stash`, `git reset --hard`, `git checkout --`, broad
  `git restore`, or hidden reverts.
- Baseline evidence must use read-only commands such as `git log`,
  `git show <ref>:<path>`, or `git diff <ref>..HEAD -- <path>`.

## Blocker Handling

When an implementer hits a blocker:

1. Keep the todo open.
2. Create a focused decision/audit todo when the blocker is architectural or
   product-level.
   The decision/audit todo must require the decision evidence stack above.
3. Use `RUBBER_DUCK1` and `RUBBER_DUCK2` for independent solution proposals
   only when the active todo requires blocker resolution and the product docs do
   not already decide the answer.
4. Record proposals and route them back to the owning implementer or tailer.
5. Ask the user only for genuine product direction that cannot be decided from
   current docs, `docs/PORTING.md`, and legacy evidence.

## Completion

The loop is complete only when:

- all todos in the current porting scope are completed or explicitly deferred
  with evidence;
- every completed implementation todo has tailer verification evidence;
- intentional changes are committed to `main`;
- applicable E2E validation in `TESTING.md` has passed or a tracked blocker
  explains why it cannot run yet;
- discovered follow-up work is captured in Solo and, when durable, in
  `docs/PORTING.md`.
