# Activity Commands

Gateway-owned activity history. Gateway API endpoints record type, effect, subject, causer, and correlation id. A CLI command that calls a matching gateway API relies on that endpoint's activity entry. CLI-only local state changes emit nothing because the CLI has no trusted shared activity writer. Spec: [`apps/docs/content/domains/16_activity/`](../../../../apps/docs/content/domains/16_activity/).

Activity is **history**, not metrics and not live state. Use `doctor` for live state.

## `orbit activity:list`

List activity entries (most recent first).

```bash
orbit activity:list [--app=<name>] [--node=<name>]
                    [--effect=read|write|destructive]
                    [--correlation=<uuid>] [--include-internal]
                    [--limit=25] [--json]
```

| Option | Default | Notes |
|---|---|---|
| `--app` |  -  | Filter by app subject. |
| `--node` |  -  | Filter by node subject. |
| `--effect` |  -  | Filter by effect category. |
| `--correlation` |  -  | Filter by correlation UUID  -  useful when one CLI call fans out to multiple gateway operations and you want the whole chain. |
| `--include-internal` | off | Include internal-lane activity rows. |
| `--limit` | 25 | Max rows. |

Examples:

```bash
orbit activity:list --app=myapp --effect=destructive
orbit activity:list --node=prod-1 --limit=100 --json
orbit activity:list --correlation=2b1a3c4d-... --json
```

## `orbit activity:show [id]`

Show one activity entry  -  full description, properties, channel, correlation.

```bash
orbit activity:show [<id>] [--json]
```
