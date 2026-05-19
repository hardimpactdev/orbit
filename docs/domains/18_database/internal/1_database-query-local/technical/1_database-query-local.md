# Technical Contract: `orbit database:query-local`

[Back to internal `database:query-local` documentation.](../database-query-local.md)

**Owner:** `database`.

**Effects:** `read`, `write`, `internal`.

**Prerequisites:**
- Invocation comes only from trusted Orbit gateway orchestration.
- The command runs on the node that owns the resolved SQLite file path.

## Signature

```bash
orbit database:query-local
```

## Input Contract

- The command accepts no arguments and no options.
- Input is stdin payload only.
- Stdin must be one strict JSON object with the resolved SQLite path, SQL,
  statement classification, limit, and an execution correlation identifier.
- Any non-JSON, partial JSON, or unsupported top-level shape fails with
  `error.code=validation_failed`.
- Write-capable SQL without explicit write consent fails with
  `error.code=database_query.write_not_allowed`.

## Behavior Contract

### Local SQLite Rules

- Opens the supplied SQLite file path locally on the owning node.
- Executes the supplied SQL exactly once.
- Returns strict JSON only. No prompts, progress tree, prose, ANSI output, or
  mixed stdout/stderr framing are allowed.
- Raw result rows may appear in the response body to the gateway, but they must
  never be mirrored into activity properties.

## Renderer Contracts

- [JSON renderer](6.2_database-query-local_output-render_json.md)

## Failure Semantics

- `validation_failed` for malformed stdin payloads.
- `database_query.write_not_allowed` for write-capable SQL without write consent.
- `database_query.execution_failed` for SQLite open or execution failures.

## Activity Logging

Local execution may emit node-local telemetry, but any structured activity
mirrored back to the gateway must omit raw result rows and full SQL text.
