---
name: quality-gate-triage
description: Use when classifying a failing, inconsistent, or slow Orbit quality-gate artifact and choosing the smallest next action.
---

# Quality Gate Triage

Triage evidence; do not turn diagnosis into a second delivery workflow.

## Inputs

- exact command or gate;
- artifact/output path;
- checkout and candidate commit;
- relevant changed files;
- comparable baseline when the question is timing.

If provenance is missing, say what cannot be concluded.

## Classification

Choose one:

- **product regression** — deterministic product behavior or contract failed;
- **test regression** — test or fixture is wrong, flaky, or no longer expresses
  authority;
- **tooling regression** — launcher, artifact, analyzer, or environment wrapper
  failed;
- **infrastructure** — host, transport, pool, cache, or dependency state failed;
- **performance warning** — comparable successful evidence is slower;
- **unknown** — evidence is insufficient.

Name the evidence, why it supports the class, and the smallest next action.
Rerun only the narrow failing lane when a rerun is necessary. A full
`composer quality-check` belongs to normal PROVE after the fix, not initial
classification.

## Boundaries

- Do not run any `composer test:e2e*` command. Never delegate, background,
  schedule, hook, script, or trigger one. Read existing user-produced E2E
  artifacts only.
- Do not mutate live nodes or shared runner state while classifying.
- Do not refresh a baseline merely to silence a warning.
- Do not dispatch standing reviewer, analyzer, observer, or signal lanes.
- Do not implement the fix unless the user or feature owner asks for it.

## Output

```text
Gate:
Candidate:
Evidence:
Classification:
Confidence:
Smallest next action:
Remaining unknowns:
```
