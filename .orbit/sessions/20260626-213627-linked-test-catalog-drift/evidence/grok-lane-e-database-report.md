# Lane E Database Linked-Test Remediation Report

Worker: Grok (Solo process 2062, lane-e-database-linked-test-audit)
Worktree: `/Users/nckrtl/orbit/.worktrees/linked-test-catalog-drift`

## Summary

Replaced 28 stale `apps/gateway/tests/Feature/Commands/Database/*` references across database command technical docs with current on-disk CLI, gateway API, and unit tests. Narrowed overclaimed renderer and error-envelope rows. Recorded explicit coverage-gap prose where routine tests do not exercise documented behavior.

## Changed Files (35)

All under `apps/docs/content/domains/18_database/**`:

- `1_database-list/technical/{1_database-list,6.1_database-list_output-render_human,6.2_database-list_output-render_json}.md`
- `2_database-show/technical/{1_database-show,6.1_database-show_output-render_human,6.2_database-show_output-render_json}.md`
- `3_database-add/technical/{1_database-add,6.1_database-add_output-render_human,6.2_database-add_output-render_json}.md`
- `4_database-update/technical/{1_database-update,6.1_database-update_output-render_human,6.2_database-update_output-render_json}.md`
- `5_database-remove/technical/{1_database-remove,5.1_database-remove_input-mode_interactive,5.2_database-remove_input-mode_non-interactive,6.1_database-remove_output-render_human,6.2_database-remove_output-render_json}.md`
- `6_database-attach/technical/{1_database-attach,6.1_database-attach_output-render_human,6.2_database-attach_output-render_json}.md`
- `7_database-detach/technical/{1_database-detach,6.1_database-detach_output-render_human,6.2_database-detach_output-render_json}.md`
- `8_database-query/technical/{1_database-query,6.1_database-query_output-render_human,6.2_database-query_output-render_json}.md`
- `9_database-tables/technical/{1_database-tables,6.1_database-tables_output-render_human,6.2_database-tables_output-render_json}.md`
- `10_database-schema/technical/{1_database-schema,6.1_database-schema_output-render_human,6.2_database-schema_output-render_json}.md`
- `11_database-describe/technical/{1_database-describe,6.1_database-describe_output-render_human,6.2_database-describe_output-render_json}.md`

Unchanged (already correct): `12_database-add-user/**`, `internal/1_database-query-local/**`, `database-doctor.md`.

## Tests Inspected

| Path | Role |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Database/DatabaseReadCommandsTest.php` | Read commands: list, show, tables, schema, describe |
| `apps/cli/tests/Feature/Commands/Database/DatabaseWriteCommandsTest.php` | Write commands: add, update, remove, attach, detach, query, add-user |
| `apps/cli/tests/Feature/InternalDatabaseQueryLocalCommandTest.php` | Internal local query executor (unchanged) |
| `apps/gateway/tests/Feature/Http/Api/Database/DatabaseConnectionApiTest.php` | Registry CRUD, query, tables API, authorization |
| `apps/gateway/tests/Feature/Http/Api/Database/DatabaseAddUserControllerTest.php` | add-user API (unchanged) |
| `apps/gateway/tests/Unit/Services/DatabaseConnections/DatabaseQueryClassifierTest.php` | SQL read/write classification |
| `apps/gateway/tests/Unit/Services/DatabaseConnections/DatabaseConnectionExecutorTest.php` | SQLite locality dispatch |
| `apps/gateway/tests/Unit/Services/DatabaseConnections/{Probe,Restorer,Adopter}Test.php` | Doctor unit coverage (referenced by doctor doc, not changed) |

## Coverage Rows Added / Narrowed

- **Split contract pattern**: command technical docs now cite CLI + gateway API tests (mirrors completed activity lane).
- **Read commands** (`list`, `show`, `tables`, `schema`, `describe`): `DatabaseReadCommandsTest.php` + gateway API where applicable.
- **Write commands** (`add`, `update`, `remove`, `attach`, `detach`, `query`): `DatabaseWriteCommandsTest.php` + gateway API where applicable.
- **Query command**: added `DatabaseQueryClassifierTest.php` and `DatabaseConnectionExecutorTest.php` for classification and SQLite handoff.
- **JSON renderer docs**: narrowed claims; added CLI-ownership and partial-coverage gap notes where gateway renderer tests no longer exist.
- **Describe/schema/tables JSON renderers**: replaced vague "documented error-envelope coverage" with specific `missing-target` / `missing-table` validation only.

## Coverage Gaps Left (explicit prose)

| Area | Gap |
| --- | --- |
| `database:add` / `database:update` | Slug-collision handling |
| `database:remove` interactive | Declined confirmation cancel path |
| `database:remove` human | Destructive-consent failure string |
| `database:show` human | Not-found failure string |
| `database:detach` | Mapping-not-found envelopes |
| `database:query` JSON | Truncation metadata, exhaustive documented error codes |
| `database:tables` / `database:schema` gateway | Ambiguity handling, metadata failure codes |
| `database:schema` gateway | `/schema` endpoint-specific behavior (API test covers `/tables` only) |
| `database:describe` gateway | Describe API resolution and failure codes |
| JSON renderers (add/update/attach/list/etc.) | Exhaustive documented renderer error codes |

## Verification Commands

```bash
cd /Users/nckrtl/orbit/.worktrees/linked-test-catalog-drift
rg -c 'apps/gateway/tests/Feature/Commands/Database' apps/docs/content/domains/18_database
# => 0 stale gateway command test refs

rg -o '`apps/[^`]+\.php`' apps/docs/content/domains/18_database \
  | sed -E 's/^[^:]+://; s/`//g' | sort -u \
  | while read p; do [ -f "$p" ] || echo "MISSING: $p"; done
# => only pre-existing doctor contract gap (see Uncertainty)
```

## Uncertainty / Out of Scope

- `database-doctor.md` still cites missing `apps/gateway/tests/Feature/Doctor/DatabaseConnectionsFamilyDoctorContractTest.php`. Pre-existing; not part of the 28 command-catalog missing refs for this lane. Feature owner may reconcile separately.
- `command-catalog.json` not regenerated in this lane (serialized reconciliation step).
- Catalog drift measurement against stale generated JSON still shows 28 missing until regeneration.

## Blockers / Risks

- None for docs-source remediation.
- Risk: post-regeneration catalog may surface additional doctor-family drift if doctor doc is in catalog scope.