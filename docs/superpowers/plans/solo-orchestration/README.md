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
   after `WORKER_DONE`, spawns rubber duck pairs to attempt automated unblocks,
   spawns the E2E runner after a command's batch commits, and closes todos
   after reviewer approval.
3. **Pipeline Filler** is a one-shot role spawned by the orchestrator when the
   ready queue is below target. It reads `docs/PORTING.md`, creates or refreshes
   draft todos including each command's E2E gate todo, and spawns one
   mandatory todo scout per candidate before any todo can become
   `worker-ready`.
4. **Todo Scout** is a one-shot role spawned by the pipeline filler for exactly
   one draft todo. It performs deep achievability and ambiguity review, may
   refine the todo, validates E2E gate todos against `TESTING.md`, and posts
   `SCOUT_REPORT`.
5. **Reviewer** is a one-shot role spawned by the orchestrator after an
   implementer posts `WORKER_DONE`. It reviews one task's diff, scope, docs
   alignment, focused gate evidence, and safety.
6. **Loop Improver** is a long-running improvement role. It watches the loop
   across cycles and keeps orchestration docs plus scratchpads `131` and `132`
   self-correcting.
7. **Implementer** owns exactly one todo, including the E2E test that
   proves the feature works against the command's declared lane. The
   implementer runs the declared lane locally before `WORKER_DONE` and
   iterates until both the focused gate and the E2E lane pass.
8. **Rubber Duck** is a one-shot role spawned by the orchestrator in pairs
   (two different configured tool types) when an implementer or reviewer
   reports `BLOCKED` or `NEEDS_DIRECTION`. Each duck independently proposes
   one path or asks for user direction. The orchestrator auto-resumes only on
   unanimous agreement with concrete evidence-stack citations.
9. **E2E** is a one-shot role spawned by the orchestrator at the end of a
   command port. It re-runs the implementer-authored lane in a clean state
   (`live-smoke`, `ephemeral`, `both`, or `none`) per `TESTING.md` and posts
   `E2E_DONE`. It does not author tests, modify code, or iterate on
   failures. On `FAILED`, the orchestrator routes the failure back to the
   implementer who owns the relevant todo.

The implementer/E2E seam: implementer authors and is responsible for making
E2E pass; E2E only verifies in a clean state. This catches
environment-dependent failures, missed cleanups, and regressions in
adjacent commands without blurring ownership of the feature.

## Shared Inputs

Every role reads only the context it needs:

- this `README.md`
- `solo-orchestration/run-config` for resolved queue targets, coordination
  todo, and agent/model choices
- the role's prompt file in `docs/superpowers/plans/solo-orchestration/`
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
4. spawns or resumes exactly the long-running roles needed by the loop:
   orchestrator and loop improver;
5. uses the startup handshake for each spawned role;
6. records the active process ids and queue state on the coordination todo;
7. exits from coordination mode once those long-running roles are active.

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

## Role Prompt Files

Repo files in `docs/superpowers/plans/solo-orchestration/` are the single
source of truth for role behavior. Agents read them directly; tool calls
return current on-disk content, so prompt edits take effect on the next read.

Spawned roles receive a compact bootstrap prompt that points to the role file
and tells the agent to read it before acting. The bootstrap prompt is not the
durable role definition.

Use this shape for spawned role prompts:

```text
You are the <role> for Solo project <id>.

Before acting, read:
- kv key: solo-orchestration/run-config
- docs/superpowers/plans/solo-orchestration/README.md
- docs/superpowers/plans/solo-orchestration/<role>.md
- the assigned todo/comment/KV context listed below

Then follow the role file as your role authority. If the run config or role
file is missing, stop with NEEDS_DIRECTION.

Assignment:
- ...
```

Long-running roles must re-read their role file on every timer tick before
inspecting queue or process state. This is an intentional live control
surface: the loop improver edits the repo file and the change takes effect on
the next tick without restarting the process.

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
   bootstrap prompt with `send_input`.
4. Verify prompt delivery by checking rendered output, a `WORKER_STARTED`
   comment, a `SCOUT_REPORT` start, or the role-specific lifecycle label.
5. If prompt-delivery evidence is absent, schedule an idle-triggered timer or
   one short follow-up check before retrying.
6. Retry prompt delivery once when the process is alive and input-capable but no
   prompt evidence appears.
7. Only after the retry/grace window fails, post
   `PROMPT_RECOVERY status=REPLACED`, close the stale process when replacement
   is required, and spawn one replacement.

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
4. **KV dispatch record** (`kv_get solo-orchestration/dispatch/<todo_id>`)
   — the current process id and role (`scout`, `implementer`, or `reviewer`)
   working on this todo. The orchestrator writes this on dispatch and clears
   it on close-out or recovery. If the KV record says `process=569` but a
   `WORKER_STARTED` comment names `process=562`, trust the KV record; the
   comment is historical. Phase comes from tags, not from this record.
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
locks and dispatch KV decide ownership, blockers decide eligibility, tags
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
- `in-progress`: an implementer or reviewer is actively working. Disambiguate
  via the `role` field on `solo-orchestration/dispatch/<todo_id>`.
- `review-ready`: implementer posted handoff evidence and released the lock;
  awaiting reviewer dispatch.
- `needs-direction`: product or architecture direction is needed.
- `verified`: reviewer approved final evidence and scope; awaiting orchestrator
  close-out.

Attention tags may coexist with one phase tag:

- `changes-requested`: reviewer found in-scope issues for the worker to fix.
- `e2e-failed`: the E2E stage produced `E2E_DONE status=FAILED` for this
  command's gate todo. Coexists with `verified` on the gate when an
  implementation todo is being re-opened to fix the failure.

Remove `changes-requested` only after the worker has addressed the finding and
the reviewer has verified the new evidence. Remove `e2e-failed` only after a
new commit reaches the E2E stage and produces `E2E_DONE status=PASSED`. Never
use either as the only state for an active todo; keep `in-progress` or
`review-ready` so ownership remains visible.

Tag transitions must respect todo locks. The actor that owns `locked_by` owns
tag mutation until it releases the lock. If a coordinator, orchestrator, or
reviewer detects a tag mismatch on a locked todo, it records the expected state
and instructs the lock owner to update tags. It may update assignment KV when
safe, but it must not treat a failed tag write against another actor's lock as
permission to unlock or overwrite the todo.

## Dispatch KV Schema

KV is the structured ownership record. Tags are the structured phase record.
KV no longer carries phase state; read tags for phase.

Stable keys:

- `solo-orchestration/run-config` — resolved run configuration written by the
  kickstarter and read by every role.
- `solo-orchestration/dispatch/<todo_id>` — current primary dispatch on this
  todo. Only one role at a time per todo (scout while `draft`, implementer
  while `worker-ready`/`in-progress`, reviewer while
  `review-ready`/`in-progress`, e2e while a command's gate todo is being
  verified).
- `solo-orchestration/dispatch/<todo_id>/duck/<1|2>` — short-lived sub-keys
  used only when the orchestrator runs a rubber duck pair against a blocked
  todo. Cleared once both `RUBBER_DUCK_PROPOSAL` comments are consumed and
  `RUBBER_DUCK_RESOLVED` is posted.
- `solo-orchestration/dispatch/pipeline-filler` — the singleton one-shot
  filler when one is running:
  `{ "process_id": <id>, "started_at": "<ISO-8601>" }`. Cleared on
  `PIPELINE_FILL_DONE`.

Per-todo dispatch record:

```json
{
  "todo_id": 191,
  "process_id": 569,
  "role": "scout|implementer|reviewer|rubber_duck|e2e",
  "assigned_at": "<ISO-8601>",
  "agent_tool_id": 2
}
```

Rubber duck dispatch records add a `slot` field (`1` or `2`) so the
orchestrator can match a process to its proposal slot.

Ownership of the per-todo dispatch records:

- The orchestrator writes implementer, reviewer, rubber duck, and e2e records
  on dispatch and clears them on close-out or recovery.
- The pipeline filler writes scout records before spawning a scout and clears
  them after `SCOUT_REPORT` has been consumed.

The loop improver reads these records but does not dispatch product work. On
a clean kickstart with no active agents, the kickstarter clears stale
`dispatch/*` records before dispatch resumes.

When recovering from a restart or compaction, prefer reading these records
over scrolling comments. If a record points at a process that no longer
exists, the orchestrator clears the record and follows Implementer Recovery.

## Lifecycle Labels

Use these exact labels in Solo comments so work can resume after compaction.
Labels are audit evidence layered on top of the structured state above; they do
not replace it:

- `PIPELINE_READY`: todo is unblocked, scoped, tagged `worker-ready`, and ready
  for assignment.
- `SCOUT_REPORT status=READY|BLOCKED|NEEDS_DOCS|SCOPE_TOO_BROAD|NEEDS_DIRECTION`:
  scout completed deep todo validation.
- `WORKER_STARTED process=<id>`: implementer or reviewer confirmed receipt of
  the role prompt and assignment. Implies prior `ASSIGNED` and
  `PROMPT_DELIVERED` semantics.
- `WORKER_DONE status=DONE|DONE_WITH_CONCERNS|BLOCKED|NEEDS_DIRECTION`:
  implementer handoff result.
- `REVIEW_APPROVED`: reviewer approved lifecycle, gate evidence, scope, and
  locks.
- `CHANGES_REQUESTED`: reviewer found in-scope issues for the implementer to
  fix.
- `RUBBER_DUCK_PROPOSAL agent=<name> verdict=PATH|NEEDS_USER_DIRECTION`:
  one rubber duck of a pair posted its independent proposal.
- `RUBBER_DUCK_RESOLVED status=AGREED|ESCALATED`: orchestrator compared both
  duck proposals and either auto-resumed the implementer (AGREED) or surfaced
  to the user (ESCALATED).
- `E2E_DISPATCHED process=<id> lane=<live-smoke|ephemeral|both|none>`:
  orchestrator spawned the e2e runner for a command's gate todo.
- `E2E_DONE status=PASSED|FAILED|SKIPPED lane=<name>`: e2e runner finished;
  see the body for command-by-command exit codes and any failure summary.
- `ORCHESTRATOR_CLOSED`: orchestrator closed the todo lifecycle.
- `PROMPT_RECOVERY status=STALLED|RETRIED|REPLACED process=<id>`: prompt
  delivery had a fair chance, did not produce evidence, and recovery was
  performed.
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

When an implementer or reviewer reports a blocker:

1. The implementer keeps the todo open and posts
   `WORKER_DONE status=BLOCKED|NEEDS_DIRECTION` (or the reviewer posts
   `NEEDS_DIRECTION`).
2. The orchestrator runs the **Blocker Resolution** flow in
   `orchestrator.md`: it spawns a rubber duck pair from
   `agents.rubber_duck_1` and `agents.rubber_duck_2`. Each duck independently
   reads the blocker and the Decision Evidence Stack and posts one
   `RUBBER_DUCK_PROPOSAL`.
3. The orchestrator auto-resumes the implementer only when both ducks return
   `verdict=PATH`, both cite at least one concrete reference from the
   Decision Evidence Stack, and their proposed paths describe the same
   approach. Otherwise it posts `RUBBER_DUCK_RESOLVED status=ESCALATED` and
   surfaces to the user.
4. When a blocker is large enough to deserve its own owner — for example a
   docs/architecture decision the user wants worked rather than answered
   directly — the orchestrator (or the user) creates a focused decision/audit
   todo. Decision/audit todos must require the worker to resolve from the
   Decision Evidence Stack.
5. Ask the user only for genuine product direction that cannot be decided
   from current docs, `docs/PORTING.md`, and legacy evidence — typically when
   the rubber duck pair has already escalated.

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
