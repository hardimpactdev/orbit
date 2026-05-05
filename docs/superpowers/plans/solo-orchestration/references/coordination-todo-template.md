# Coordination Todo Template

Reference for creating the single coordination todo at the start of each loop
run.

The coordination todo is the run's audit and comm log. Every cycle, every
helper, and every reconciler outcome lands here as a lifecycle comment. It is
not a unit of work — no worker, reviewer, or E2E role ever picks it up.

## Required Shape

- One coordination todo per run.
- Title: `Solo orchestration coordination — run <run_id>`.
- No phase tag. Optional `coordination` tag for filtering.
- No assignee, no worktree, no blockers.
- Open for the duration of the run; closed manually after the run ends.
- `control-config.md`'s `coordination_todo` points at this todo's id.

## Body Template

````markdown
### Purpose

Audit log for Solo orchestration run `<run_id>`. Lifecycle comments are
appended here by the loop clock, orchestrator, reconciler, pipeline filler,
and helper roles per
`docs/superpowers/plans/solo-orchestration/README.md`.

### Run Parameters

- run_id: <run_id>
- started_at: <YYYY-MM-DD HH:MM>
- control_config: docs/superpowers/plans/solo-orchestration/control-config.md

### Expected Comment Vocabulary

See the Lifecycle Labels section of
`docs/superpowers/plans/solo-orchestration/README.md`. Labels include
`CYCLE_STARTED`, `CYCLE_DONE`, `RECONCILE_*`, `PIPELINE_FILL_*`,
`RECONCILIATED`, and routing labels emitted by the orchestrator.

### Stop Conditions

- The loop clock is disabled in `control-config.md` and no live cycles
  remain.
- The user requests run shutdown.
````

## Replacement Rule

A new run gets a new coordination todo. Do not reuse an existing one across
runs. When initiating a loop, close any prior coordination todo before
creating the fresh one and updating `control-config.md`.
