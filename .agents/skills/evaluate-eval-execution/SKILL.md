---
name: evaluate-eval-execution
description: Use when reviewing whether an Orbit eval run, agent eval execution, scorer result, trial transcript set, or proposed eval gate was trustworthy, calibrated, reproducible, and useful enough to keep or revise.
---

# Evaluate Eval Execution

## Overview

Review the eval run itself. Decide whether the run measured the intended Orbit behavior, whether the evidence can be trusted, and how the suite should change.

## Reference Map

Load only what the review needs:

- `../_orbit-eval-references/eval-artifact-schema.md` for `eval-run-review` fields.
- `../_orbit-eval-references/orbit-eval-principles.md` for suite quality, saturation, statistics, and gate recommendations.
- `../_orbit-eval-references/trial-isolation.md` for isolation and contamination checks.
- `../_orbit-eval-references/scorer-selection.md` for grader calibration review.

## Workflow

1. Reconstruct the eval intent.
   - Read the suite, cases, run, trials, transcripts, outcomes, and grader results.
   - Name the Orbit behavior the eval claimed to measure.
   - Check whether the case source still matches current Orbit authority docs and product decisions.

2. Inspect evidence before scores.
   - Spot-read representative transcripts or trajectories, including failures and surprising passes.
   - Compare final environment outcomes to end-state checks.
   - Treat final assistant claims or command stdout as supporting evidence, not as the outcome.

3. Judge case and grader quality.
   - Confirm reference solutions or known examples prove the task is passable.
   - Check positive, negative, and edge-case balance.
   - Verify the scorer matches the failure mode.
   - For model or hybrid judges, inspect calibration labels, rubric clarity, `Unknown` handling, and false-positive or false-negative risks.

4. Judge execution quality.
   - Verify clean start state, isolation, hidden-answer-key handling, environment snapshots, reset steps, and teardown.
   - Separate infrastructure flakes, harness bugs, scorer bugs, and genuine agent failures.
   - Check whether repeated samples, pass@k, pass^k, confidence intervals, or paired comparisons are needed before claiming improvement.

5. Decide what to do next.
   - Mark saturated capability evals as regression candidates or refresh them with harder inputs.
   - Mark invalid runs when isolation, evidence, scorer, or reference-solution proof is insufficient.
   - Recommend release-gate status only as advice; actual wiring belongs to Orbit's release and quality-gate processes.

## Output Contract

Return an `eval-run-review` artifact with:

- coverage and case-balance verdict
- reference-solution status
- isolation quality
- scorer quality and calibration gaps
- transcripts reviewed
- false-positive and false-negative risks
- statistical confidence and saturation status
- missing cases and recommended changes
- `release_gate` recommendation with rationale

## Stop Conditions

Stop and call the run invalid when evidence cannot be re-found, outcomes were not captured separately from claims, answer keys leaked to the agent under test, isolation is unknowable, or the grader cannot be checked against reference examples.
