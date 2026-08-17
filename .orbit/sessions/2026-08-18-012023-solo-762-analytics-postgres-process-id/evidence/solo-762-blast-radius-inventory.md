# Todo 762 — Blast Radius Inventory

Candidate: `380deb1c3`
Base: `a9afe594f` (main incl. todo 761)

## Method

Repository-wide `rg` over the analytics endpoint-resolution surface: callers of
`AnalyticsProcessEndpointResolver::resolve`, `postgres_process_id` consumers,
and the ClickHouse discovery path.

## Result — complete

The change is narrowly scoped:

- **AnalyticsProcessEndpointResolver::resolve** — adds one guard in the
  missing/invalid-stored-id `else` branch: `if ($processIdSetting !== null)
  throw "The analytics role requires a {$processIdSetting} setting."` **before**
  the `$processes->first()` fallback. The unique-process fallback is removed
  ONLY when a process-id setting is expected. Stale-id error (positive int, no
  match) and multiple-process ambiguity error are unchanged.
- **Sole caller = PlausibleRuntimeConfig**: postgres resolve passes
  `processIdSetting='postgres_process_id'` (now fail-closed on a missing id);
  clickhouse resolve passes NO processIdSetting (`processIdSetting === null`),
  so ClickHouse single-process discovery is unchanged.
- **AnalyticsProxyDoctorProbe** — adds a read-only migration-completeness
  diagnostic (`POSTGRES_PROCESS_ID_MISSING_KEY`) that flags analytics
  `node_role` assignments whose settings lack `postgres_process_id`. Reporting
  only — no mutating calls (verified by diff grep: no delete/update/save/create/
  restore).
- **AnalyticsRoleSettings** — already required `postgres_process_id`
  (constructor + `fromArray`); covered by a new focused test.
- The landed backfill migration `2026_07_19_030000` is unchanged.

Tests: AnalyticsProcessEndpointResolverTest (valid id resolves / missing id
fails closed / stale id / multiple-process ambiguity / ClickHouse discovery),
AnalyticsRoleSettingsTest (rejects missing/invalid id),
AnalyticsProxyDoctorProbeTest (diagnostic flags a missing-id assignment, passes
when present).

Result: complete — evidence=repository-wide rg sweep + resolver/caller/diagnostic review.
