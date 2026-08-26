# `orbit app-development-setup-step:add`

Add one reusable development setup step to an app's defaults.

```bash
orbit app-development-setup-step:add [app] --command=<command> [--before=<id> | --after=<id>] [--timeout=<seconds>] [--json]
```

The row is app-owned, is not executed here, and is copied only when Orbit
creates a new instance on an `app-dev` node. The copied row is independent
instance-owned policy. Later app-default changes do not change existing
instances. The caller needs `app:write`; app selection resolves through a
visible app instance.

See [technical contract](technical/1_app-development-setup-step-add.md), [human output](technical/6.1_app-development-setup-step-add_output-render_human.md), and [JSON output](technical/6.2_app-development-setup-step-add_output-render_json.md).
