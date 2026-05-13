# Worker Todo Template

Reference for the pipeline filler and the todo scout when creating or
validating worker todos.

Use `docs/superpowers/plans/solo-orchestration/references/todo-state.md` for
phase tags, lifecycle labels, dispatch eligibility, and close-out rules.

## Required Shape

Every worker todo must state:

- objective;
- worktree assignment, once dispatched;
- scope and non-goals;
- sequencing and blockers;
- product authority;
- legacy evidence;
- owned files or domains;
- focused quality gate with exact Pest/PHP commands for implementation work;
- E2E lane, or `none` with a concrete reason;
- reviewer checks;
- stop conditions.

## Body Template

````markdown
### Objective

<Observable outcome. Describe behavior, not preferred internals.>

### Worktree Assignment

- Path: <filled by orchestrator at dispatch, for example .worktrees/solo-123>
- Branch: <filled by orchestrator at dispatch, for example solo-123>
- Base ref: <filled by orchestrator at dispatch>
- Prep evidence:
  - <filled by orchestrator, command plus exit code>

### Scope

- <in-scope behavior or files>

### Non-Goals

- <explicitly excluded work>

### Sequencing And Blockers

- Depends on: <todo ids or none>
- Blocks: <todo ids or none>
- Parallel-safe with: <todo ids or none>
- Known blockers: <decision, missing lane, safety issue, or none>

### Product Authority

- <docs/ARCHITECTURE.md, docs/BUILDING-BLOCKS.md, docs/commands/**, or other current docs>

### Legacy Evidence

- <../orbit-old-may paths to inspect, or none with reason>

### Owned Files Or Domains

- <paths, commands, domains, or services the worker may edit>

### Quality Gate

```bash
<focused command>
```

Run `vendor/bin/pint --dirty --format agent` when PHP changes.

### E2E Lane

- lane=<e2e-provision|e2e-feature|none>
- Commands: <exact commands or accepted deferral>
- Safety: <ephemeral requirement or no-runtime reason>

### Reviewer Checks

- <contract, scope, gates, E2E evidence, tracker, or safety checks>

### Stop Conditions

- <conditions that require NEEDS_DIRECTION instead of guessing>
````

## Validation

A worker todo can become `worker-ready` only when it matches this template and
the `todo-state.md` dispatch rules. An E2E gate todo can become `e2e-ready`
only when it declares a valid lane and command list and follows those same
state rules.

`Worktree Assignment` is intentionally blank while the todo is draft or
`worker-ready`. The orchestrator fills it immediately before implementer
dispatch. If the section is still blank after `WORKER_STARTED`, the worker must
stop with `NEEDS_DIRECTION`.
