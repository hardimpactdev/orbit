---
name: e2e-verification-lanes
description: Use when reading or triaging existing Orbit E2E artifacts, explaining manual composer test:e2e command references, or documenting E2E lane ownership. Never execute, delegate, schedule, or trigger E2E tests.
---

# E2E Verification Lanes

## Non-Execution Rule

Agents must not run, delegate, background, schedule, hook, or script any
`composer test:e2e*` command. This includes provider-specific commands such as
`composer test:e2e:docker`, `composer test:e2e:incus`,
`composer test:e2e:provision:docker`, and `composer test:e2e:provision:incus`.

E2E tests run only when the user independently invokes the Composer command from
a shell. Do not ask the user to run E2E for ordinary feature completion; use
retained proof instead. Only explain a manual E2E command after the user
explicitly asks about that lane. If they choose to run it, inspect the terminal
output or resulting `.orbit/quality-gates/` artifact.

For normal feature completion, use retained topology proof instead: record the
topology id/kind, inspected roles or nodes, exact command, terminal/session or
artifact evidence, and result in `.orbit/loop.md` or `.orbit/evidence/`.

## Allowed Agent Work

Agents may:

- Inspect existing `.orbit/quality-gates/` artifacts.
- Run read-only analyzers such as `composer quality-gate:analyze` or
  `composer quality-gate:final-check`; these commands must not rerun E2E.
- Explain which manual E2E command a user may choose to run.
- Update docs, tests, skills, or scripts so they do not trigger E2E tests.

Agents may not:

- Run any `composer test:e2e*` command.
- Spawn a worker to run any `composer test:e2e*` command.
- Add a hook, quality gate, release flow, or skill instruction that runs E2E.
- Treat missing E2E output as a feature-completion blocker; use retained
  topology proof for the feature gate.

## Manual Command Reference After An Explicit User Request

These commands remain available for user-run checks only:

```bash
composer test:e2e
composer test:e2e:docker
composer test:e2e:incus
composer test:e2e:docker:canary
composer test:e2e:topology-contract
composer test:e2e:provision:docker
composer test:e2e:provision:incus
```

`composer test:e2e:provision` is the human-only aggregate for both provider
provision commands. Agents must not run it.

## Existing Artifact Triage

When classifying existing E2E artifacts, inspect the recorded command, gate,
exit code, Git commit, timing summaries, provider metadata, and captured Pest
summary. Compare only like-for-like provider shape and runner capacity. If no
artifact exists, report that no manual E2E evidence is present; do not create it
by running E2E.

Use `quality-gate-triage` for slow or failing artifact classification. Keep
substrate failures separate from product regressions, especially provider pool
capacity, stale prepared artifacts, host drift, missing credentials, and stale
retained topology state.
