# Horizon tag fixtures

Source: `nckrtl/horizon-new-dawn` at `06e309c09afadf03f2fae931b19dbd5206a332af`.
Snapshots are verbatim files from `laravel/horizon`, retained from the detector
in merged PR #5. The fixture directory names pin the tag pairs:

- `silenced`: v5.11.0 → v5.12.0, six changes across all four surfaces.
- `watch`: v5.42.0 → v5.43.0, the watch config key.
- `screens`: v5.49.0 → v5.50.0, two recent-job screens.
- `release`: v5.50.0 → v5.50.1, no surface change.

`.snapshot` prevents fixture PHP and Vue files from becoming extension code.
Tests reconstruct local Git repositories, so no network is needed.
