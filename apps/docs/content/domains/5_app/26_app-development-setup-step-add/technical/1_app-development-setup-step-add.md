# Technical Contract: `orbit app-development-setup-step:add`

**Owner:** `app`. **Effects:** `write`.

```bash
orbit app-development-setup-step:add [app] --command=<command> [--before=<id> | --after=<id>] [--timeout=<seconds>] [--json]
```

`--command` is non-empty; `--before` and `--after` are mutually exclusive
positive step IDs; `--timeout` is positive seconds and defaults to `600`;
`--json` selects JSON. The caller needs `app:write`, checked through a visible
app instance.

The gateway writes one ordered app-owned default. It contacts no node and does
not execute the command. New `app-dev` instances copy the row into their
existing instance setup pipeline. App-prod instances do not copy it. Invalid
input returns `validation_failed`; unknown apps return `app.not_found`; denied
calls return `authorization_failed`.

## Renderers

- [Human](6.1_app-development-setup-step-add_output-render_human.md)
- [JSON](6.2_app-development-setup-step-add_output-render_json.md)
