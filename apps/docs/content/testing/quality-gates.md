# Quality gates

Use the smallest verification command that proves the change while developing.
Escalate only when the change crosses a wider contract boundary.

## Default gates

Run these gates while developing unless the change needs a narrower file or
filter first.

```bash
bin/orbit-gateway-pest --compact
bin/orbit-gateway-vendor-bin pint --dirty --format agent
composer docs-lint
```

Run `composer quality-check` before handing off a change that should be broadly
safe. That gate fans out docs linting, PHPStan, Rector dry-run, Pint, and the
default Pest suite across each app and package. Default Pest subgates exclude
`slow` tests. `composer test:slow` keeps real boundary checks available when
the behavior under test is the boundary: PTY rendering, transport timing, and
shell commands that build release packages. Use `bin/orbit-gateway-pest
--group=slow` or `bin/orbit-cli-pest --group=slow` for a lane-specific slow
pass, or run a focused Pest command for the changed file.

The wrapper caps background fan-out by default so local runner contention does
not inflate the long Pest lane timings unnecessarily. The cap is derived from
the detected logical CPU count and only changes scheduling; every subgate still
runs and still contributes to the final exit code. For a one-off diagnostic,
override it with `ORBIT_QUALITY_CHECK_MAX_BACKGROUND_JOBS=<n> composer
quality-check`.

Subgate durations start when the actual subgate command starts, after any
background-slot queue wait. Queue time is reflected in the aggregate gate
duration, not in the individual `subgate_durations` values.

`composer quality-check`, `composer quality-check:fix`, and E2E lanes that run
against the prepared source checkout write local timing artifacts under
`.orbit/quality-gates/`. The `:fix` lane records the same evidence shape with
`mode=fix` so triage can distinguish read-only checks from auto-fix runs without
rerunning the gate.

The CLI Pest subgate runs the default CLI suite through
`bin/orbit-cli-pest-quality`, which splits the suite into non-overlapping Pest
processes by test surface. This is not Pest's `--parallel` mode. The root,
command, and support surfaces run together. The services surface runs after
those finish because it is more sensitive to nested runner contention. The split
keeps the Laravel Zero CLI bootstrap isolated while reducing the quality-check
critical path.

The `apps/e2e` in-memory Pest lane and SDK Pest lane run as background subgates
after the gateway lane has started and the CLI Pest split has finished. This
keeps the fragile Laravel Zero bootstrap split out of the heaviest overlap while
still letting E2E and SDK Pest overlap the gateway tail. The overlapped E2E Pest
lane must keep work that builds checkout archives for the whole tree in
topology/provision feature groups, outside the default in-memory pass.

Core Pest still runs after all background Pest lanes because the core progress
tests fork ticker children and must stay isolated from unrelated Pest process
signals.

Gate names for prepared-source E2E are:

| Command | Gate |
|---------|------|
| `composer test:e2e` | `e2e` |
| `composer test:e2e:docker` | `e2e-docker` |
| `composer test:e2e:docker:canary` | `e2e-docker-canary` |
| `composer test:e2e:incus` | `e2e-incus` |

Inspect existing artifacts with:

```bash
composer quality-gate:analyze
```

The analyzer reads `.orbit/quality-gates/` only. It reports missing evidence,
recent run durations, and warning-only baseline observations when a local
baseline exists. It does not rerun `composer quality-check` or E2E lanes.

Before merging a worktree, inspect the existing timing evidence with:

```bash
composer quality-gate:final-check
```

The final check wraps the analyzer and highlights stale evidence, latest gate
exits that were non-zero, and local baseline observations that remain
warning-only. Without explicit `--gate` arguments, it analyzes the gates that
already have artifacts in this worktree. It does not warn about missing E2E
lanes that were not run, and it does not rerun `composer quality-check`, Pest,
Docker E2E, Incus E2E, or provider provision lanes. When no timing artifacts
exist, it exits successfully and reports that timing regression analysis was
skipped so the feature owner can decide whether another gate run is needed.

## Failure and timing triage

When a quality gate fails or slows down, use the root
`.agents/skills/quality-gate-triage/SKILL.md` runbook before blaming product
code. Participating gate commands and future timing wrappers should leave timing
and command evidence under `.orbit/quality-gates/`, with supporting transcripts
under `.orbit/evidence/` when applicable. If no artifact exists yet, triage uses
the captured command output and reports the missing evidence as a baseline or
tooling action. A final analyzer inspects existing artifacts and should not
rerun expensive gates merely to classify a failure or slowdown.

Treat timing baselines as local and machine-specific first. Until a stable
baseline exists for the current lane and runner pool, classify timing deltas as
warning-only and record the missing-baseline action instead of calling a product
regression.

## Local timing baselines

Store machine-local baseline metadata under
`.orbit/quality-gates/baselines/{gate}.json`. Each file records the expected
duration for one gate on the current runner pool.

Capture or refresh a machine-local baseline from the latest quality-gate
artifact:

```bash
composer quality-gate:baseline-capture
```

Baseline capture reads existing artifacts only. It does not rerun
`composer quality-check`, Pest, E2E lanes, or `composer quality-gate:analyze`.
By default it promotes the latest `quality-check` artifact into
`.orbit/quality-gates/baselines/quality-check.json`. Pass `--force` when the
latest artifact exited non-zero but you still want to refresh the local
baseline. Use `--warning-threshold-percent=<n>` to override the stored
warning threshold.

Baseline file shape:

```json
{
  "schema_version": 1,
  "gate": "quality-check",
  "duration_seconds": 330,
  "warning_threshold_percent": 25,
  "source_artifact": "quality-check-2026-06-23T100530Z-latest456.json",
  "updated_at": "2026-06-23T10:05:30Z",
  "subgate_durations": {
    "gateway_pest": 245.5,
    "docs_lint": 12.0
  }
}
```

`duration_seconds` is required. `warning_threshold_percent` is optional. When it
is missing or invalid, the analyzer keeps the backward-compatible default of
25 percent above the baseline duration. When it is present and valid, the
analyzer uses that percentage to decide whether a recent run is a timing
regression. `source_artifact` records which artifact was promoted into the
baseline.

Quality-check artifacts may also record per-subgate profiling under
`subgate_durations` alongside the existing `subgates` exit-code map. Baseline
capture stores the `subgate_durations` from the latest quality-gate artifact
that was promoted into the local baseline. The analyzer prints the latest
artifact's subgate durations and compares them with the baseline subgate
durations using the same warning threshold. This values the final run in a
feature worktree instead of an earlier faster run from a different
implementation state.

Timing baseline observations remain warning-only. `composer quality-gate:analyze`
and `composer quality-gate:final-check` exit successfully even when a run
exceeds the local baseline. They do not rerun `composer quality-check` or E2E
lanes to classify the slowdown.

When the analyzer emits a timing baseline warning, it also prints a routing hint
to `.agents/skills/quality-gate-triage/SKILL.md`. Use that skill to classify the
slowdown before treating it as a product regression.

## E2E gates

Run `composer test:e2e` when behavior touches the integrated prepared topology.
Use `composer test:e2e:docker` for Docker-eligible feature tests and
`composer test:e2e:incus` for VM-feature behavior. These source-prepared lanes
write timing artifacts through `bin/quality-gate-run`; the wrapper preserves the
lane's exit code and does not change provider selection or argument forwarding.

Run feature E2E before any affected provider artifact/provision gate. The
prepared-topology lanes exercise the current source checkout and are the normal
behavior signal. Incus provision is final verification for fresh VM topology
preparation, installer behavior, `node:new`, WireGuard provisioning, or other
VM/provider setup behavior. Docker provision is only for Docker image shape,
prepared role images, Docker host artifact distribution, or Docker topology
preparation changes:

```bash
composer test:e2e:provision:docker
composer test:e2e:provision:incus
```

These commands may be run by separate agents in parallel only when both provider
gates are independently required and the matching provider feature lane is green.
The aggregate `composer test:e2e:provision` runs both provider provision
commands and is reserved for humans, not agents.

There is no standing live-node verification lane. Persistent gateway, operator,
and app nodes are diagnostic targets only.
