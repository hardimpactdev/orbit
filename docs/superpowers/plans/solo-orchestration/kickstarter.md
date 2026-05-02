# Kickstarter Procedure

This is a coordinator-run procedure for starting or resuming the Solo
orchestration loop.

Do not spawn a Solo agent just to execute this file. The current human-facing
agent or coordinator reads this procedure and performs the steps directly.

## Configuration

Use these variables for this run unless the user provides overrides:

```env
PORTING_TRACKER=docs/PORTING.md
PIPELINE_READY_TARGET=4

ORCHESTRATOR_AGENT=claude-sonnet
PIPELINE_FILLER_AGENT=claude-opus
TODO_SCOUT_AGENT=gemini-3.1-pro-preview
REVIEWER_AGENT=gemini-3.1-pro-preview
LOOP_IMPROVER_AGENT=claude-sonnet
IMPLEMENTATION_AGENT=opencode-kimi-k2.6
RUBBER_DUCK1=gemini-3.1-pro-preview
RUBBER_DUCK2=claude-opus
E2E_AGENT=claude-opus
```

Agent variable format:

`<cli app>-<model>-<model-version>-<reasoning/thinking>`

The final reasoning/thinking segment may be omitted. When omitted, use that
CLI/model's configured default.

Examples:

- `opencode-kimi-2.6`
- `codex-gpt-5.5-xhigh`
- `claude-opus-4.7`

The canonical run-config schema lives in
`README.md` → "Run Config KV". Resolve the variables once at startup, create
or select the coordination todo, and seed `solo-orchestration/run-config`
using that schema. After the loop improver is active, it owns runtime
loop-control fields in that key. Do not hard-code coordination todo ids,
queue targets, or agent/model choices when a variable exists.

If a configured agent is not available in Solo, stop with `NEEDS_DIRECTION`
instead of silently substituting a different model.

## Run Config Write

After resolving the defaults and user overrides, write exactly one runtime
configuration record with Solo `kv_set` to `solo-orchestration/run-config`.
The schema is canonical in `README.md`. Map the uppercase defaults to the
runtime keys:

- `PORTING_TRACKER` -> `porting_tracker`
- `PIPELINE_READY_TARGET` -> `pipeline_ready_target`
- `ORCHESTRATOR_AGENT` -> `agents.orchestrator`
- `PIPELINE_FILLER_AGENT` -> `agents.pipeline_filler`
- `TODO_SCOUT_AGENT` -> `agents.todo_scout`
- `REVIEWER_AGENT` -> `agents.reviewer`
- `LOOP_IMPROVER_AGENT` -> `agents.loop_improver`
- `IMPLEMENTATION_AGENT` -> `agents.implementation`
- `RUBBER_DUCK1` -> `agents.rubber_duck_1`
- `RUBBER_DUCK2` -> `agents.rubber_duck_2`
- `E2E_AGENT` -> `agents.e2e`

On a fresh loop start, create a new coordination todo before writing
`solo-orchestration/run-config`. Suggested title:

```text
SOLO-RUN <run_id> Coordination
```

Use tags such as `orchestration`, `coordination`, and `active-run`. Store the
new todo id in `coordination_todo`.

On resume, read the existing `solo-orchestration/run-config` first. Reuse its
`coordination_todo` only when resuming the same active `run_id`. If this is a
new loop run, create a fresh coordination todo, remove `active-run` from the
previous coordination todo when it is still open, and write a new `run_id` plus
new `coordination_todo` to `solo-orchestration/run-config`.

If the user changed configuration for the same run, overwrite the whole KV
value with the resolved configuration and record that change on the current
coordination todo.

## Mission

Start or resume the Solo implementation loop without implementing code yourself.
The job is to make sure the right long-running roles exist, know their prompts,
and have the context listed in this file to keep moving on timers.

## Inputs

Read:

- `docs/superpowers/plans/solo-orchestration/README.md`
- this file
- `solo-orchestration/run-config` when resuming a loop
- `docs/PORTING.md`
- Solo scratchpad `131`
- Solo scratchpad `132` for loop-level pipeline/template friction notes
- Solo agent-tool list, so configured agents can be matched before spawning
- current Solo todos, comments, locks, timers, and process list
- structured loop state per `README.md` "Loop State Sources":
  `kv_get solo-orchestration/run-config`,
  `kv_list solo-orchestration/dispatch/*`, todo `locked_by`, blocker links,
  completion state, and tags
- `git status --short --branch`

## Actions

1. Resolve the configuration variables, applying any user-provided overrides.
2. Decide whether this is a fresh start or a resume. For a fresh start, create
   a new coordination todo and use its id. For a resume, reuse the existing
   `coordination_todo` only when the existing `run_id` is the active run being
   resumed.
3. Write `solo-orchestration/run-config` with the resolved configuration:
   owner, `run_id`, porting tracker, coordination todo,
   `pipeline_ready_target`, agent/model choices, and `synced_at`. This is the
   initial seed; after handoff, the loop improver owns runtime loop-control
   updates.
4. Select the correct Solo project.
5. List Solo agent tools and verify each configured agent needed immediately is
   available.
6. Check whether an orchestrator is already active. If not, spawn one using
   `ORCHESTRATOR_AGENT` and `orchestrator.md`.
7. Check whether a loop improver is already active. If not, spawn one using
   `LOOP_IMPROVER_AGENT` and `loop-improver.md`.
8. For each process you spawn, use the startup handshake from
   `solo-orchestration/README.md`: check `get_process_status`, inspect
   `get_process_output`, deliver a compact bootstrap prompt with `send_input`,
   and verify prompt delivery before assuming the role is active. The bootstrap
   prompt must name `solo-orchestration/run-config`,
   `docs/superpowers/plans/solo-orchestration/README.md`, and the role file
   `docs/superpowers/plans/solo-orchestration/<role>.md`, and require the role
   to read all three before acting. Do not paste the full role prompt into the
   spawned process unless the role file is unreachable and the loop is already
   stopped for `NEEDS_DIRECTION`.
9. If a newly spawned orchestrator or loop improver is still rendering
   a startup screen, schedule an idle-triggered Solo timer instead of declaring
   failure. Retry prompt delivery once before closing and replacing a process.
10. Reconcile structured loop state with reality. For each
    `solo-orchestration/dispatch/<todo_id>` and
    `solo-orchestration/dispatch/pipeline-filler` KV record, confirm the named
    process is still live. Clear records that point at processes that no
    longer exist so the orchestrator does not interpret them as live
    dispatches. On a clean restart with no agents running, expect these
    records to be empty and clear any leftovers before dispatch resumes.
11. Record a checkpoint on the coordination todo with:
   - active process ids;
   - `solo-orchestration/run-config` value;
   - current worker-ready todos (filtered on `is_blocked=false`,
     `worker-ready` tag, no `solo-orchestration/dispatch/<todo_id>` record,
     `locked_by=null`);
   - blocked todos and blockers;
   - active `solo-orchestration/dispatch/*` KV records;
   - current git status;
   - next expected timer.

## Boundaries

- Do not implement code.
- Do not run tests.
- Do not dispatch implementation workers yourself unless no orchestrator exists
  and the user explicitly asks for one-shot recovery.
- Do not create broad future-work backlogs. Create only the coordination todo if
  needed.

## Handoff

After the orchestrator and loop improver are active, stop kickstarting. The
orchestrator owns implementer/reviewer assignment. The pipeline filler owns
todo readiness through scouts. The loop improver owns durable loop prompt
improvement and scratchpads `131` and `132`.
