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

Use it as the post-implementation quality-gate reviewer after a feature owner
has produced tests and timing evidence. It reviews changed tests and artifacts;
it does not replace the implementer as the default owner of docs, tests, or
code.

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
- Current `HEAD`, artifact commit metadata when present, and whether the
  artifact is from the same worktree being reviewed.
- Local baseline file and the source artifact used to create that baseline when
  a baseline warning is being classified.
- Changed test files when the question is whether new tests slowed a lane.

If an input is missing, report the missing field and classify only as far as
the evidence permits.

## Hard Stops

- Do not run any `composer test:e2e*` command. E2E artifacts may be inspected,
  but E2E tests run only when the user explicitly invokes the Composer command
  from a shell.
- Do not mutate live nodes, persistent gateways, standing app nodes, provider
  hosts, or shared runner state while classifying.
- Do not implement a product fix unless the feature owner explicitly assigns
  that follow-up after classification.
- Do not rerun expensive E2E or provision gates merely to classify a final-check
  report. Inspect the evidence produced by the run first.
- Do not call a product regression when runner pool health, SSH transport,
  cache state, host load, or lane selection is degraded or unknown.
- Do not ask for a dedicated test-writer agent by default. Tests stay with the
  implementer unless classification shows a test-harness regression or a
  focused follow-up is explicitly assigned.
- Do not refresh a local baseline just to silence one warning. Classify the
  warning first and keep the old baseline until the new timing is accepted by
  repeated compatible evidence.

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
| `composer quality-check` | `apps/docs/content/testing/quality-gates.md`; then route each failing sub-gate to Pest, docs, Mago, Rector, or package owner guidance |
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

Participating test wrappers and quality-gate timing commands emit timing
evidence when they run. `composer quality-check`, `composer quality-check:fix`,
`composer test:e2e`, `composer test:e2e:docker`,
`composer test:e2e:docker:canary`, and `composer test:e2e:incus` store
machine-local artifacts under `.orbit/quality-gates/`. Store supporting
transcripts, PTY summaries, screenshots, or topology pointers under
`.orbit/evidence/`. If no artifact exists yet, classify from captured command
output and report the missing evidence as a baseline or tooling action.

The final analyzer inspects existing evidence and classifies the run. Run it
with `composer quality-gate:analyze` from the repo root. It does not rerun
expensive gates as part of analysis. A rerun can be the next command only for
non-E2E lanes after classification names why the rerun is useful, such as
confirming a flake on the narrowest lane.

When the expected lane is provider-specific E2E, pass the provider gates
explicitly instead of relying on the analyzer default:

```bash
composer quality-gate:analyze -- --gate=e2e-docker
composer quality-gate:analyze -- --gate=e2e-incus
composer quality-gate:analyze -- --gate=e2e-docker --gate=e2e-incus
```

Bare `composer quality-gate:analyze` is not enough for Docker/Incus-only
classification because it defaults to the broad `quality-check` gate.

Before a worktree merge, run `composer quality-gate:final-check` when timing
artifacts exist or when the feature owner needs to know that timing evidence is
missing. This final check wraps the analyzer and, without explicit `--gate`
arguments, analyzes default non-E2E local gates such as `docs-lint` and
`quality-check`. Pass Docker or Incus E2E gates explicitly when reviewing those
artifacts, otherwise stale provider artifacts must not create default final
check warnings. It highlights stale evidence, latest gate exits that were
non-zero, and local baseline observations that remain warning-only. It still
does not rerun expensive gates or warn about E2E lanes that were not run.

Worktree-local timing artifacts are disposable. After a worktree is removed or a
branch is merged, the primary checkout's `.orbit/quality-gates/` may contain
older artifacts. Do not claim those primary-checkout artifacts prove the removed
worktree's final timing evidence. Prove merged analyzer or wrapper code with
focused tests and docs checks, and report missing timing evidence separately.

## Post-Implementation Review Gate

When using this skill as a quality-gate reviewer, start after the implementer
has produced the feature tests, code, and initial evidence. The reviewer
inspects and classifies; it does not take over normal test writing.

Review in this order:

1. Confirm the latest relevant artifact for the current `HEAD`. Favor the
   latest final run, not the fastest or best historical run. Best timings are
   context only; they must not hide a slower or failing final artifact.
2. Confirm stale-evidence checks: artifact age, artifact commit, worktree path,
   gate name, exit code, test count, assertion count, provider plan, and
   baseline source artifact when available.
3. Inspect changed tests for timing hazards: sleeps, real network or provider
   calls in default in-memory Pest, archive/build work, broad fixtures,
   accidental E2E groups in default Pest, or assertions that require slow
   external state.
4. If the changed tests are slow, classify before assigning work:
   `test-harness regression` when the test does unnecessary work,
   `expected slower coverage` when the diff intentionally proves a broader
   contract, or `product regression` only when changed product behavior is the
   proven cause.
5. Ask for test changes only when the classification points at test design,
   harness behavior, or accidental lane selection. Otherwise route to the
   feature owner, provider owner, or a scoped follow-up.

## Baseline Rules

- Prefer local, machine-specific, lane-specific baselines before global
  comparisons.
- Read baseline metadata from `.orbit/quality-gates/baselines/{gate}.json`.
  Required field: `duration_seconds`. Optional field:
  `warning_threshold_percent`. Legacy baselines with only `duration_seconds`
  keep the default 25 percent warning threshold.
- Keep baseline enforcement warning-only until the lane has stable repeated
  passes with matching test count, assertion count, provider pool, and host
  health. Analyzer and final-check timing warnings do not fail the merge gate by
  themselves.
- Compare the latest final artifact first. During feature work, timings may
  regress and later recover; an earlier best run is not the final signal.
- Compare Docker E2E timing only when SSH multiplexing, runner reachability,
  cache state, and host load are healthy enough for the comparison.
- Compare Incus E2E timing only when the prepared topology, source checkout,
  storage pool, host slots, and cache mode match the baseline.
- For `composer quality-check` in a fresh worktree, check cache warmth before
  treating the first timing warning as a regression. When the first run is slow
  and cache state is still ambiguous, rerun the same `composer quality-check`
  command once as a diagnostic warm-run and classify the cold run separately
  from the latest warmed evidence. Cold first runs can also inflate Mago and
  Rector timings, so do not assume a product or scheduler regression first.
- Change only one variable when testing a timing hypothesis. A comparison that
  changes both scheduler fan-out and cache warmth is not evidence for either
  cause.
- If runner pool, SSH, caches, host load, or provider availability are degraded,
  classify timing as `provider capacity`, `host/env drift`, or
  `stale/missing baseline` before considering `product regression`.

## Timing Regression Routing

When `composer quality-gate:analyze` or `composer quality-gate:final-check`
reports a timing baseline warning, activate this skill before blaming product
code. The analyzer prints a routing hint to
`.agents/skills/quality-gate-triage/SKILL.md` on those warnings.

1. Read the latest artifact under `.orbit/quality-gates/` and the matching
   baseline file under `.orbit/quality-gates/baselines/`.
2. Confirm the baseline is compatible with the current lane, runner pool, cache
   state, and host health.
3. For `composer quality-check`, distinguish cold-cache first-run evidence from
   warmed evidence before optimizing the scheduler or individual tools. Change
   only one variable between timing comparisons.
4. For provider E2E phase warnings, compare the latest artifact with the
   baseline source artifact: lane/provider, runner shape, command process count,
   test file count, test/assertion summary when available, provider plan
   environment, and artifact commit. For Docker, verify SSH multiplexing and
   repeated `ssh true` latency before trusting phase comparisons. For Incus,
   verify source checkout, storage pool, host slots, prepared topology, and
   cache mode.
5. Classify the slowdown using the categories below.
6. Recommend the next narrow command or owner action. Do not rerun expensive
   gates unless classification proves the rerun is diagnostic.

## Provider Phase Warning Triage

E2E phase warnings are routing signals, not automatic regressions.

- If the full provider gate is within baseline and only one phase regresses,
  classify from compatibility evidence first. A single Docker or Incus phase
  p95 warning with compatible runner shape is usually `provider capacity` or
  `flake` until repeated evidence proves otherwise.
- Do not refresh the phase baseline from one warning. Record the classification
  under `.orbit/evidence/` or the feature scratchpad. Refresh only after the new
  timing is accepted by repeated compatible runs or an intentional provider
  capacity change.
- If the same phase warning recurs across independent worktrees, promote it to a
  durable `harness-signals/` record or tighten the analyzer/docs. If it affects
  full-lane duration, assign a provider-specific optimization slice.
- If the warning comes from an artifact captured before the current `HEAD`,
  classify as stale evidence before drawing timing conclusions.

## Parallel Lane Triage

When the goal is to improve or classify more than one quality lane, split the
work before analysis starts. Treat these as separate lanes by default:

- in-memory/Pest and `composer quality-check` sub-gates
- Docker E2E
- Incus E2E

Dispatch independent lane triage or optimization through separate Solo workers
unless a concrete dependency, shared state path, provider-capacity limit, or
merge-order reason is recorded. Docker and Incus use different provider
systems, so they should normally run in parallel. Do not finish all Pest,
package, or app-level timing work before starting Docker or Incus investigation
when those lanes are part of the same goal and have no dependency on the
in-memory result. Do not overlap aggregate `composer quality-check` with active
provider E2E unless shared E2E support state is proven isolated; schedule that
aggregate check after provider lanes are idle.

Each lane report must say whether it produced a concrete optimization diff, a
no-op classification, or a deferred follow-up. If a lane is not dispatched, the
orchestrator must record the reason and owner in `.orbit/loop.md`, the feature
scratchpad, or the worker plan instead of leaving it implicit.

Concrete optimization diffs to the quality-gate analyzer, wrappers, or E2E
timing model need a Claude review or an explicitly recorded fallback reviewer
before commit. Use a bounded, blockers-first prompt with the exact changed-file
diff. Give reviewers real read time while they are actively reading or
generating; replace or downgrade the review only after idle/unproductive wait
is clear and recorded.

## Triage Workflow

1. Confirm the expected lane and verify the command matches the affected
   surface.
2. Inspect the provided run evidence and command output before running anything.
3. Confirm the latest relevant artifact matches the current `HEAD` and worktree.
4. Split aggregate output into sub-gates when the command is
   `composer quality-check`.
5. Compare failures against changed files and the relevant authority docs.
6. For E2E lanes, check provider capacity, host/env drift, stale artifacts, and
   lane-selection errors before product blame.
7. For timing reports, compare only against a stable compatible baseline.
8. Review changed tests for slow-test or lane-selection regressions before
   asking for implementation changes.
9. Choose one classification category, name confidence, and recommend the next
   smallest command or owner action.
10. Search `harness-signals/` for matching prior records. Recommend a durable
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
