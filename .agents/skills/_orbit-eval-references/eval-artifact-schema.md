# Orbit Eval Artifact Schema

Use these shapes in Solo scratchpads or durable fixtures. Omit fields only when they are not applicable and say why.

## `eval-suite`

```yaml
id:
domain:
suite_type: capability | regression | diagnostic | release-gate-candidate
purpose:
cases:
  - case_id
expected_pass_rate:
promotion_criteria:
gate_policy:
owner_or_domain_expert:
maintenance_signal: production issue | live-node drift | session trace | docs drift | manual request
tags:
  - tag
```

## `eval-case`

```yaml
id:
domain:
suite_id:
source:
intent:
input:
user_simulation:
expected_behavior:
end_state_checks:
  - check:
    evidence:
reference_solution:
known_good_examples:
  - example:
    reason:
known_bad_examples:
  - example:
    reason:
failure_mode:
polarity: positive | negative | edge
scorer:
grader_type: code | model | human | hybrid
rubric:
calibration_labels:
  - input:
    expected_label:
    rationale:
fixtures:
  - fixture:
risk:
tags:
  - tag
```

## `eval-run`

```yaml
run_id:
suite_id:
target:
agent_harness:
eval_harness:
model:
cases:
  - case_id
environment:
isolation:
commands:
  - command:
    cwd:
    env:
trials:
  - trial_id
aggregate_scores:
pass_at_k:
pass_caret_k:
failures:
  agent:
  grader:
  harness:
  infrastructure:
cost_or_time:
evidence:
verdict:
```

## `eval-trial`

```yaml
trial_id:
run_id:
case_id:
attempt_index:
agent_harness:
eval_harness:
model:
user_simulation:
start_state:
environment_snapshot:
commands:
  - command:
    cwd:
    env:
transcript_ref:
trajectory:
outcome_ref:
end_state:
grader_results:
reset_or_teardown:
duration:
cost:
verdict:
```

## `eval-run-review`

```yaml
review_id:
run_id:
coverage:
case_balance:
reference_solution_status:
isolation_quality:
scorer_quality:
transcript_reviewed:
false_positive_risks:
false_negative_risks:
statistical_confidence:
saturation_status:
missing_cases:
recommended_changes:
release_gate: recommendation only
```

## Artifact Rules

- Use stable ids that include the domain and short behavior name.
- Keep raw transcripts and large outcomes as references, not pasted blobs.
- Record enough provenance to re-run or inspect: worktree, command, model, harness, environment, and timestamp.
- Separate trial-level verdicts from run-level aggregate scores.
- Separate grader failures from agent failures.
- Keep private session details in scratchpads unless the user approves repo fixtures.
