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
   one-shot fillers, spawns or restarts one implementer, spawns one reviewer
   after `WORKER_DONE`, and closes todos after reviewer approval.
3. **Pipeline Filler** is a one-shot role spawned by the orchestrator when the
   ready queue is below target. It reads `docs/PORTING.md`, creates or refreshes
   draft todos, and spawns one mandatory todo scout per candidate before any
   todo can become `worker-ready`.
4. **Todo Scout** is a one-shot role spawned by the pipeline filler for exactly
   one draft todo. It performs deep achievability and ambiguity review, may
   refine the todo, and posts `SCOUT_REPORT`.
5. **Reviewer** is a one-shot role spawned by the orchestrator after an
   implementer posts `WORKER_DONE`. It reviews one task's diff, scope, docs
   alignment, focused gate evidence, and safety.
6. **Loop Improver** is a long-running improvement role. It watches the loop
   across cycles and keeps orchestration docs plus scratchpads `131` and `132`
   self-correcting.
7. **Implementer** owns exactly one todo.

## Shared Inputs

Every role reads only the context it needs:

- this `README.md`
- `solo-orchestration/run-config` for resolved queue targets, coordination
  todo, and agent/model choices
- the role's prompt-cache scratchpad from
  `solo-orchestration/prompt-registry/<role>`
- the role-specific prompt file
- `docs/PORTING.md`
- relevant `docs/commands/**`
- `docs/BLUEPRINT.md`
- `docs/BUILDING-BLOCKS.md`
- `docs/MISSION.md` when scope or capability questions arise
- `../orbit-old-may` only as legacy implementation evidence
- Solo scratchpad `131` for the worker todo template
- Solo scratchpad `132` for loop-level pipeline/template friction notes

Current docs are product authority. Current implementation and the old repo are
evidence only.

## Startup Procedure

The coordinator runs `kickstarter.md` directly. Do not spawn a Solo process
whose only job is to read `kickstarter.md`.

The kickstarter procedure:

1. resolves the configuration in `kickstarter.md`;
2. writes the resolved configuration to `solo-orchestration/run-config`;
3. confirms the Solo project, process list, agent tools, todo state, scratchpad
   state, and git status;
4. syncs the repo role prompts into prompt-cache scratchpads and writes
   `solo-orchestration/prompt-registry/<role>` records;
5. spawns or resumes exactly the long-running roles needed by the loop:
   orchestrator and loop improver;
6. uses the startup handshake for each spawned role;
7. records the active process ids and queue state on the coordination todo;
8. exits from coordination mode once those long-running roles are active.

After that, the orchestrator owns assignment and reviewer dispatch, the
pipeline filler owns todo quality through mandatory scouts, reviewers own
task-level review, and the loop improver owns durable loop improvements plus
scratchpads `131` and `132`.

## Run Config KV

`kickstarter.md` is the human-editable default configuration. The runtime
configuration lives in Solo KV at:

```text
solo-orchestration/run-config
```

The coordinator executing `kickstarter.md` seeds this key with Solo `kv_set`
after resolving defaults and user overrides. Write the value as a JSON object,
not an encoded JSON string. Once the loop improver is active, the loop improver
owns runtime loop-control fields in this key. All spawned roles read it before
acting. If the key is missing, a role stops with `NEEDS_DIRECTION` instead of
guessing values from `kickstarter.md`.

Each value contains at least:

```json
{
  "owner": "loop-improver",
  "run_id": "<ISO-8601-slug-or-short-id>",
  "porting_tracker": "docs/PORTING.md",
  "coordination_todo": "<fresh todo id for this run>",
  "pipeline_ready_target": 4,
  "agents": {
    "orchestrator": "claude-sonnet",
    "pipeline_filler": "claude-opus",
    "todo_scout": "gemini-3.1-pro-preview",
    "reviewer": "gemini-3.1-pro-preview",
    "loop_improver": "claude-sonnet",
    "implementation": "opencode-kimi-k2.6",
    "rubber_duck_1": "gemini-3.1-pro-preview",
    "rubber_duck_2": "claude-opus",
    "e2e": "claude-opus"
  },
  "synced_at": "<ISO-8601>"
}
```

Create a fresh coordination todo for every fresh loop start and store that id
in `coordination_todo`. Reuse an existing coordination todo only when resuming
the same active `run_id`. This keeps the control log bounded and prevents old
loop events from becoming the current run's state.

The loop improver may update runtime loop-control fields such as
`pipeline_ready_target` when the loop needs a different queue target. It must
record `RUN_CONFIG_UPDATED` on the coordination todo with old value, new value,
and reason. It must not change agent/model choices unless the user explicitly
directs that change.

The orchestrator, pipeline fillers, scouts, implementers, and reviewers read
`solo-orchestration/run-config` but do not write it. They report config
friction to the loop improver with `TEMPLATE_FRICTION`, `LOOP_IMPROVEMENT`, or
`NEEDS_DIRECTION`.

## Prompt Cache Scratchpads

Repo files in `docs/superpowers/plans/solo-orchestration/` are canonical.
Prompt-cache scratchpads are runtime mirrors that let long-running agents
refresh their guidance without being restarted or receiving a large repeated
prompt.

The kickstarter syncs the canonical role prompt files into dedicated
scratchpads and records the active mapping in KV:

- `solo-orchestration/prompt-registry/orchestrator`
- `solo-orchestration/prompt-registry/pipeline-filler`
- `solo-orchestration/prompt-registry/todo-scout`
- `solo-orchestration/prompt-registry/implementer`
- `solo-orchestration/prompt-registry/reviewer`
- `solo-orchestration/prompt-registry/loop-improver`

Each KV value contains at least:

```json
{
  "role_file": "orchestrator.md",
  "scratchpad_id": 134,
  "synced_at": "<ISO-8601>"
}
```

Do not hard-code scratchpad ids in role prompts. Read the registry entry for
the role, then read that scratchpad. If the registry entry is missing or points
to a missing scratchpad, stop with `NEEDS_DIRECTION` instead of falling back to
stale prompt text.

Spawned roles receive a compact bootstrap prompt that points to the registry
key and tells the agent to read its prompt scratchpad before acting. The
bootstrap prompt is not the durable role definition.

Use this shape for spawned role prompts:

```text
You are the <role> for Solo project <id>.

Before acting, read:
- kv key: solo-orchestration/run-config
- kv key: solo-orchestration/prompt-registry/<role>
- the scratchpad_id from that KV record
- the assigned todo/comment/KV context listed below

Then follow the prompt scratchpad as your role authority. If the run config,
registry key, or scratchpad is missing, stop with NEEDS_DIRECTION.

Assignment:
- ...
```

Long-running roles must re-read their assigned prompt scratchpad on every timer
tick before inspecting queue or process state. This is an intentional live
control surface: prompt fixes can take effect on the next tick without
restarting the process. Scratchpads remain mirrors/cache; repo files stay the
source of truth.

## Solo Tooling

Use Solo's process tools as the loop's source of runtime truth:

- `list_agent_tools` to resolve configured agent strings from
  `solo-orchestration/run-config` to Solo `agent_tool_id` values before any
  role spawns a process.
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
3. When the process is running and able to receive input, send the compact
   prompt-cache bootstrap with `send_input`.
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

## Agent Tool Resolution

Configured agent strings in `solo-orchestration/run-config` are role
contracts, not suggestions. Resolve them through `list_agent_tools` before
spawning:

- `gemini-*` -> Solo tool type `gemini`
- `opencode-*` -> Solo tool type `opencode`
- `claude-*` -> Solo tool type `claude`
- `codex-*` -> Solo tool type `codex`

The resolved `agent_tool_id` must match the configured CLI/tool type for that
role. If it cannot be resolved, stop with `NEEDS_DIRECTION`.

Prompt recovery must use the same configured role agent. If a Gemini scout
stalls, the replacement scout is still Gemini. If the configured tool type
keeps failing, stop with `NEEDS_DIRECTION`; do not substitute Codex, Claude,
OpenCode, Amp, or any other tool type.

Any report from a process whose tool type does not match the configured role
agent is invalid for promotion, review approval, or close-out.

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

## Loop State Sources

MCP cannot directly set the todo `status` field. Treat structured Solo state,
not free-form comments, as the source of truth for "where is this todo in the
loop?". Comments are audit evidence: append-only, possibly stale, and useful for
reconstructing history across compaction. They are not a state machine.

When deciding what to do next, read these structured sources together:

1. **Run config** (`kv_get solo-orchestration/run-config`) — resolved queue
   target, coordination todo id, and configured agent/model choices. Roles do
   not infer runtime values from `kickstarter.md` after startup.
2. **Completion state** (`completed`, `completed_at`) — terminal done-state.
   Only the orchestrator flips this via `todo_complete` after `WORKER_DONE` +
   `REVIEW_APPROVED` + close-out evidence.
3. **Todo lock** (`locked_by` plus `lock_status` for keyed lease locks) — who
   currently owns the todo. A held lock means an in-flight worker, reviewer, or
   orchestrator is mid-action; do not dispatch or close on top of it.
4. **KV assignment record** (`kv_get solo-orchestration/assignment/<todo_id>`)
   — the current implementer process id, role, and state. The orchestrator
   writes this on dispatch and clears it on `ORCHESTRATOR_CLOSED`. If the KV
   record says `process=569` but a `WORKER_STARTED` comment names
   `process=562`, trust the KV record; the comment is historical.
5. **Tags** — queue and workflow phase. Tags are the writable substitute for
   the read-only MCP todo `status` field. Tag writes are protected by the todo
   lock: if another actor owns the lock, ask that lock owner to make the tag
   transition instead of forcing it from the outside.
6. **Blocker links** (`blocker_ids`, `is_blocked`, `unresolved_blocker_count`)
   — queue dependencies. A todo is dispatchable only when `is_blocked=false`.
7. **Process state** (`get_process_status`, `get_process_output`) — liveness of
   the assigned worker.
8. **Timers** (`timer_list`) — the next expected check-in for each long-running
   role.
9. **Comments and lifecycle labels** — audit evidence only. Use them to
   reconstruct history and to communicate intent across compaction, not to
   decide whether a todo is dispatchable, owned, or done.

The source with authority depends on the question: completion decides done,
locks and assignment KV decide ownership, blockers decide eligibility, tags
decide queue/phase, process state decides liveness, and comments explain how
the loop got there.

When a comment label and structured state disagree, structured state wins.
Record the discrepancy in one short comment so future readers can see the loop
self-corrected, then act on the structured state.

## State Tags

Use tags as the writable todo state machine.

Exactly one phase tag should be present while a todo is open:

- `draft`: candidate todo that is not dispatchable. Pipeline filler/scout work
  happens here.
- `worker-ready`: open, unblocked, scoped, and available for dispatch.
- `assigned`: orchestrator has spawned a worker and prompt delivery is in
  progress or complete.
- `in-progress`: worker has locked the todo and started implementation.
- `review-ready`: worker has posted handoff evidence and released the lock.
- `in-review`: orchestrator has spawned a reviewer and review is active.
- `needs-direction`: product or architecture direction is needed.
- `verified`: reviewer approved final evidence and scope.

Attention tags may coexist with one phase tag:

- `changes-requested`: reviewer found in-scope issues for the worker to fix.

Remove `changes-requested` only after the worker has addressed the finding and
the reviewer has verified the new evidence. Never use it as the only state for an
active todo; keep `in-progress` or `review-ready` so ownership remains visible.

Tag transitions must respect todo locks. The actor that owns `locked_by` owns
tag mutation until it releases the lock. If a coordinator, orchestrator, or
reviewer detects a tag mismatch on a locked todo, it records the expected state
and instructs the lock owner to update tags. It may update assignment KV when
safe, but it must not treat a failed tag write against another actor's lock as
permission to unlock or overwrite the todo.

## Assignment KV Schema

The orchestrator writes one record per active dispatch under a stable key:

- `solo-orchestration/run-config` — resolved run configuration written by the
  kickstarter and read by every role.
- `solo-orchestration/assignment/<todo_id>` — current implementer dispatch.
- `solo-orchestration/reviewer/<todo_id>` — current reviewer dispatch.
- `solo-orchestration/scout/<todo_id>` — current todo scout dispatch.

```json
{
  "todo_id": 191,
  "process_id": 569,
  "role": "implementer",
  "state": "assigned|in-progress|review-ready|in-review",
  "assigned_at": "<ISO-8601>",
  "agent_tool_id": 2
}
```

The orchestrator also tracks short-lived helpers under fixed keys:

- `solo-orchestration/pipeline-filler/active` — the current one-shot filler
  when one is running: `{ "process_id": <id>, "started_at": "<ISO-8601>" }`.
  Cleared on `PIPELINE_FILL_DONE`.

The orchestrator owns implementer and reviewer records. It creates them on
dispatch and clears them on `ORCHESTRATOR_CLOSED` or recovery. The assigned
implementer may update only the `state` field of its own assignment record as
it moves `assigned` → `in-progress` → `review-ready`. The assigned reviewer may
update only the `state` field of its own reviewer record as it moves into
`in-review`.

The pipeline filler owns scout records while it is active. It creates
`solo-orchestration/scout/<todo_id>` before spawning a scout and clears it after
`SCOUT_REPORT` has been consumed. The loop improver reads these records but
does not dispatch product work. On a clean kickstart with no active agents, the
kickstarter clears stale `assignment/*`, `reviewer/*`, `scout/*`, and
`pipeline-filler/*` records before dispatch resumes.

When recovering from a restart or compaction, prefer reading these records over
scrolling comments. If a record points at a process that no longer exists, the
orchestrator clears the record and follows Implementer Recovery.

## Lifecycle Labels

Use these exact labels in Solo comments so work can resume after compaction.
Labels are audit evidence layered on top of the structured state above; they do
not replace it:

- `PIPELINE_READY`: todo is unblocked, scoped, tagged `worker-ready`, and ready
  for assignment.
- `SCOUT_STARTED process=<id>`: pipeline filler spawned a scout for one draft
  todo.
- `SCOUT_REPORT status=READY|BLOCKED|NEEDS_DOCS|SCOPE_TOO_BROAD|NEEDS_DIRECTION`:
  scout completed deep todo validation.
- `ASSIGNED process=<id>`: orchestrator assigned a worker process.
- `PROMPT_DELIVERED process=<id>`: orchestrator, filler, scout, implementer, or
  reviewer verified that the process received the role prompt.
- `PROMPT_DELIVERY_STALLED process=<id>`: orchestrator or loop improver
  observed that startup has had a fair chance and prompt delivery still has no
  evidence.
- `WORKER_STARTED`: worker confirmed task scope, dependencies, and gate.
- `WORKER_DONE status=DONE|DONE_WITH_CONCERNS|BLOCKED|NEEDS_DIRECTION`:
  worker handoff result.
- `REVIEW_ASSIGNED process=<id>`: orchestrator assigned a reviewer process.
- `REVIEW_APPROVED`: reviewer approved lifecycle, gate evidence, scope, and
  locks.
- `CHANGES_REQUESTED`: reviewer found in-scope issues for the implementer to
  fix.
- `ORCHESTRATOR_CLOSED`: orchestrator closed the todo lifecycle.
- `PROMPT_RECOVERY`: prompt delivery or stalled-process recovery was performed.
- `PROCESS_CLOSED process=<id> reason=<role>`: a completed, stale, or replaced
  Solo process was closed after its durable todo/KV evidence was recorded.
- `PIPELINE_FILL_STARTED process=<id>`: one-shot pipeline filler was spawned.
- `PIPELINE_FILL_DONE status=DONE|BLOCKED|NEEDS_DIRECTION`: pipeline filler
  finished queue work.
- `RUN_CONFIG_UPDATED`: loop improver changed a runtime loop-control field such
  as `pipeline_ready_target`.
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

- Loop improver owns scratchpads `131` and `132` plus repo orchestration
  prompts in this directory. It may update them when todo-shape, process,
  pipeline, or role-boundary friction repeats.
- Loop improver owns runtime loop-control tuning in
  `solo-orchestration/run-config`, including `pipeline_ready_target`.
- Pipeline filler, scouts, reviewers, implementers, and orchestrator may report
  `TEMPLATE_FRICTION`, but do not edit scratchpads or role prompts.
- Orchestrator may report scheduling or process-recovery friction, but does not
  edit templates or tune `pipeline_ready_target`.

When a role finds friction in an artifact it does not own, it records
`TEMPLATE_FRICTION` for the loop improver instead of expanding its own scope.

## Todo Pipeline Rules

- Keep a bounded queue of open, unblocked todos tagged `worker-ready` with
  `PIPELINE_READY` audit evidence.
- The orchestrator must spawn a one-shot pipeline filler on timer ticks when
  the queue has fewer than `pipeline_ready_target` from
  `solo-orchestration/run-config` open, unblocked `worker-ready` todos.
- The pipeline filler reads `docs/PORTING.md` first, then checks relevant
  command docs, current todos, blockers, and completed work.
- The pipeline filler creates or refreshes candidate todos as `draft`, spawns
  one mandatory todo scout per candidate, consumes `SCOUT_REPORT`, and only
  then promotes a todo to `worker-ready`.
- Prefer docs-first and decision/audit todos when docs or architecture contain
  multiple unresolved paths.
- Do not create todos for phases or command-group headings. Command groups are
  sequencing context, not assignable work.
- Every todo must state objective, sequencing rules, dependencies, product
  authority, legacy evidence, owned files/domains, non-goals, quality gate,
  reviewer verification requirements, escalation and stop conditions, lock
  hygiene, and reporting requirements.
- A todo is worker-ready only after a scout has posted
  `SCOUT_REPORT status=READY` and the pipeline filler has applied any required
  refinements. If it contains alternatives, create a decision todo first.
- A dispatched todo must stop being `worker-ready` before another dispatch
  cycle can see it as queue capacity.

## Quality Gates

Each implementer must run the exact focused gate listed on its todo.

If PHP files changed, also run:

```bash
vendor/bin/pint --dirty --format agent
```

Before batch close-out, the orchestrator and reviewer evidence must show these
gates have passed:

```bash
composer rector
composer analyse
composer format
composer test
```

Use `composer quality-check` only when the plan or todo explicitly accepts the
combined gate. Do not replace a todo's focused gate with a broader gate unless
the todo says so.

## Concurrent Worktree Hygiene

Multiple roles share one worktree. Tracked-but-uncommitted diffs may belong to
the loop improver, a reviewer, the orchestrator, or another in-flight worker —
not to you. Treat the worktree like a shared workspace, not your sandbox.

- Identify ownership before reverting anything. Loop improver owns
  `docs/superpowers/plans/solo-orchestration/**` and scratchpads `131`/`132`.
  Implementers own only the files named in their todo's "Owned Files Or
  Domains" section. Reviewers and scouts normally do not edit repo files. If a
  tracked diff exists outside your owned files, it is not yours to revert.
- When an out-of-scope tracked diff appears in the worktree during your work,
  do not assume it is yours. Read the most recent comments on the coordination
  todo — the loop improver, reviewer, or orchestrator will have listed their
  changed files. If the listing is missing, stop with `NEEDS_DIRECTION` rather
  than guessing.
- Cleanup, when needed, is done with explicit file edits that preserve the
  unrelated state of other roles. Never use destructive git commands as a
  shortcut to "make the worktree clean for handoff".
- The Safety Rules below are absolute: `git checkout --`, `git reset --hard`,
  broad `git restore`, `git stash`, and hidden reverts are forbidden even when
  used to revert what looks like your own scope drift.

## Safety Rules

- Do not run destructive, provisioning, host-mutation, or repair/adoption flows
  against standing live nodes.
- Standing live-node checks on gateway, beast, and mini must stay read-only or
  idempotent.
- Provisioning and destructive validation must use only the ephemeral nodes or
  VMs described in `TESTING.md`.
- Agents must not use `git stash`, `git reset --hard`, `git checkout --`, broad
  `git restore`, or hidden reverts. This applies even when an agent believes a
  tracked diff is its own out-of-scope drift; see Concurrent Worktree Hygiene.
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
4. Record proposals and route them back to the owning implementer, scout,
   reviewer, or orchestrator.
5. Ask the user only for genuine product direction that cannot be decided from
   current docs, `docs/PORTING.md`, and legacy evidence.

## Completion

The loop is complete only when:

- all todos in the current porting scope are completed or explicitly deferred
  with evidence;
- every completed implementation todo has reviewer approval evidence;
- intentional changes are committed to `main`;
- applicable E2E validation in `TESTING.md` has passed or a tracked blocker
  explains why it cannot run yet;
- discovered follow-up work is captured in Solo and, when durable, in
  `docs/PORTING.md`.
