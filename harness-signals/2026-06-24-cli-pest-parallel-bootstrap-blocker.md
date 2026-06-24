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

## Prior Occurrences

No matching harness signal was found for CLI Pest parallelization or ParaTest
bootstrap.

## Missing Guardrail

The quality-gate workflow can identify CLI Pest as a timing hotspot, but it
does not yet tell agents that `--parallel` is currently blocked by CLI test
bootstrap behavior rather than by individual slow tests.

## Guardrail Change

None yet. This should become its own quality-gate slice if CLI Pest duration
remains a bottleneck after cheaper timing fixes.

## Verification

The failed command returned immediately with the ParaTest bootstrap error. The
serial CLI Pest lane continued to pass and produced the current timing evidence.

## Reappearance Check

If a future agent tries to reduce `cli_pest` by adding `--parallel`, first solve
the CLI ParaTest bootstrap failure in a dedicated slice. Do not wire `--parallel`
into `bin/quality-check.sh` until the full CLI test lane passes repeatedly under
parallel execution.

## Curation Notes

Keep while quality-gate timing work continues. Retire if CLI Pest parallel
support is implemented and verified, or if another approach makes this lane no
longer relevant.
