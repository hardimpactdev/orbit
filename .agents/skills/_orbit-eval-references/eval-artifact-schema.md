# Orbit Eval Artifact Schema

Use these shapes in files under `~/shared-knowledge/projects/orbit/evals/` or durable fixtures. Omit fields only when they are not applicable and say why.

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
comparison_design:
  baseline_condition:
  treatment_condition:
  controlled_delta:
  pairing:
  primary_metric:
  secondary_metrics:
    - metric
  minimum_trials:
tags:
  - tag
```

## `eval-case`

```yaml
id:
domain:
suite_id:
source: # doc path, issue ref, live-node signal, or archive-slug (a .orbit/sessions/<timestamp>-<slug> archive name)
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
comparison:
  condition: baseline | treatment | control | variant
  visible_artifacts:
    - artifact
  hidden_artifacts:
    - artifact
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
paired_comparisons:
  - pair_id:
    baseline_trial_id:
    treatment_trial_id:
    delta:
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
condition: baseline | treatment | control | variant
pair_id:
agent_harness:
eval_harness:
model:
user_simulation:
start_state:
environment_snapshot:
visible_artifacts:
  - artifact
hidden_artifacts:
  - artifact
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
tracked_metrics:
  elapsed_time:
  tool_call_count:
  file_or_source_count:
  evidence_count:
  output_validity:
  uncertainty_count:
stop_reason:
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
comparative_validity:
  prompt_delta:
  pairing_quality:
  contamination:
  sample_size:
  conclusion_scope:
```

## Artifact Rules

- Use stable ids that include the domain and short behavior name.
- Keep raw transcripts and large outcomes as references, not pasted blobs.
- Record enough provenance to re-run or inspect: worktree, command, model, harness, environment, and timestamp.
- Separate trial-level verdicts from run-level aggregate scores.
- Separate grader failures from agent failures.
- For comparative fresh-agent evals, record the condition, pair id, prompt delta, visible artifacts, tracked metrics, and contamination status per trial.
- `source` fields accept an archive-slug value: the `.orbit/sessions/<timestamp>-<slug>` archive directory name, optionally suffixed with the provider/session path inside `agent-sessions/`.
- Keep private session details in files under `~/shared-knowledge/projects/orbit/evals/` unless the user approves repo fixtures.
