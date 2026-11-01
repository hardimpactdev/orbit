# Database read and write fixture preparation

These six F13 variants prepare `database.read` and `database.write` for MySQL, PostgreSQL and SQLite on `["app-dev+database"]`. They drive the real CLI, normal admission, native execution and terminal projection. SQL prepare calls create a disposable managed server and connection; the Gateway generates credentials. SQLite prepare registers a new path and the workload-only `before_request` creates its database. No credential or secret appears in argv. SQL text here contains only public proof data.

## Legacy parity

The migration baseline is `6291bb036`. `Actions/DatabaseConnections/QueryDatabaseConnectionAction.php` normalizes and classifies SQL before `DatabaseInspectionExecutor::query`; `DatabaseConnectionInspectionTest.php` covers read-only defaults, explicit writes, stacked-statement refusal, query privacy, table/schema/column metadata, missing tables, linked SQLite Nodes and unsupported Redis. `PdoDatabaseInspectorTest.php` and `SqliteNodeDatabaseInspectorTest.php` cover the execution boundary and safe refusal mapping. Legacy SQL runs through Gateway PDO; SQLite runs on its owning Node. The current workflows use official SSH from 209 to those admitted identities. No phase is reordered by this preparation.

| Kind | Request | Additional prepared checks |
| --- | --- | --- |
| `database.read` | Read three owned rows in order | Exact native/public result and Connection identity; tables/schema/describe agree; missing table has the bounded public refusal; unflagged DELETE and stacked SQL fail without changing rows; a recursive read returns exactly the first 500 rows and declares truncation |
| `database.write` | Insert one row into the owned table | Native/public row count is one; a separate `database.read` sees exactly the inserted row; unflagged DELETE and stacked SQL cannot change it; SQLite ATTACH and VACUUM INTO fail without creating the escape file or changing existing rows |

SQLite parity also reads the owned file directly on `app-dev` with `mode=ro`, checks integrity and checks the escape file is absent. SQL parity uses a distinct read Operation through the public CLI; it does not copy generated credentials out of the Gateway for a second client. Every supplementary Operation is checked for the same Connection and driver. Public Operation inputs must contain neither SQL nor private-material fields. This checks privacy of these public statements; it does not claim a secret-redaction topology case.

## Fixture contract and readiness

Both `before_request` and parity run from the staged repository root with repo-relative paths. `PROOF_EXEC` selects the disposable topology operator or `app-dev`; only the frozen five `PROOF_*` variables are available. CLI follows get an explicit 1,800-second guest timeout, with a 30-second outer transport margin, rather than the harness's 60-second default. SQLite setup writes only its fresh `/home/orbit/proof-database-inspection-<run>/proof.sqlite` directory and refuses an existing directory. It never writes Gateway or Swamp state. One-shot teardown owns the records, databases, volumes and SQLite directory; a persistent topology keeps its owned fixtures until `down`. Production Connection 47 and the load owner's historical target-v2 cleanup are excluded.

These are draft fixtures, not an executed F13 receipt. No topology is allocated and no kind behavior changes. The 1,800-second ceiling is the unmeasured-kind default, not a p95 sample. Null queue timeout records the roots' absent explicit `queueTimeout`; native inheritance is currently 600 seconds. The owning port must measure the kind and replace those values with explicit queue/phase/run budgets. The selected `sql` or `sqlite` step maps the legacy query phase; admission and projection are not native phase timers.

Remaining topology cases include production SQLite owner selection, missing/unreadable files and symlinks, Unicode/byte output bounds, secret-bearing result redaction and unsupported Redis. Existing Gateway/native tests are not receipts for these cases. Run local checker tests with `python3 -I swamp/proof/database-inspection/parity_test.py`. They include the actual PHP read policy, real temporary SQLite files and source bindings, without allocating infrastructure or starting an Operation.
