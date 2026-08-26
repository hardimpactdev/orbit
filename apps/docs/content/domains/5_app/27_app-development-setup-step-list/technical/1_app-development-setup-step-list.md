# Technical Contract: `orbit app-development-setup-step:list`

**Owner:** `app`. **Effects:** `read`.

```bash
orbit app-development-setup-step:list [app] [--json]
```

The command resolves one app, checks `app:read` through a visible app
instance, and returns gateway-owned defaults sorted by `order`. It performs no
node probe, copy, execution, or mutation. Only new app-dev instance creation
consumes these defaults. Unknown apps return `app.not_found`; unauthorized
callers return `authorization_failed`.

## Renderers

- [Human](6.1_app-development-setup-step-list_output-render_human.md)
- [JSON](6.2_app-development-setup-step-list_output-render_json.md)
