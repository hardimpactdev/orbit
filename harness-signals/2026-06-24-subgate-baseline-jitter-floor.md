# Signal: Subgate Baseline Jitter Floor

Status: guarded
First seen: 2026-06-24
Last seen: 2026-06-24
Last reviewed: 2026-06-24

## Signal

After capturing local quality-gate baselines, `composer
quality-gate:final-check` emitted subgate timing warnings for tiny differences
such as `0.3s` to `0.5s`.

Those warnings were technically above the percentage threshold, but they were
not actionable regressions. They made final-check noisier and would route
agents into quality-gate triage for harmless sub-second jitter.

## Prior Occurrences

No prior dedicated signal record was found. The issue appeared during the first
real use of local subgate baselines across `composer quality-check`, Docker E2E,
and Incus E2E artifacts.

## Guardrail Change

`bin/quality-gate-analyze` now requires subgate warnings to exceed the
percentage threshold and increase by at least one second. Total gate warnings
keep their existing percentage threshold.

The quality-gates documentation now explains that subgate warnings include this
absolute-delta floor.

## Verification

- `bin/orbit-gateway-pest --compact tests/Feature/E2ESupport/QualityGateArtifactsTest.php --filter='surfaces slow sub-gate durations from analyzer output'`
  covers a large `gateway_pest` warning and suppresses a `core_rector`
  `0.3s` to `0.5s` jitter warning.

## Reappearance Check

If final-check again reports noisy sub-second subgate warnings, inspect
`write_subgate_duration_warnings()` in `bin/quality-gate-analyze` and confirm
that the absolute delta is still applied after the percentage threshold.

## Curation Notes

Keep while timing baselines are new. Retire only after repeated final-check use
shows the warning model is stable and useful.
