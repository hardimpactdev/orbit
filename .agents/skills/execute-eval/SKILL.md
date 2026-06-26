---
name: execute-eval
description: Use when running Orbit eval cases, agent eval trials, regression evals, scorer checks, or eval harness experiments that need isolated execution, transcript capture, outcomes, grader results, and structured eval-run artifacts.
---

# Execute Eval

## Overview

Run Orbit evals as isolated trials and record what happened. Capture transcript, trajectory, final outcome, and grader evidence separately so failures can be debugged and aggregated.

## Reference Map

Load only what the run needs:

- `../_orbit-eval-references/eval-artifact-schema.md` for `eval-run` and `eval-trial` fields.
- `../_orbit-eval-references/trial-isolation.md` for clean-state, answer-key, reset, and flake rules.
- `../_orbit-eval-references/scorer-selection.md` when applying or calibrating graders.
- `../_orbit-eval-references/orbit-eval-principles.md` for repeated sampling, pass@k, pass^k, and release-gate boundaries.

## Workflow

1. Read the eval artifacts.
   - Confirm each case has a clear intent, expected behavior, end-state checks, reference solution or known examples, scorer, fixtures, and risk notes.
   - Refuse to run cases whose answer keys or hidden grader internals would be visible to the agent under test.

2. Prepare clean execution.
   - Use existing Orbit setup rules and project skills.
   - For state-modifying evals, isolate by worktree, sandbox, database, temp path, retained topology, or explicit reset.
   - Do not run `composer test:e2e*` unless the user explicitly invokes the relevant Composer E2E command.

3. Execute one trial at a time.
   - Record `trial_id`, `case_id`, `attempt_index`, working directory, model, agent harness, eval harness, command or prompt, user simulation when applicable, start state, and environment snapshot.
   - Capture transcript or trajectory, including messages, tool calls, intermediate observations, and final response.
   - Capture final outcome separately: files, DB rows, JSON, process state, topology facts, command side effects, or other observable state.

4. Grade and reset.
   - Run deterministic graders before model or human graders when both apply.
   - Record grader results per trial, including `Unknown` or inconclusive results when evidence is insufficient.
   - Record reset or teardown steps before starting the next trial.
   - Classify infrastructure failure separately from genuine agent failure.

5. Aggregate only after evidence exists.
   - Use repeated trials for nondeterministic agents.
   - Report pass@k, pass^k, confidence intervals, paired comparisons, or the reason they are not applicable.
   - Store `eval-run` and `eval-trial` artifacts in a Solo scratchpad unless the user requested durable repo fixtures.

## Output Contract

Return:

- run id, suite id, target, agent harness, eval harness, model, environment, and isolation method
- trial records with transcript refs, outcome refs, grader results, reset notes, verdict, duration, and cost
- aggregate scores and nondeterminism notes
- failures split into agent, grader, harness, and infrastructure categories
- evidence location and residual risks

## Stop Conditions

Stop when isolation cannot be proven, hidden answer keys would leak, required fixtures are missing, an eval would mutate live or shared state without approval, or Orbit verification rules require retained topology proof that cannot be completed inside the current slice.
