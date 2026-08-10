# Quality gates

Use the smallest verification command that proves the change while developing.
Escalate only when the change crosses a wider contract boundary.

## Default gates

Run these gates while developing unless the change needs a narrower file or
filter first.

```bash
bin/orbit-gateway-pest --compact
bin/orbit-gateway-vendor-bin mago format --check
composer docs-lint
```

Run `composer quality-check` before handing off a change that should be broadly
safe. That gate fans out docs linting, Mago analyze/lint/format checks, Rector
dry-run, the default Pest suites for the gateway, CLI, docs, core, and SDK, and
Cargo checks for the headless Orbit Agent service and macOS Tauri UI.

In an interactive TTY, `bin/quality-check.sh` renders an in-place progress tree
for the monorepo areas (`apps/gateway`, `apps/cli`, `apps/docs`, `apps/e2e`,
`apps/reverb`, `apps/agent`, `apps/macos`, `packages/core`, `packages/sdk`)
while subgates run, leaves the final pass/fail tree visible, and then prints the same
per-subgate logs and summaries as before.

In the tree, every row for an area starts as queued. It changes to running only
after the CPU scheduler admits that component, then remains running while the
component runs its owned subgates. A row never returns to queued after running;
it settles only to passed or failed. Components that do not fit the remaining
CPU budget stay visibly queued instead of appearing to run while they wait.

Redirected or non-TTY runs skip the live tree so CI logs stay free of ANSI
cursor repainting. `NO_COLOR` removes color from the tree but keeps the live
terminal progress visible.
Default Pest subgates exclude `slow` tests. `composer test:slow` keeps real
boundary checks available when the behavior under test is the boundary: PTY
rendering, transport timing, and shell commands that build release packages. Use
a lane-specific command such as `bin/orbit-gateway-pest --group=slow` or
`bin/orbit-cli-pest --group=slow`, or run a focused Pest command for the changed
file.

The wrapper derives a CPU-token budget from the host: one-core hosts use one
token, hosts with up to four logical CPUs reserve one core, hosts with five to
fourteen logical CPUs reserve two cores, and larger hosts use fourteen tokens.
Each component declares its peak nested demand. Admission follows priority
order but backfills later components that fit while a larger component remains
queued, so the gateway's parallel Pest workers and the CLI
surface split cannot be mistaken for single cheap background jobs. For a
one-off diagnostic, override the budget with
`ORBIT_QUALITY_CHECK_CPU_BUDGET=<n> composer quality-check`.

The gateway Pest lane uses an explicit worker count equal to half of the CPU
budget, increasing to eight on fourteen-token hosts, and never exceeds eight.
It reserves that same number of scheduler tokens.
Parallel workers receive the same 512 MB PHP memory limit as the parent Pest
process so file workers do not fall back to the host's smaller CLI default.
CLI reserves up to five tokens for its concurrent test surfaces. Cargo
components reserve up to three tokens and set `CARGO_BUILD_JOBS` to the same
limit. Mago also receives the admitted component's thread limit.

Subgate durations start when the actual subgate command starts, after any
background-slot queue wait. Queue time is reflected in the aggregate gate
duration, not in the individual `subgate_durations` values.

`composer docs-lint`, `composer quality-check`, `composer quality-check:fix`,
and E2E lanes that run against the prepared source checkout write local timing
artifacts under `.orbit/quality-gates/`. The `:fix` lane records the same
evidence shape with `mode=fix` so triage can distinguish read-only checks from
auto-fix runs without rerunning the gate.

The E2E lane launcher gives each provider lane its own temp directory. This
keeps Pest cache files isolated when Docker and Incus lanes start together.

Every aggregate Pest subgate uses Pest's `--profile` report so slow tests remain
visible in the per-subgate logs. Each run retains those logs under
`.orbit/quality-gates/profiles/` before temporary scheduler state is removed.
The CLI Pest subgate runs through
`bin/orbit-cli-pest-quality`: top-level feature files and the command,
architecture, support, and suite-root surfaces are distributed across five
stable mixed shards. Service tests run in a sixth service shard so that their
larger process state cannot make a mixed shard unreliable. The service shard
starts after the five mixed shards finish.
This is a file-surface split, not Pest or ParaTest
`--parallel`. On
successful profiled runs the wrapper records each surface log before its final
aggregate JSON line. The root scheduler retains that output without replaying
successful subgate logs to the terminal, preserving both the slow-test reports
and the machine-readable summary without adding avoidable terminal I/O.
Gateway's parallel Pest lane also retains merged JUnit timing data because
ParaTest suppresses Pest's ordinary terminal profile table.

Mago and Rector still cover `apps/e2e` during the aggregate quality-check, but
E2E-app Pest tests are not part of the default aggregate gate. E2E Pest remains
manual-only through the explicit E2E command surface.

The Orbit Agent lanes run Cargo checks from both Rust surfaces. `apps/agent`
is the headless service lane and `apps/macos` is the macOS Tauri UI lane. Each
component runs `cargo fmt -- --check`, `cargo test`, `cargo check`, and
`cargo clippy --all-targets -- -D warnings` sequentially, avoiding Cargo target
locks and duplicate compiler pressure. In `composer quality-check:fix`, each
formatting subgate runs `cargo fmt` instead of `cargo fmt -- --check`.
Focused development can still run the same Cargo commands directly from the
owning app; the root aggregate gate exists for broad handoff evidence.

Read-only gateway and CLI static checks share their component's reserved CPU
budget. `composer quality-check:fix` keeps Rector and Mago mutators sequential,
preventing two tools from rewriting the same component at once. Core's static
checks can backfill an available token, but Core Pest waits for the gateway,
CLI, docs, and SDK Pest lanes because its progress tests fork ticker children
and must stay isolated from unrelated Pest process-group signals.

Gate names for docs and prepared-source E2E are:

| Command | Gate |
|---------|------|
| `composer docs-lint` | `docs-lint` |
| `composer test:e2e` | `e2e` |
| `composer test:e2e:docker` | `e2e-docker` |
| `composer test:e2e:docker:canary` | `e2e-docker-canary` |
| `composer test:e2e:incus` | `e2e-incus` |

Inspect existing artifacts with:

```bash
composer quality-gate:analyze
```

When reviewing only provider E2E artifacts, pass the provider gates explicitly:

```bash
composer quality-gate:analyze -- --gate=e2e-docker
composer quality-gate:analyze -- --gate=e2e-incus
composer quality-gate:analyze -- --gate=e2e-docker --gate=e2e-incus
```

The analyzer reads `.orbit/quality-gates/` only. It reports missing evidence,
recent run durations, and warning-only baseline observations when a local
baseline exists. It does not rerun `composer quality-check` or E2E lanes.

Treat the first `composer quality-check` run in a newly prepared worktree as
potentially cold-cache evidence. When a fresh-worktree run is much slower than
the baseline, use a same-command warmed rerun as a diagnostic confirmation
before calling the scheduler, product code, or a specific subgate regressed.

Before merging a worktree, inspect the existing timing evidence with:

```bash
composer quality-gate:final-check
```

The final check wraps the analyzer and highlights stale evidence, latest gate
exits that were non-zero, and local baseline observations that remain
warning-only. Without explicit `--gate` arguments, it analyzes only default
gates that are not E2E, such as `docs-lint` and `quality-check`. E2E artifacts
are reviewed only when their gates are passed explicitly, so stale Docker or
Incus artifacts do not create default final-check warnings.

Feature finalization also reads existing artifacts instead of rerunning lanes.
The merge/cleanup gate derives the required proof from the branch diff.
Docs-only diffs need a successful `docs-lint` or broader `quality-check`
artifact. Any non-docs diff needs a successful `quality-check` artifact.

For PHP files, the hook applies one more rule. If the PHP file is outside
`apps/docs/`, outside tests, and outside repository tooling under `bin/`, the
final packet also needs `Retained topology proof: passed`. That includes PHP SDK
source under `packages/sdk/` because it is a production require of CLI and
gateway. Repository tooling under `bin/` and repository-only TypeScript SDK
packaging under `packages/sdk-typescript/` still require diff-routed
`composer quality-check` but have no retained topology target. Shared core under
`packages/core/src/`, CLI, and node runtime stay on retained topology. PHP under
`apps/docs/` is docs tooling and does not need retained topology proof unless
the slice also changes topology behavior. Derive the venue early with
`bin/orbit-feature-acceptance route`. Live/production runtime receipts must use
exact `environment=live`. The retained topology row must name the topology
id/kind, inspected roles or nodes, exact command, and captured terminal/session
or artifact evidence.

For native Orbit Agent/Tauri changes, the hook applies a host topology rule
instead. Non-Markdown files under `apps/macos/` require the final packet to run
from a Darwin implementation host and record `Retained topology proof: passed -
host topology kind=host-macos; host=...; os=...; command=...; evidence=...`.
Retained Incus does not satisfy this proof because the macOS app itself is the
runtime under test. Use `composer quality-gate:final-check` to review warnings
for stale commits or slow timings.

Evidence is stale when the latest artifact exceeds the configured max-age
window or was captured for a different Git commit than the current worktree
`HEAD`. By default, final-check uses `docs-lint` and `quality-check`; Docker
and Incus E2E artifacts are checked only when their gates are passed explicitly.
The final check does not rerun
`composer quality-check`, Pest, Docker E2E, Incus E2E, or provider provision
lanes. When no timing artifacts exist, it exits successfully and reports that
timing regression analysis was skipped so the feature owner can decide whether
another gate run is needed.

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
  },
  "timing_phases": {
    "acquire/docker.seedGatewayRegistry": {
      "sample_count": 35,
      "p50": 5.944,
      "p95": 11.275
    }
  }
}
```

`duration_seconds` is required. `warning_threshold_percent` is optional. When it
is missing or invalid, the analyzer uses 25 percent above the baseline duration.
When it is present and valid, the
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
implementation state. Subgate warnings also require at least a one-second
absolute increase so harmless sub-second jitter does not trigger triage.

E2E artifacts may record provider phase summaries under
`timing_summary.summary_lines`. Baseline capture stores those summaries as
`timing_phases`, keyed by the phase label with `sample_count`, `p50`, and `p95`
values. The analyzer compares the latest phase `p95` with the stored baseline
phase `p95` using the same warning threshold. The increase must also be at
least one second. This lets Docker and Incus timing regressions route to triage
from existing artifacts without rerunning provider lanes.

Timing baseline observations remain warning-only. `composer quality-gate:analyze`
and `composer quality-gate:final-check` exit successfully even when a run
exceeds the local baseline. They do not rerun `composer quality-check` or E2E
lanes to classify the slowdown.

`bin/orbit-prepare-worktree` seeds missing baseline JSON files from the primary
checkout into the prepared worktree's `.orbit/quality-gates/baselines/`
directory. It copies files instead of symlinking them, preserves any
worktree-local baseline file that already exists, and does not run
`composer quality-check`, Pest, or E2E lanes. This keeps feature-worktree
`composer quality-gate:final-check` comparisons anchored to the latest local
main-checkout timing context while leaving each worktree's `.orbit/` state
disposable.

When the analyzer emits a timing baseline warning, it also prints a routing hint
to `.agents/skills/quality-gate-triage/SKILL.md`. Use that skill to classify the
slowdown before treating it as a product regression.

## E2E gates

The `composer test:e2e*` commands are manual prepared-topology lanes, not
default feature-completion gates. Use retained topology proof for ordinary
feature verification when behavior touches the integrated topology.

Agents, skills, hooks, release flows, and default scripts must not run or
delegate `composer test:e2e`, `composer test:e2e:docker`, or
`composer test:e2e:incus`. Those commands run only when the user explicitly
invokes them from a shell.

When run manually, these source-prepared lanes write timing artifacts through
`bin/quality-gate-run`; the wrapper preserves the lane's exit code and does not
change provider selection or argument forwarding.
When an E2E gate emits `[orbit-e2e]` timing lines, the wrapper also stores the
raw timing stream and `bin/e2e-timings.awk` summary under
`.orbit/quality-gates/e2e-timings/`. The quality-gate JSON points at those
files through `timing_summary`, and `composer quality-gate:analyze` prints the
captured `timing phase` lines so final-check review can see slow provider
phases without rerunning Docker or Incus.

E2E artifacts also record planner metadata when the lane runs through
`bin/orbit-e2e-artisan e2e:test`. The wrapper passes a local metadata file path
to the E2E command so raw plan JSON stays out of the human-visible terminal
output.

The resulting `e2e_context.plans` entries record the selected lane, provider,
lane execution mode, test execution mode, command process count, test file
count, and allowlisted E2E runner environment values. The analyzer prints those
entries as `e2e plan` and `e2e plan env` lines. It also prints the final Pest
`Tests:` summary when it was captured from stdout. Use that metadata to compare
E2E timings only against runs with compatible Docker or Incus runner shape.

When the user manually chooses an E2E/provision pass, feature E2E should precede
any affected provider artifact/provision gate. Incus provision is manual final
verification for fresh VM topology preparation, installer behavior, `node:new`,
WireGuard provisioning, or other VM/provider setup behavior. Docker provision
is only for Docker image shape, prepared role images, Docker host artifact
distribution, or Docker topology preparation changes:

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
