# Orbit Eval Scorer Selection

Choose the least subjective scorer that can measure the failure mode.

## Scorer Types

| Type | Use When | Requirements |
| --- | --- | --- |
| `code` | Files, DB rows, JSON, exact output contracts, docs links, topology facts, or command side effects can be checked deterministically. | Clear expected state and runnable check. |
| `model` | The behavior is semantic, open-ended, conversational, or interaction-quality based. | Rubric, calibration labels, known-good and known-bad examples, `Unknown` option. |
| `human` | The decision is high stakes, ambiguous, novel, or policy-sensitive. | Named reviewer role, rubric, and examples. |
| `hybrid` | Deterministic checks cover part of the behavior but semantic judgment remains. | Separate deterministic and judgment dimensions. |

## Selection Steps

1. Write the failure mode in one sentence.
2. List observable end states.
3. Use a code scorer for every deterministic end state.
4. Add a model or human scorer only for dimensions the code scorer cannot observe.
5. Define calibration labels before trusting a model judge.
6. Decide what evidence produces `pass`, `fail`, and `Unknown`.

## Model Judge Rules

- Treat the judge as a classifier, not an oracle.
- Give the judge only the evidence it should use.
- Keep answer keys and hidden expected outputs away from the agent under test.
- Use narrow binary or categorical labels before Likert scores.
- Grade separate dimensions separately: correctness, completeness, safety, interaction quality, and evidence quality should not be merged without reason.
- Require the judge to return `Unknown` when evidence is missing or contradictory.
- Spot-check judge decisions against human or deterministic labels.

## Calibration Set

Use this minimal shape:

```yaml
calibration_labels:
  - id:
    evidence:
    expected_label: pass | fail | Unknown
    rationale:
  - id:
    evidence:
    expected_label:
    rationale:
```

Keep calibration labels separate from final held-out eval cases when measuring scorer quality.

## Rubric Shape

```yaml
rubric:
  pass:
    - observable requirement
  fail:
    - observable failure
  unknown:
    - missing or insufficient evidence condition
```

## Common Mistakes

- Using a model judge for a file, JSON, DB, or command-state check.
- Scoring final assistant prose while ignoring the actual environment outcome.
- Combining many dimensions into one subjective score.
- Trusting a judge without known-good and known-bad examples.
- Letting the agent under test see the grader internals or answer key.
