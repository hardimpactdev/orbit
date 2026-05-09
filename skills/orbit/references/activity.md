# Activity Commands

Gateway-owned activity history. Records every CLI/API command's type, effect, subject, causer, and correlation id so you can trace who did what. Spec: [`docs/commands/17_activity/`](../../../docs/commands/17_activity/).

Activity is **history**, not metrics and not live state. Use `doctor` for live state.

## `orbit activity:list`

List activity entries (most recent first).

```bash
orbit activity:list [--app=<name>] [--node=<name>]
                    [--effect=read|write|destructive]
                    [--correlation=<uuid>] [--limit=25] [--json]
```

| Option | Default | Notes |
|---|---|---|
| `--app` | — | Filter by app subject. |
| `--node` | — | Filter by node subject. |
| `--effect` | — | Filter by effect category. |
| `--correlation` | — | Filter by correlation UUID — useful when one CLI call fans out to multiple gateway operations and you want the whole chain. |
| `--limit` | 25 | Max rows. |

Examples:

```bash
orbit activity:list --app=myapp --effect=destructive
orbit activity:list --node=prod-1 --limit=100 --json
orbit activity:list --correlation=2b1a3c4d-… --json
```

## `orbit activity:show [id]`

Show one activity entry — full description, properties, channel, correlation.

```bash
orbit activity:show [<id>] [--json]
```
