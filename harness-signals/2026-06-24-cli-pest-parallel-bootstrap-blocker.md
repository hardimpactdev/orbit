# Signal: CLI Pest Parallel Bootstrap Blocker

Status: open
First seen: 2026-06-24
Last seen: 2026-06-24
Last reviewed: 2026-06-24
Source worktree: quality-e2e-lane-timing-baseline
Source commit: 9be2027a
Signal type: failed-check
Guardrail target: pending
Guardrail change: none
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

No matching harness signal was found for CLI Pest parallelization or ParaTest
bootstrap.

## Missing Guardrail

The quality-gate workflow can identify CLI Pest as a timing hotspot, but it
does not yet tell agents that `--parallel` is currently blocked by CLI test
bootstrap behavior and full-suite parallel runtime behavior rather than by
individual slow tests alone.

## Guardrail Change

None yet. This should become its own quality-gate slice only if CLI Pest
duration remains a bottleneck after cheaper timing fixes. A successful slice
must prove the full CLI Pest lane repeatedly beats the serial lane before
changing `bin/quality-check.sh`.

## Verification

The original failed command returned immediately with the ParaTest bootstrap
error. A file-scoped run passed with a temporary resolver, but full-suite
parallel attempts with two and eight processes exceeded the serial baseline and
were manually stopped. The serial CLI Pest lane continued to pass and produced
the current timing evidence.

## Reappearance Check

If a future agent tries to reduce `cli_pest` by adding `--parallel`, first solve
the CLI ParaTest bootstrap failure in a dedicated slice, then prove full-suite
runtime and exit-code behavior. Do not wire `--parallel` into
`bin/quality-check.sh` until the full CLI test lane passes repeatedly under
parallel execution and beats the serial baseline.

## Curation Notes

Keep while quality-gate timing work continues. Retire if CLI Pest parallel
support is implemented and verified, or if another approach makes this lane no
longer relevant.
