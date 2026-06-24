# Signal: Stale Quality Gate Artifact Commit

Status: guarded
First seen: 2026-06-24
Last seen: 2026-06-24
Last reviewed: 2026-06-24

## Signal

After rebasing `quality-e2e-lane-timing-baseline`, `composer
quality-gate:final-check` reported no warnings even though the latest
`quality-check`, Docker E2E, and Incus E2E artifacts were captured for older
commits than the current worktree `HEAD`.

This let stale timing evidence look current. The analyzer already handled
age-based stale artifacts, but it did not compare artifact Git metadata with
the checkout being reviewed.

## Prior Occurrences

No prior dedicated signal record was found. Existing quality-gate docs said
final-check highlights stale evidence, so the implementation was narrower than
the documented contract.

## Guardrail Change

`bin/quality-gate-analyze` now compares full 40-character artifact commits with
the current worktree `HEAD`. When they differ, it emits a `missing evidence:`
line, which `bin/quality-gate-final-check` already promotes into final-check
warnings.

The quality-gates documentation now states that evidence is stale when the
latest artifact is too old or was captured for a different Git commit than the
current worktree `HEAD`.

## Verification

- `bin/orbit-gateway-pest --compact tests/Feature/E2ESupport/QualityGateArtifactsTest.php --filter='final-check warns when latest timing evidence was captured for a different commit'`: failed before the analyzer change, then passed.
- `bin/orbit-gateway-pest --compact tests/Feature/E2ESupport/QualityGateArtifactsTest.php`: passed after the analyzer and docs update.

## Reappearance Check

If final-check again accepts stale artifacts after a rebase or commit, verify
whether the artifact includes a full `git.commit` value. If it does and no
warning appears, tighten `quality-gate-analyze`; if the metadata is missing,
consider making missing commit metadata a separate warning.

## Curation Notes

Keep this record while quality-gate timing evidence is still being stabilized.
