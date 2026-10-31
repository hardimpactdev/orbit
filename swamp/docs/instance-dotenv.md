# Instance dotenv preparation

The inactive `dotenv.ts` module prepares environment text in memory. It renders
stored values in key order, expands the admitted Instance domain and
environment, and escapes dotenv quotes, backslashes, dollars and line breaks.
Unknown or unavailable references refuse with a bounded code. Keys, UTF-8 value
bytes, key count and final file bytes follow the existing Instance limits.

The testing-file merge preserves unmanaged lines. It replaces each managed key
once, removes duplicate or no-longer-supplied managed assignments, and appends
missing supplied keys. Duplicate or incomplete managed updates refuse.

This is preparation WIP, published separately from local root execution. It
performs no file write, process launch, UID switch, secret delivery, socket call
or result projection. Rendered values are private material, never a workflow
result or log entry. The protected-value delivery and app-account execution
contracts must be approved before a workflow consumes it. Existing Gateway and
agent paths remain active; no writer switch or removal is included.
