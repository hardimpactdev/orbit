# Database Doctor

[Back to Database commands.](README.md)

The database family doctor implements the
[Family Doctor Implementation Contract](../11_operation/3_doctor/technical/1_doctor.md#family-doctor-implementation-contract).
`key()` returns `database_connection`.

`doctor --family=database_connection` verifies whether gateway database
connection state still matches the supported env configuration for databases
that is present in app and workspace `.env` files.

The database family owns these facts:

- database connection records owned by the gateway: slug, driver, host, port,
  database name, SQLite path, username, optional node relationship, and
  encrypted credentials for secret fields;
- database connection targets owned by the gateway: the app or workspace
  mapping and the supported environment prefix used in that target's `.env`;
- supported env-prefix expansions in app and workspace `.env` files;
- drift between gateway connection intent and observed target `.env` values;
- adoption of database connection state for existing registered apps and
  workspaces during rollout, using their current `.env` files as the observed
  source.

App runtime health belongs to `app`. Workspace path and runtime artifacts
belong to `workspace`. Database service lifecycle and credentials generated for
managed MySQL/PostgreSQL/Redis instances belong to process-owned service
definitions. WireGuard route mutation belongs to node provisioning/topology
work.

## Probe Layers

The database probe reads gateway connection records, target mappings, and the
selected app/workspace `.env` files and checks these layers:

1. **Target mapping presence:** every selected database connection record has
   the app or workspace target mapping needed for the requested scope.
2. **Target env visibility:** the selected app or workspace has a readable
   `.env` file and the supported env-prefix keys can be inspected.
3. **Expected env presence:** gateway-owned keys for a mapped prefix exist in
   the target `.env` when that connection should be materialized there.
4. **Expected env shape:** observed env values for a mapped prefix match the
   gateway-owned connection record.
5. **WireGuard self-route diagnostics:** when a mapped managed database host
   equals the consuming node's own WireGuard service address, Linux nodes are
   diagnosed with `ip route get <wireguard-ip>` and must report a local route
   such as `local <ip> dev lo` or an equivalent local route. macOS reports
   `WireGuard self-route diagnostics are only supported on Linux.` and the
   database family does not mutate routes.
6. **Observed env extras:** during an explicit adoption scope, supported
   database env-prefix groups that appear in the target `.env` without a
   matching target mapping are eligible for adoption review.
7. **Observed target extras:** during an explicit adoption scope, supported
   database env-prefix groups that are present in a selected target `.env`
   without a matching gateway connection record are eligible for adoption.
8. **Verifiability:** malformed, partial, unsupported env-prefix groups, or
   unhealthy WireGuard self-route diagnostics for same-node connections are
   reported as unverifiable instead of being guessed into gateway state.

Observed `.env` keys outside the supported prefix model are not drift by
default. They are preserved during restore unless Orbit has an explicit target
mapping for that prefix and the operator selected database-family restore for
that target.

## Database Issue Codes

Each code below identifies a specific kind of drift the database probe can
detect.

| Code | Detected when |
| --- | --- |
| `database_connection.env_missing` | A mapped env prefix is expected from gateway state, but one or more supported keys for that prefix are absent from the target `.env`. |
| `database_connection.env_mismatch` | A mapped env prefix exists in the target `.env`, but one or more supported values differ from the gateway connection record. |
| `database_connection.env_extra` | During an explicit adoption scope, a supported env prefix exists in the target `.env` without a matching gateway target mapping for that target. |
| `database_connection.target_missing` | A gateway database connection record that should be materialized for the selected app or workspace has no matching target mapping. |
| `database_connection.target_extra` | During an explicit adoption scope, a selected app or workspace has an adopted-or-mappable database target that does not yet exist in gateway target mappings. |
| `database_connection.unverifiable` | The observed env-prefix group is partial, malformed, ambiguous, or uses unsupported fields so Orbit cannot safely compare, restore, or adopt it. |
| `database_connection.wireguard_self_route_unavailable` | A mapped managed database host points at the consuming node's own WireGuard service address, but Linux self-route diagnostics are missing/unhealthy or the platform does not support this diagnostic. |

## Database Fix Map

This table shows what `doctor --restore` does for each fixable issue code.

| Code | `doctor --restore` behavior |
| --- | --- |
| `database_connection.env_missing` | Write the stored gateway connection values for the mapped prefix into the selected app or workspace `.env`. |
| `database_connection.env_mismatch` | Rewrite the mapped prefix in the selected app or workspace `.env` so the supported keys match the stored gateway connection values. |
| `database_connection.target_missing` | Recreate the missing target mapping when the owning app or workspace and selected prefix are already unambiguous from gateway state. |

`doctor --restore` does not handle `database_connection.env_extra`,
`database_connection.target_extra`, `database_connection.unverifiable`, or
`database_connection.wireguard_self_route_unavailable`.

`doctor --restore` preserves unrelated `.env` keys and comments. It never
deletes unrelated database-looking keys without an explicit target mapping for
that prefix. Restore rewrites only the supported keys for the selected mapped
prefixes.

## Database Adopt Map

This table shows what `doctor --adopt` does for each adoptable issue code.

| Code | `doctor --adopt` behavior |
| --- | --- |
| `database_connection.env_extra` | Parse the observed supported env-prefix group, create or update a gateway database connection record, and create or update the matching target mapping for the selected app or workspace. |
| `database_connection.target_extra` | Materialize the missing gateway target mapping and attach it to the created or updated database connection record derived from the observed env-prefix group. |
| `database_connection.env_mismatch` | Update the existing gateway database connection record from the observed supported env-prefix values when the mapping already proves the same target and prefix. |

`doctor --adopt` does not guess unsupported prefixes, infer database ownership
from tool inventory, scan arbitrary files beyond the selected target `.env`, or
adopt partial env groups reported as `database_connection.unverifiable`.

Adoption rules:

- Parse observed `.env` values by supported prefixes only.
- Create or update `database_connections` from the normalized observed values.
- Create or update `database_connection_targets` for the selected app or
  workspace and observed prefix.
- Support first-rollout adoption for existing registered apps and workspaces
  that predate the `database_connection` family.
- Store adopted passwords in encrypted credentials instead of plain columns.

## Test Mapping

Required implementation test coverage:

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Doctor/DatabaseConnectionsFamilyDoctorContractTest.php` | Database-family dispatch, issue codes, restore map, adopt map, rollout adoption semantics, denied restore/adopt cases, and scope filtering. |
| `apps/gateway/tests/Unit/Services/DatabaseConnections/DatabaseConnectionProbeTest.php` | In-memory `.env` diff behavior, supported-prefix parsing, unverifiable groups, same-node WireGuard self-route diagnostics, and exclusion of unrelated `.env` keys from drift. |
| `apps/gateway/tests/Unit/Services/Nodes/NodeWireGuardSelfRouteProbeTest.php` | Read-only Linux/macOS WireGuard self-route diagnostics used by database and process doctors. |
| `apps/gateway/tests/Unit/Services/DatabaseConnections/DatabaseConnectionRestorerTest.php` | `.env` rewrite behavior, mapped-prefix updates, preservation of unrelated keys/comments, and no deletion without explicit target mapping. |
| `apps/gateway/tests/Unit/Services/DatabaseConnections/DatabaseConnectionAdopterTest.php` | Supported-prefix adoption, existing app/workspace rollout adoption, connection/target upsert behavior, and encrypted credential storage for passwords. |
