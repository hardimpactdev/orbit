# QualityGateArtifacts manual-only contract reshape (Slice 2, E2E guard)

The prior contract asserted every default gate script — including
`bin/orbit-codex-pre-tool-use-hook` — must not contain the literal
`composer test:e2e`. Its intent: default gate scripts must never mention or
trigger the human-only E2E lanes.

Slice 2 makes the hook the deterministic denier of those lanes, so the hook now
legitimately names `composer test:e2e*` inside its deny logic and teaching
message. Keeping the literal ban would forbid the enforcement itself.

Replacement of equal or greater strength (same test,
`keeps e2e test commands manual only across default gates and skills`):

- The four other default gate scripts keep the unchanged literal ban.
- The hook must now positively contain the dedicated guard
  (`Orbit E2E guard blocked`, `human-only`) — absence of the guard fails the
  contract, which the old assertion could never detect.
- The hook must contain none of the executable E2E vectors:
  `orbit-e2e-artisan`, `e2e:test`, `bin/quality-gate-run`, `.env.e2e`. The old
  literal ban only blocked one spelling of a trigger; this bans the actual
  execution entry points.
- Both agent wirings (`.claude/settings.json`, `.codex/hooks.json`) must keep
  referencing the hook, so the deny cannot be silently unwired.
- Behavioral proof (exit 2, dedicated message, no execution) lives in
  `bin/orbit-codex-pre-tool-use-hook-test`, which feeds command strings into
  the hook and never executes any Composer script.
