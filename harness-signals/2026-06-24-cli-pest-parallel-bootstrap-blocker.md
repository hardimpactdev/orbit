# Signal: CLI Pest Parallel Bootstrap Blocker

Status: guarded
First seen: 2026-06-24
Last seen: 2026-07-10
Last reviewed: 2026-07-10
Source worktree: codex/quality-check-performance
Source commit: pending
Signal type: failed-check
Guardrail target: bin/orbit-cli-pest-quality; bin/quality-check.sh; apps/docs/content/testing/quality-gates.md
Guardrail change: codex/quality-check-performance
Related signals: none
Superseded by: none
Tags: quality-gate, cli, pest, paratest, timing

## Signal

While optimizing quality-gate timings, the CLI Pest lane remained the largest
serial Pest lane. A direct attempt to run it through Pest parallelization failed
before tests started:

```bash
bin/orbit-cli-pest --exclude-group=slow --parallel --compact
```

The failure was `Target class [config] does not exist` from ParaTest bootstrap.
The regular serial lane still passed.

Follow-up investigation found the low-level bootstrap cause: Pest detects the
Laravel Zero CLI app as a Laravel application because the CLI Composer package
provides `laravel/framework`, then replaces Pest's generic wrapper runner with
`Illuminate\Testing\ParallelRunner`. Laravel's parent-process parallel runner
creates and flushes multiple temporary apps; Laravel Zero registers static
`Artisan::starting` callbacks that can retain a flushed app and later resolve
`$app['config']` from it.

A CLI test-bootstrap resolver can work around that first failure for file-scoped
parallel runs by clearing stale Artisan bootstrappers before each temporary app
bootstrap. That is not sufficient for the quality gate: full-suite attempts
with `--processes=8` and `--processes=2` both exceeded the serial CLI Pest
baseline and were manually stopped after the worker children had exited or the
run had already passed 30 seconds. Serial CLI Pest has ranged around 11-15s
depending cache warmth. Full parallel remained slower than that warmed serial
baseline during these checks.

## Prior Occurrences

The original 2026-06-24 occurrence introduced the surface split. A later
quality-gate change replaced it with one serial `bin/orbit-cli-pest` process
without retiring this record. By 2026-07-10, the serial CLI lane was again the
dominant aggregate subgate, and the aggregate scheduler counted it and the
parallel gateway Pest lane as equally cheap top-level jobs. This recurrence
restored the split and made nested CPU pressure part of scheduler admission.

## Missing Guardrail

The quality-gate workflow can identify CLI Pest as a timing hotspot, but it
does not yet tell agents that `--parallel` is currently blocked by CLI test
bootstrap behavior and full-suite parallel runtime behavior rather than by
individual slow tests alone.

## Guardrail Change

`bin/orbit-cli-pest-quality` now runs the default CLI suite as five
non-overlapping Pest processes. Top-level feature, command, architecture,
support, suite-root, and service files are distributed across five stable mixed
shards. They run concurrently only after the aggregate scheduler reserves all
five CPU tokens. `bin/quality-check.sh` uses that wrapper for the `cli_pest`
subgate. The testing docs state that this is a file-surface split, not Pest
`--parallel`, so future agents should not reopen the ParaTest bootstrap path
unless they are deliberately fixing parallel support.

The aggregate scheduler now admits whole components against a CPU-token budget
and leaves unadmitted rows queued. CLI declares a peak demand of five tokens;
gateway reserves the exact number of explicit Pest workers it starts. Read-only
gateway and CLI static checks share their declared component budget, while
fix-mode mutators and Cargo commands remain sequential. Components that do not
fit stay queued while later components may backfill unused tokens. All Pest
lanes retain profile evidence; the gateway parallel lane derives its slow-test
profile from merged JUnit timing data. Core static checks may backfill a token,
but Core Pest waits until every other Pest-owning component has finished,
preserving the signal-safe boundary for its forked ticker tests.

## Verification

The original failed command returned immediately with the ParaTest bootstrap
error. A file-scoped run passed with a temporary resolver, but full-suite
parallel attempts with two and eight processes exceeded the serial baseline and
were manually stopped. The serial CLI Pest lane continued to pass and produced
the initial timing evidence.

The manual surface split passed with the same coverage as the serial lane:
1606 tests and 6427 assertions. The retained evidence lives under
`.orbit/evidence/cli-pest-manual-split-20260624T064429Z/`.

The wrapper failure path was checked with a missing Pest configuration file.
The wrapper exited non-zero, printed failed group logs, and emitted JSON with
`result: failed` and non-zero group exits.

After the first 2026-06-24 four-way split, two aggregate `composer quality-check` reruns
reported `cli_pest_services=143`. The wrapper then kept the services surface
split for reporting but ran it after the root, command, and support surfaces.
The standalone wrapper passed with 1606 tests and 6427 assertions in 6.2s. The
full aggregate quality check passed afterward with `cli_pest=9.5s`,
`gateway_pest=12.0s`, `e2e_pest=3.1s`, `sdk_pest=0.4s`, and total
`quality-check=16s`.

The 2026-07-10 tuning pass rebalanced the expanded 2170-test CLI suite across
five mixed shards and replaced measured PHP-startup fake binaries with
lightweight deterministic executables. Two exact-final aggregate runs passed
in 62s and 64s, with CLI Pest stable at 27.0s and 27.5s versus the 143.2s baseline.
The same profile pass found and fixed an Incus failure-path test cleanup that
reached live SSH; its isolated duration fell from 60s to 89ms.

## Reappearance Check

If a future agent tries to reduce `cli_pest` by adding `--parallel`, first solve
the CLI ParaTest bootstrap failure in a dedicated slice, then prove full-suite
runtime and exit-code behavior. Do not wire `--parallel` into
`bin/quality-check.sh` until the full CLI test lane passes repeatedly under
parallel execution and beats the serial baseline.

For routine quality-check speed work, keep using the surface split unless a
newer measurement shows that it is slower than the serial wrapper on the same
machine.

If aggregate scheduling changes, compare declared component demand with peak
nested fan-out. Do not count a parallel Pest invocation, the five concurrent
CLI surfaces, or Cargo's compiler jobs as one unit. A component must remain
queued until its full declared demand fits, and must not mark itself running
before admission. The service surface may remain concurrent only while those
five tokens are reserved; if exit 143 reappears, reduce the declared and actual
CLI fan-out together.

If `cli_pest` starts failing with exit 143 after this split, classify it as a
possible runner-contention false fail first. The wrapper reports that safely as
a failed gate, but repeated occurrences should reduce nested concurrency or
adjust scheduling instead of reopening the Pest `--parallel` path. Do not keep
the service surface in the concurrent CLI batch without repeated
full-gate proof.

## Curation Notes

Keep while quality-gate timing work continues. Retire if CLI Pest parallel
support is implemented and verified, or if another approach makes this lane no
longer relevant.
