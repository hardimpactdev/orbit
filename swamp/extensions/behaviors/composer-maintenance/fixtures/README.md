# Composer fixture provenance

Source: `hardimpactdev/maintainer` main at
`caf034a6b04758465caeadcc6da58257f8bb53ae` (see the full source commit below),
including merged PRs #1–#5. `manager` is copied without edits from
`tests/Fixtures/dependencies/manager`. `livewire-audit.json` is the exact
`livewireFinding()` payload from `tests/helpers.php`. The tests retain the same
Composer command keys and response sequences. All parsing and file writes run
through the real extension. Only Composer is stubbed. PHP executes the upstream
fixture binary, not the extension.

Case mapping:

- `DependenciesTest.php`: no-vendor locked detection; malformed JSON, code 4,
  error document and missing locked field; stale exception.
- `PathsTest.php`: nested application; absolute, parent, dot segment, duplicate
  and escaping symlink paths; two paths resolving to one directory; missing
  manifest. Inputs replace `maintainer.json` as requested.

JavaScript, Vite+, npm, Bun and Expo cases belong to Phase 3. CLI argument
spellings and repo-owned checks are not a Swamp method surface. Extra tests
cover input-owned policy, schema resources, sparse Composer advisory objects,
cancellation, and unusable records.
