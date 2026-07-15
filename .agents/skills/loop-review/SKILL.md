---
name: loop-review
description: Use only when a human explicitly requests historical loop diagnosis or a bounded process experiment.
---

# Trigger-Only Loop Review

Ordinary successful feature loops do no retrospective work. This skill is
human-invoked and repository-read-only unless the user separately authorizes a
normal implementation slice.

## Eligible Evidence After Human Invocation

- a failed promoted protection;
- a severe preventable safety incident;
- a reviewer-confirmed recurring process failure; or
- explicit user process feedback.

One ordinary mistake is evidence, not automatically an experiment. A severe
safety incident may trigger immediately.

## Historical Diagnosis

Use `.orbit/sessions/index.json` to select the named time window or surface,
then open only relevant compact receipts and feedback streams. Historical/full
archives may be read when the question needs them. Missing values stay
`unknown`; do not invent zeroes or reconstruct a generic metric store.

Return the recurring failure, exact evidence refs, likely cause, existing
protection, and smallest preventive change. Do not require analyzer, capture,
observer, or signal-taxonomy fields that compact receipts do not contain.

For prevention trend, count an escaped same-surface defect after terminal PASS:
a later session or feedback event exposes a contradiction in the same contract
surface that the earlier accepted review should have covered. Bind each count to
both archive refs and keep uncertain matches `unknown`. Internal commit count and
autonomous pre-land rework are not prevention failures; they show recovery before
the defect escaped. Use `blast_radius_status` to explain coverage when present,
not as proof that no defect escaped.

## One Bounded Experiment

There may be one active loop experiment at a time. Create a Solo scratchpad
tagged `loop-experiment` only after a qualifying trigger:

```markdown
# Loop Experiment: <id>

- Status: proposed | active | pending | keep | revert | complete
- Surface:
- Trigger:
- Observed failure:
- Cause hypothesis:
- Smallest preventive change:
- Target metric:
- Receipt derivation:
- Baseline boundary:
- Window:
- Revert condition:
- Revert command:
- Treatment commits:
- Selected receipt refs:
- Outcome:
```

The experiment has one target metric, an exact derivation from existing compact
receipts, a fixed window, and revert by default. Implement its preventive change
as an ordinary isolated feature through `implementing-features`; never self-edit
inside the feature that exposed the issue.

Keep only when the target improves without a hard-protection failure or material
ordinary-loop slowdown. Otherwise execute the recorded revert slice. If the
same stable calculation is genuinely needed for a second experiment, request a
separate thin helper. Do not create generic evaluator tooling, dashboards,
metrics schemas, or routine cadence in anticipation.

Hard security, correctness, acceptance, and evidence-integrity protections are
never experimental.
