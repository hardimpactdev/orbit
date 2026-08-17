# Todo 762 — Retained-Incus Runtime Proof

- Candidate: `380deb1c3` (formatter amend of `9db4cbc32`; behavior identical)
- Venue: retained-incus
- Environment: dev-fixture
- Topology: `operator_gateway_app-dev` id `dev-0f7ec9` (host `beast`)
- Bind: sha256 of `AnalyticsProcessEndpointResolver.php` and `AnalyticsProxyDoctorProbe.php`
  match between the worktree and the gateway VM mount `/home/orbit/orbit`.
- Runtime: deployed `orbit-gateway` container (PHP 8.5).

## Part A — backfill completeness on the DEPLOYED gateway DB (read-only)

Query `node_role WHERE role='analytics'` on the live gateway SQLite
(`/home/orbit/.config/orbit/gateway.sqlite`) and assert every settings JSON has a
valid `postgres_process_id`.

Observed: `analytics_assignments=0 missing_postgres_process_id=0` →
**BACKFILL COMPLETE** — no analytics assignment violates the required-identity
invariant on the deployed installation. (This prepared topology has no analytics
backend role, so the count is 0; the completeness check finds zero violating rows.)

## Part B — fail-closed + discovery behavior on the deployed runtime

Ran the three focused analytics Pest files inside the `orbit-gateway` container
against an isolated disposable test DB (`DB_DATABASE=/tmp/762-test.sqlite`,
RefreshDatabase-migrated), NOT `composer test:e2e*`:
`AnalyticsProcessEndpointResolverTest`, `AnalyticsRoleSettingsTest`,
`AnalyticsProxyDoctorProbeTest`.

Observed: **42 passed (140 assertions)** — proving on the deployed PHP 8.5 runtime:
- valid stored `postgres_process_id` resolves the exact process + endpoint;
- a MISSING `postgres_process_id` (single process present) now FAILS CLOSED
  ("The analytics role requires a postgres_process_id setting.") — the removed
  unique-process fallback;
- a stale id (positive int, no match) raises the existing "must reference a
  postgres process" error;
- multiple PostgreSQL processes + missing id raises the existing ambiguity error;
- ClickHouse single-process discovery (processIdSetting === null) still resolves;
- `AnalyticsRoleSettings` rejects a missing/invalid `postgres_process_id`;
- the read-only migration-completeness diagnostic flags an assignment missing
  `postgres_process_id` and passes when present.

Result: passed.
