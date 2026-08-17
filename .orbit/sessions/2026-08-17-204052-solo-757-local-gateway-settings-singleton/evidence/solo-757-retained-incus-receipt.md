# Solo 757 retained-Incus runtime receipt

candidate=`99522c332ba63cf350f1753ba7db58d3d4510429`
git_tree_clean=true
venue=retained-incus
environment=dev-fixture
topology_id=`dev-bf43eb` (operator_gateway_app-dev; acquired from the 757 worktree, source-mounted + re-migrated to the candidate)
source_binding=container `/srv/orbit/apps/gateway/database/migrations/2026_08_17_120000_enforce_singleton_local_gateway_settings.php` sha256 `d3b937ce305bf608f289be44cd006f9f622ea6fc3d1a206d80ccf0af50844df9` identical to the worktree candidate (final candidate 99522c332: nullable→consolidate→NOT NULL singleton_key with a type-safe nullability check)
driver_identity=Solo orchestrator agent process 2470 driving `ssh beast incus exec`; DB reads/writes via PDO on the gateway VM, migrations via artisan on disposable DB copies, accessor via `php artisan tinker`

## Live gateway (after candidate migration ran during sync)

`local_gateway_settings` has exactly ONE canonical row: id=1, singleton_key='default',
gateway_url='https://10.6.0.2', gateway_wg_ip='10.6.0.2'; unique index
`local_gateway_settings_singleton_key_unique` present.

## Row-count matrix (all four required cases)

| Case | Method | Observed | Result |
|---|---|---|---|
| ZERO rows | disposable copy: delete all rows, then `LocalGatewaySettings::current()` via tinker | count 0 → current() creates exactly one canonical row (singleton_key='default') | PASS |
| ONE row / idempotency | second `LocalGatewaySettings::current()` call | count stays 1, returns the SAME row (same id) | PASS |
| DUPLICATE rows | disposable copy: drop unique index, insert 3 rows (id1 @2026, OLD @2020, NEWEST @2099), remove migration record, re-run the singleton migration forward | 3 rows consolidated to ONE canonical row (id=1) keeping the NEWEST row's field values (gateway_url='https://NEWEST', gateway_wg_ip='9.9.9.9'); unique index re-added | PASS |
| Second/concurrent init | insert a second row with singleton_key='default' after migration | rejected by the unique constraint; row count stays 1 | PASS |
| NOT NULL singleton_key | after migration, singleton_key column PRAGMA notnull=1; insert with NULL singleton_key | rejected by the NOT NULL constraint; row count stays 1 | PASS |

## Determinism

The consolidation winner is the newest row by created_at, tiebreak highest id
(`consolidateToCanonicalRow`); the canonical retained row is the lowest id, updated with the winner's
gateway_url / gateway_wg_ip / ca_sha256 / ca_pem_path / trusted_at values, then all other rows are
deleted and the unique `singleton_key` index is added. `current()` is race-safe
(`firstOrCreate(['singleton_key' => SINGLETON_KEY])` with a `UniqueConstraintViolationException`
refetch fallback). The comprehensive per-case matrix is also unit-tested in
`EnforceSingletonLocalGatewaySettingsTest` and `LocalGatewaySettingsTest` (green in quality-check).

result=passed
