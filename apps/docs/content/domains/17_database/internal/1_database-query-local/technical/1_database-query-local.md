# Technical Contract: `orbit internal:database-query-local`

[Back to internal `internal:database-query-local` documentation.](../database-query-local.md)

**Owner:** `database`.

**Effects:** `read`, `write`, `internal`.

**Prerequisites:**
- Invocation comes only through `RemoteLocalExecutor`.
- A valid gateway-issued operation token is supplied.
- The command runs on the node that owns the resolved SQLite file path.

## Signature

```bash
orbit internal:database-query-local --operation-token=<token> --json
```

## Input Contract

- The command accepts no public arguments.
- `--operation-token` is required and must validate before stdin is read.
- `--json` selects the strict JSON envelope renderer.
- Input is stdin payload only.
- Stdin must be one strict JSON object with the resolved SQLite connection
  payload, SQL, write/full flags, limit, timeout, and max JSON byte limit.
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
- Delegates query classification and execution to a node-local action/service;
  the command class stays limited to token validation, stdin mapping, action
  invocation, and envelope rendering.
- Raw result rows may appear in the response body to the gateway, but they must
  never be mirrored into activity properties.

## Renderer Contracts

- [JSON renderer](6.2_database-query-local_output-render_json.md)

## Failure Semantics

- `missing_token` for direct invocation without an operation token.
- Operation-token verification inherits the shared internal-executor safe denial
  codes from the gateway verifier:
  - `arguments_mismatch` when bound command arguments or context do not match
  - `target_node_mismatch` when the token targets a different node
  - `command_mismatch` when the token command does not match this internal
    command
  - `operation.already_dispatched` when the token was already consumed
  - `operation.not_found` when the bound operation cannot be found
  - `invalid_token` for malformed, expired, unknown, or transport-failed
    verification outcomes (including unrecognized gateway denial reasons)
- `validation_failed` for malformed stdin payloads.
- `database_query.write_not_allowed` for write-capable SQL without write consent.
- `database_query.execution_failed` for SQLite open or execution failures.

## Activity Logging

Local execution may emit node-local telemetry, but any structured activity
mirrored back to the gateway must omit raw result rows and full SQL text.
