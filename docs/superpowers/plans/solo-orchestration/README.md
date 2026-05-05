# Solo Orchestration Loop

Source of truth for Orbit's Solo orchestration loop.

## Core Shape

- One persistent loop clock exists only to wake, read `loop-clock.md`, spawn
  the configured orchestrator agent, and set the next timer.
- Every orchestrator is a fresh one-shot Solo agent. It runs one cycle,
  records what happened, and exits.
- Helper roles are one-shot Solo agents spawned by the orchestrator.
- Runtime knobs live in
  `docs/superpowers/plans/solo-orchestration/control-config.md`. Read that file
  every wake/cycle. The old `Solo Orchestration Control` scratchpad is not a
  runtime authority.
- Worker todo shape lives in
  `docs/superpowers/plans/solo-orchestration/references/worker-todo-template.md`.
  Read that file when creating or validating worker todos.
- Family-review todo shape extends the worker template in
  `docs/superpowers/plans/solo-orchestration/references/family-review-todo-template.md`.
  Read it when creating or validating todos tagged `family-review`.
- Dispatch KV is deprecated. Use tags, locks, blockers, process state, timers,
  and lifecycle comments.
- There is no kickstarter role, long-running orchestrator, or loop improver.

## Roles

- `loop-clock.md`: persistent clock. No orchestration decisions.
- `orchestrator.md`: one fresh cycle. Spawns reconciler, E2E, reviewers, and
  rubber ducks in parallel, then waits for reconciliation, then runs pipeline
  filler, then dispatches workspace setups and implementers.
- `reconciler.md`: merge every passed-E2E branch to `main` and close the
  long-lived implementer for each merged todo. Posts `RECONCILIATED` when
  done. Conflict handling rules live here.
- `pipeline-filler.md`: apply prior scout outcomes, re-validate held todos
  against fresh `main`, fill to `pipeline.ready_target`, and spawn one scout
  per new or refreshed draft.
- `todo-scout.md`: validate exactly one draft todo before it can become
  `worker-ready`.
- `references/worktree/setup.md`: prepare one todo's isolated git worktree
  before implementation dispatch.
- `implementer.md`: long-lived. Implement, post `WORKER_DONE`, then stay open
  for `send_input` feedback from reviewer / E2E / duck resolution. Closed by
  the reconciler on merge.
- `reviewer.md`: review exactly one `review-ready` todo. Pulls main into the
  worktree first; on findings, sends feedback to the long-lived implementer.
- `rubber-duck.md`: independently resolve one blocker, in a pair.
- `e2e.md`: run one gate todo's declared lane or one approved implementation
  todo's lane. Pulls main into the worktree first; on failure, sends feedback
  to the long-lived implementer and flips the todo back to `in-progress`.

The implementer/E2E boundary is strict: implementers author and pass E2E
coverage locally; E2E re-verifies in a clean state.

Every passed-E2E branch lands on `main` (reconciler) before any new
implementer is dispatched (orchestrator step 9), so new work always starts
from fresh `main`.

## Initiating The Loop

When the user asks to start or resume the loop, follow
`docs/superpowers/plans/solo-orchestration/references/loop-initiation.md`.

## Control Config

Runtime control lives in:

```text
docs/superpowers/plans/solo-orchestration/control-config.md
```

Default shape:

```yaml
run_id: <active-run-id>
coordination_todo: <active coordination todo id>

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
  loop_clock: claude-sonnet-low
  orchestrator: claude-sonnet-low
  reconciler: claude-opus-xhigh
  pipeline_filler: claude-opus-xhigh
  workspace_setup: claude-sonnet-low
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

Do not write runtime control values to scratchpads or KV.

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
- docs/superpowers/plans/solo-orchestration/control-config.md
- docs/superpowers/plans/solo-orchestration/references/worker-todo-template.md when todo shape matters
- the assigned todo/comment/process context below

Assignment:
- ...
```

If required prompt files, config files, or assignment context are missing, the
role stops with `NEEDS_DIRECTION`.

## Shared Authority

Use this evidence order when product or architecture choices fork:

1. Current docs as product authority.
2. `docs/PORTING.md` for migration order and tracker state.
3. `../orbit-old-may` as legacy implementation evidence.
4. Current code, tests, and todo comments as implementation evidence.

Current docs decide product behavior. Current implementation and the old repo
are evidence, not automatic authority.

The orchestration loop should create Pest E2E work using
`composer test:e2e:provision`, `composer test:e2e:features`, and
`composer e2e:prepare-topology`, or create a docs-refresh todo when the tracker
needs correction.

## Solo State Model

Structured state wins over comments when they disagree.

Read these together:

1. `control-config.md`: run id, queue target, clock interval, pause switches,
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
- `e2e-ready`: open, unblocked, scoped E2E gate approved for the E2E role.
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
- `RECONCILE_STARTED process=<id>`
- `RECONCILE_DONE status=MERGED|FAILED|NEEDS_DIRECTION`
- `RECONCILIATED`
- `RECONCILE_CYCLE_DONE status=DONE|BLOCKED|NEEDS_DIRECTION`
- `PIPELINE_FILL_STARTED process=<id>`
- `PIPELINE_FILL_DONE status=DONE|BLOCKED|NEEDS_DIRECTION`
- `SCOUT_REPORT status=READY|BLOCKED|NEEDS_DOCS|SCOPE_TOO_BROAD|NEEDS_DIRECTION`
- `WORKER_STARTED process=<id>`
- `WORKER_DONE status=DONE|DONE_WITH_CONCERNS|BLOCKED|NEEDS_DIRECTION`
- `REVIEW_APPROVED`
- `CHANGES_REQUESTED`
- `RUBBER_DUCK_PROPOSAL agent=<name> verdict=PATH|NEEDS_USER_DIRECTION`
- `RUBBER_DUCK_RESOLVED status=AGREED|ESCALATED`
- `E2E_DISPATCHED process=<id> lane=<e2e-provision|e2e-feature|none>`
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
- `RECONCILE <run_id>`
- `PIPELINE-FILLER <run_id> <YYYYMMDD-HHMMSS>`
- `WORKTREE-SETUP-<todo_id>`
- `SCOUT-<todo_id>`
- `IMPLEMENTER-<todo_id>` (long-lived; closed by reconciler on merge)
- `REVIEWER-<todo_id>`
- `DUCK-1 <todo_id>`
- `DUCK-2 <todo_id>`
- `E2E-<todo_id>`

Reconcile process names with locks, tags, lifecycle comments, and process
liveness before replacing anything.

## Solo Tooling Rules

- Read orchestration prompts and runtime control from
  `docs/superpowers/plans/solo-orchestration/**`.
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
- The pipeline filler applies prior `SCOUT_REPORT` outcomes, re-validates held
  todos against fresh `main`, creates or refreshes drafts up to the target,
  and spawns one scout per new or refreshed draft.
- A todo becomes `worker-ready` only after `SCOUT_REPORT status=READY` is
  applied by the next pipeline-filler run.
- Existing ready implementation work takes precedence over filling future
  queue capacity.
- Every command port ends with one E2E gate todo. Implementers author and run
  the lane; the E2E role reruns it after commit.
- Use Pest E2E lanes from `TESTING.md`.

## Manual Priority Todos

`docs/PORTING.md` drives the auto-filled port pipeline via the pipeline filler.
Other priority work — doc-driven feature changes, bug reports, ad-hoc requests —
is created manually as Solo todos by the user. When product docs shift in ways
that affect open work, the user revises sibling todos to match. Manually-created
todos enter the normal `draft → scout → worker-ready` flow and obey the same
queue rules.

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

## Worktree Isolation

Every implementation todo runs in its own git worktree and branch. The
orchestrator spawns the workspace setup helper before implementation dispatch.
Use `.worktrees/solo-<todo-id>` as the default local path and `solo-<todo-id>`
as the default branch name unless that branch already exists or the todo needs a
clearer unique suffix. That worktree is the only checkout the worker may use for
implementation, focused gates, and feature E2E evidence. Workers must not
implement from `main` or from a shared dirty checkout.

Worktree preparation means enough Laravel setup for the worker's assigned
focused gates to run without rediscovering bootstrap steps. At minimum, the
orchestrator should install Composer dependencies, ensure the app environment is
usable for tests, and record the worktree path plus prep evidence on the todo
before spawning the implementer. If preparation fails, leave the todo
non-dispatched and route the failure instead of asking the worker to start from
a broken checkout. The operational command sequence lives in the workspace setup
prompt at
`docs/superpowers/plans/solo-orchestration/references/worktree/setup.md`.

Product authority docs are read-only for normal implementation todos. If the
assigned behavior conflicts with `docs/commands/**`, `docs/abstractions/**`,
`docs/BLUEPRINT.md`, `docs/MISSION.md`, or `docs/CONCEPTS.md`, the worker marks
the todo `needs-direction` instead of changing the contract locally. Product
authority docs may change only through an explicit docs/design todo or direct
human instruction. Progress and accounting docs such as `docs/PORTING.md` may be
updated with implementation status, gate evidence, and accepted deferrals.

For `e2e-feature` gates, prepared topology images and templates are reusable
baselines. Feature assertions must install or overlay the assigned worktree's
checkout into disposable topology clones and run `php artisan <command>` from
that checkout. Do not bake feature code into images, mutate template instances,
or repoint the clone's steady-state `orbit` symlink to make a feature test see
new code.

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
