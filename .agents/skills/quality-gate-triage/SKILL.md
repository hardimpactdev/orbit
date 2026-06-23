---
name: quality-gate-triage
description: Use when classifying failing or slow Orbit quality gates, including Pest, composer test, composer quality-check, Docker E2E, Incus E2E, and final-check analyzer reports.
---

# Quality Gate Triage

## When To Use

Use this skill when an orchestrator, reviewer, final-check analyzer, or human
asks why an Orbit verification lane failed or slowed down. It classifies the
failure, names the next command or owner, and decides whether a durable harness
signal is needed.

This skill is for triage. Do not implement product fixes by default.

## Required Inputs

The orchestrator or final-check analyzer must provide:

- Run evidence path: local artifact path, usually under `.orbit/quality-gates/`
  or `.orbit/evidence/`.
- Command output: captured stdout/stderr, parsed failure summary, or log path.
- Changed files: the current diff or file list being verified.
- Feature context: objective, out-of-scope boundaries, and any known risky
  surface.
- Expected lane: Pest, `composer test`, `composer quality-check`, Docker E2E,
  Incus E2E, or an explicitly named provider/provision lane.

If an input is missing, report the missing field and classify only as far as
the evidence permits.

## Hard Stops

- Do not run `composer test:e2e:provision`; that aggregate is human-only.
- Do not mutate live nodes, persistent gateways, standing app nodes, provider
  hosts, or shared runner state while classifying.
- Do not implement a product fix unless the feature owner explicitly assigns
  that follow-up after classification.
- Do not rerun expensive E2E or provision gates merely to classify a final-check
  report. Inspect the evidence produced by the run first.
- Do not call a product regression when runner pool health, SSH transport,
  cache state, host load, or lane selection is degraded or unknown.

## Routing By Lane

Always read:

- `HARNESS.md`
- `HARNESS_SIGNALS.md`
- `harness-signals/README.md`
- `apps/docs/content/testing/README.md`
- `apps/docs/content/testing/quality-gates.md`

Read lane-specific guidance before classifying:

| Lane | Required docs and skills |
|------|--------------------------|
| Pest, `composer test`, in-memory failures | `.agents/skills/pest-testing/SKILL.md`; `apps/docs/content/testing/in-memory/performance.md` |
| `composer quality-check` | `apps/docs/content/testing/quality-gates.md`; then route each failing sub-gate to Pest, docs, Pint, PHPStan, Rector, or package owner guidance |
| Docker E2E | `.agents/skills/e2e-verification-lanes/SKILL.md`; `apps/docs/content/testing/e2e/environment.md`; `apps/docs/content/testing/e2e/performance.md` |
| Incus E2E | `.agents/skills/e2e-verification-lanes/SKILL.md`; `apps/docs/content/testing/e2e/environment.md`; `apps/docs/content/testing/e2e/performance.md` |
| CLI rendering, progress, prompts, or streaming output | `.agents/skills/cli-output-pty-capture/SKILL.md` before asking for human UX review |

## Classification Categories

Use one primary category and optional secondary categories:

- `product regression`: changed product behavior violates docs, tests, command
  contracts, or expected user-facing behavior.
- `test-harness regression`: the harness, fixture, runner, parser, assertion,
  or setup code is broken while product behavior remains plausible.
- `lane-selection error`: the selected gate is broader, narrower, or different
  from the affected surface.
- `stale topology/artifact`: prepared topology, role image, source checkout,
  candidate artifact, or cached build does not contain the intended code.
- `provider capacity`: runner slots, Docker capacity, Incus VM capacity, or
  shared lease availability is insufficient or degraded.
- `host/env drift`: local machine, SSH, DNS, credentials, `.env.e2e`, caches,
  filesystem permissions, or host packages differ from the expected baseline.
- `flake`: the evidence points to nondeterminism and a focused rerun is the
  next diagnostic, with no product or harness cause proven yet.
- `expected slower coverage`: the diff legitimately exercises more work, wider
  topology, more assertions, or heavier setup than the baseline.
- `stale/missing baseline`: no trustworthy baseline exists for the current
  machine, lane, runner pool, or command shape.

## Timing Evidence Model

Participating test wrappers and future quality-gate timing commands should
emit timing evidence when they run. Store machine-local artifacts under
`.orbit/quality-gates/`; store supporting transcripts, PTY summaries,
screenshots, or topology pointers under `.orbit/evidence/`. If no artifact
exists yet, classify from captured command output and report the missing
evidence as a baseline or tooling action.

The final analyzer inspects existing evidence and classifies the run. Run it
with `composer quality-gate:analyze` from the repo root. It does not rerun
expensive gates as part of analysis. A rerun can be the next command only after
classification names why the rerun is useful, such as confirming a
flake on the narrowest lane.

## Baseline Rules

- Prefer local, machine-specific, lane-specific baselines before global
  comparisons.
- Keep baseline enforcement warning-only until the lane has stable repeated
  passes with matching test count, assertion count, provider pool, and host
  health.
- Compare Docker E2E timing only when SSH multiplexing, runner reachability,
  cache state, and host load are healthy enough for the comparison.
- Compare Incus E2E timing only when the prepared topology, source checkout,
  storage pool, host slots, and cache mode match the baseline.
- If runner pool, SSH, caches, host load, or provider availability are degraded,
  classify timing as `provider capacity`, `host/env drift`, or
  `stale/missing baseline` before considering `product regression`.

## Triage Workflow

1. Confirm the expected lane and verify the command matches the affected
   surface.
2. Inspect the provided run evidence and command output before running anything.
3. Split aggregate output into sub-gates when the command is
   `composer quality-check`.
4. Compare failures against changed files and the relevant authority docs.
5. For E2E lanes, check provider capacity, host/env drift, stale artifacts, and
   lane-selection errors before product blame.
6. For timing reports, compare only against a stable compatible baseline.
7. Choose one classification category, name confidence, and recommend the next
   smallest command or owner action.
8. Search `harness-signals/` for matching prior records. Recommend a durable
   signal only when the issue is recurring, expensive, safety-sensitive, or a
   missing guardrail.

## Report Shape

```markdown
## Quality Gate Triage Report

Evidence:
- Run evidence: <path or missing>
- Command output: <path, excerpt, or missing>
- Changed files: <summary>
- Feature context: <summary>
- Expected lane: <lane>
- Actual command: <command, if known>

Classification:
- Primary: <category>
- Secondary: <categories or none>
- Confidence: <high|medium|low>
- Reasoning: <short evidence-backed explanation>

Next command:
- <single narrow command, no rerun, or human/provider action>

Owner:
- <feature owner|docs owner|test harness owner|provider/host owner|human>

Baseline action:
- <none|create local baseline|refresh stale baseline|warning-only until stable|not applicable>

Durable signal recommendation:
- <none|update harness-signals/path.md|create harness-signals/YYYY-MM-DD-slug.md|scoped follow-up>

Hard stops honored:
- Aggregate provision not run: <yes|no>
- Live nodes not mutated: <yes|no>
- Product fix deferred until assigned: <yes|no>
```
