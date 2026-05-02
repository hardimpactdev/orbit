# Kickstarter Procedure

This is a coordinator-run procedure for starting or resuming the Solo
orchestration loop.

Do not spawn a Solo agent just to execute this file. The current human-facing
agent or coordinator reads this procedure and performs the steps directly.

## Configuration

Use these variables for this run unless the user provides overrides:

```env
PORTING_TRACKER=docs/PORTING.md
COORDINATION_TODO=190
PIPELINE_READY_TARGET=2

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

Resolve the variables once at startup and pass the resolved configuration
verbatim to every spawned orchestrator, pipeline filler, todo scout, reviewer,
loop improver, implementer, rubber-duck, and E2E tester. Do not hard-code
coordination todo ids, queue targets, or agent/model choices when a variable
exists.

If a configured agent is not available in Solo, stop with `NEEDS_DIRECTION`
instead of silently substituting a different model.

## Mission

Start or resume the Solo implementation loop without implementing code yourself.
The job is to make sure the right long-running roles exist, know their prompts,
and have the context listed in this file to keep moving on timers.

## Inputs

Read:

- `docs/superpowers/plans/solo-orchestration/README.md`
- this file
- `docs/PORTING.md`
- Solo scratchpad `131`
- Solo scratchpad `132`
- Solo agent-tool list, so configured agents can be matched before spawning
- current Solo todos, comments, locks, timers, and process list
- structured loop state per `README.md` "Loop State Sources":
  `kv_list solo-orchestration/assignment/*`,
  `kv_list solo-orchestration/reviewer/*`,
  `kv_list solo-orchestration/scout/*`,
  `kv_list solo-orchestration/pipeline-filler/*`, todo `locked_by`, blocker
  links, completion state, and tags
- `git status --short --branch`

## Actions

1. Resolve the configuration variables, applying any user-provided overrides.
2. Select the correct Solo project.
3. List Solo agent tools and verify each configured agent needed immediately is
   available.
4. Identify the coordination todo for this run, or create one if none exists.
5. Check whether an orchestrator is already active. If not, spawn one using
   `ORCHESTRATOR_AGENT` and `orchestrator.md`.
6. Check whether a loop improver is already active. If not, spawn one using
   `LOOP_IMPROVER_AGENT` and `loop-improver.md`.
7. For each process you spawn, use the startup handshake from
   `solo-orchestration/README.md`: check `get_process_status`, inspect
   `get_process_output`, deliver the role prompt with `send_input`, and verify
   prompt delivery before assuming the role is active.
8. If a newly spawned orchestrator or loop improver is still rendering
   a startup screen, schedule an idle-triggered Solo timer instead of declaring
   failure. Retry prompt delivery once before closing and replacing a process.
9. Tell the orchestrator to spawn a one-shot pipeline filler using
   `PIPELINE_FILLER_AGENT` and `pipeline-filler.md` whenever a timer tick finds
   fewer than `PIPELINE_READY_TARGET` open, unblocked todos tagged
   `worker-ready`.
10. Reconcile structured loop state with reality. For each
    `solo-orchestration/assignment/<todo_id>` or
    `solo-orchestration/reviewer/<todo_id>` or
    `solo-orchestration/scout/<todo_id>` or
    `solo-orchestration/pipeline-filler/*` KV record, confirm the named process
    is still live. Clear records that point at processes that no longer exist
    so the orchestrator does not interpret them as live dispatches. On a clean
    restart with no agents running, expect these records to be empty and clear
    any leftovers before dispatch resumes.
11. Record a checkpoint on the coordination todo with:
   - active process ids;
   - resolved configuration;
   - current worker-ready todos (filtered on `is_blocked=false`,
     `worker-ready` tag, no `solo-orchestration/assignment/<todo_id>` record,
     `locked_by=null`);
   - blocked todos and blockers;
   - active `solo-orchestration/assignment/*` and
     `solo-orchestration/reviewer/*`,
     `solo-orchestration/scout/*`, and
     `solo-orchestration/pipeline-filler/*` KV records;
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
