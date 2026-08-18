# Todo 767 — Blast Radius Inventory

Candidate: `eb08a40d83676739cfb3d526edb134bc7ba82559`
Base: `9ba473cb` (main incl. todo 766)

## Method

Explore trace of the TS SDK release-prep scripts-map rewrite. The prepared package
(`packages/sdk-typescript`) copied its runtime tests but the prepared `test` script was
typecheck-only, so `npm test` in the publish workflow never ran them — silently weakening
release validation.

## Result — complete

Diff `9ba473cb..eb08a40d` — 2 files, +11 / −6:

- **bin/orbit-prepare-release-package**: `rewrite_npm_package_json` no longer HARD-REPLACES
  the whole `scripts` map with a 3-entry typecheck-only literal. Now:
  ```php
  $scripts = is_array($json['scripts'] ?? null) ? $json['scripts'] : [];
  unset($scripts['generate'], $scripts['generate:openapi'], $scripts['generate:types']);
  $json['scripts'] = $scripts;
  ```
  So the prepared package PRESERVES the source scripts (build, typecheck, test:runtime,
  `test` = `npm run typecheck && npm run test:runtime`, pack:dry-run) and DROPS only the
  three generation-only commands (generate/generate:openapi/generate:types — they need the
  gateway/composer checkout or regenerate source). The copied runtime tests now execute
  when the publish workflow runs `npm test`.
- **apps/gateway/tests/Feature/Release/OrbitReleaseWorkflowTest.php**: the prepared-scripts
  assertion updated from the typecheck-only 3-key map to the preserved map (asserting
  `test` regains the runtime chain and the `generate*` keys are absent).

## Verified

- Prepared-package runtime tests execute: Grok ran the SDK prepare + `npm run typecheck` +
  `npm run test:runtime` from the prepared package — 17/17 passed (the fix's whole point).
- Gateway release test green; full quality-check exit 0.
- `packages/sdk-typescript/.github/workflows/publish.yml` UNCHANGED — `npm test` now runs
  typecheck AND runtime automatically. No other prepared-package behavior changed. TS SDK =
  packages/sdk-typescript (packages/sdk is the Laravel PHP SDK, untouched).

Result: complete — evidence=Explore trace of the scripts-map rewrite + diff review; only
bin/orbit-prepare-release-package + the release test changed; generation-only commands
dropped, runtime tests preserved.
