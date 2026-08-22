# Orbit Eval Trial Isolation

Every state-modifying eval trial needs a clean start, hidden answer keys, captured outcome, and reset story.

## Isolation Checklist

Before each trial, record:

- `trial_id`, `case_id`, `attempt_index`
- worktree, branch, or sandbox
- database, temp path, config root, retained topology id, or other mutable state boundary
- model, prompt, tools, agent harness, eval harness, and user simulation when applicable
- visible fixtures and hidden grader material
- start-state snapshot

## Clean-State Options

Use the lightest boundary that prevents contamination:

- isolated git worktree for file edits
- temp directory for generated files
- isolated SQLite database or configured test database
- reset command for local state
- retained topology only when the behavior needs real VM or integrated topology proof
- fresh worker window via `bin/orbit-worker-spawn` when prior conversation would leak context

Shared state is unsafe when cached data, previous trial traces, answer keys, git history, generated artifacts, provider resources, or environment variables can influence the next attempt.

## Hidden Answer-Key Rules

- Do not expose expected hidden outputs, grader code, rubric internals, reference solution details, or previous trial transcripts to the agent under test.
- Keep the public task statement separate from private grading notes.
- Record which files are visible to the agent under test.
- If leakage occurs, mark the trial invalid and restart with a clean context.

## Transcript And Outcome Capture

Capture both:

- transcript or trajectory: messages, tool calls, observations, intermediate outputs, final assistant response
- outcome: final filesystem, database, JSON, topology state, process state, command side effects, or other externally inspectable facts

Do not let a final claim of success substitute for an outcome check.

## Reset And Teardown

After each trial, record:

```yaml
reset_or_teardown:
  commands:
    - command:
      cwd:
      result:
  remaining_state:
  next_trial_safe: true | false
```

If reset cannot be proven, do not aggregate the next trial with independent trials.

## Flake Classification

Classify failures before scoring:

- `agent`: the agent failed the task despite valid harness and environment
- `grader`: scorer bug, ambiguous rubric, or missing evidence handling
- `harness`: eval runner, fixture, prompt, or capture defect
- `infrastructure`: provider outage, transient command failure, resource exhaustion, or unrelated environment failure

Only agent failures should count as agent capability failures. Grader, harness, and infrastructure failures should trigger eval repair or rerun decisions.

## Orbit Boundaries

- Use Orbit worktree setup and verification conventions.
- Never run, invoke, dispatch, delegate, schedule, or trigger a
  `composer test:e2e*` command, including through the agent under test. Only a
  human may manually invoke the Composer command from a shell; agents may
  inspect the resulting artifact.
- Use retained topology proof when the evaluated behavior touches integrated topology, VM runtime, node behavior, or operator-visible CLI behavior that cannot be judged locally.
