# Composer fixture provenance

Source: `hardimpactdev/maintainer` main at
`caf034a6b04758465caeadcc6da58257f8bb53ae` (see the full source commit below),
including merged PRs #1–#5. `manager` is copied without edits from
`tests/Fixtures/dependencies/manager`. `livewire-audit.json` is the exact
`livewireFinding()` payload from `tests/helpers.php`. The tests retain the same
Composer command keys and response sequences. All parsing and file writes run
through the real extension. Git runs in temporary repositories; only Composer is
stubbed. PHP executes the upstream fixture binary, not the extension.

Case mapping:

- `DependenciesTest.php`: sequential routine failures; no-vendor locked
  detection; malformed JSON, code 4, error document and missing locked field;
  targeted development dependency; two-advisory security verification; unknown
  advisory before writes; remaining advisory; invalid, missing and floating
  targets; duplicate exceptions; failed require stops update; absent policy;
  already-fixed rerun; stale exception.
- `PathsTest.php`: nested application; explicit package path; absolute, parent,
  dot segment, duplicate and escaping symlink paths; two paths resolving to one
  directory; missing manifest. Inputs replace `maintainer.json` as requested.
- `SummaryTest.php` (PR #5): direct updates and transitive omission; major;
  mixed routine and major; zero-major minor; cleared advisory links; path
  labels; no changes; origin/HEAD; invalid refs; still-open advisory;
  added/removed dependencies; removed declaration still installed; empty-lock
  removals in one and multiple paths; invalid empty audit on a nonempty lock.
  Expected text keeps the approved title, prose, sections, and four-column
  table.

JavaScript, Vite+, npm, Bun and Expo cases belong to Phase 3. CLI argument
spellings and repo-owned checks are not a Swamp method surface. Major package
expectations retain maintainer's caret range; security targets remain exact.
Extra tests cover input-owned policy, schema resources, sparse Composer advisory
objects, cancellation, and unusable records.
