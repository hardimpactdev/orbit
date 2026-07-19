# `orbit workspace:log <run>`

Show captured output for a single workspace setup, teardown, or lifecycle run.

## Usage

```bash
orbit workspace:log <run> [--json]
```

## Description

The `workspace:log` command retrieves the captured execution output for one
workspace run resolved by ID. It is the primary tool for debugging failed
workspace transitions and auditing what each step actually printed and
returned.

This command reads **stored output** from the gateway database. It does not
stream live process logs. For live logs from a running application process,
use [`orbit process:logs`](../../7_process/8_process-logs/process-logs.md).

`workspace:log` pairs with
[`orbit workspace:history`](../6_workspace-history/workspace-history.md):
`workspace:history` lists runs, `workspace:log` shows the captured detail of
one run.

## Arguments

- `<run>`: Required. The unique gateway-owned ID of the workspace run to
  inspect. Use
  [`orbit workspace:history`](../6_workspace-history/workspace-history.md)
  or the `latest_setup_run.run_id` field from
  [`orbit workspace:show`](../4_workspace-show/workspace-show.md) to find
  run IDs.

## Options

- `--json`: Output JSON.

## Output

### Human Output

Human output displays a step-by-step view of the captured run, with the executed
command, failure details, and captured stdout/stderr for each step.

### JSON Output

Use `--json` to receive a structured response containing the full run
detail, including per-step timing and truncation flags.

```json
{
  "success": {
    "data": {
      "run": {
        "id": 12,
        "status": "failed",
        "started_at": "2026-04-30T10:00:00Z",
        "finished_at": "2026-04-30T10:00:12Z",
        "duration_ms": 12500,
        "steps": [
          {
            "name": "Install dependencies",
            "command": "composer install",
            "status": "failure",
            "exit_code": 1,
            "stdout": "...",
            "stderr": "...",
            "stdout_truncated": false,
            "stderr_truncated": false,
            "started_at": "2026-04-30T10:00:03Z",
            "finished_at": "2026-04-30T10:00:11Z",
            "duration_ms": 8200
          }
        ]
      }
    },
    "meta": []
  }
}
```

## Requirements

- The CLI caller can reach the Orbit gateway.
- The caller is authorized to inspect the workspace that owns the run.

## Related

- [`orbit workspace:history`](../6_workspace-history/workspace-history.md)
- [`orbit workspace:show`](../4_workspace-show/workspace-show.md)
- [`orbit workspace:setup`](../2_workspace-setup/workspace-setup.md)
- [`orbit doctor --family=workspace`](../workspace-doctor.md)

[See the technical contract for detailed behavior and failure semantics.](technical/1_workspace-log.md)
