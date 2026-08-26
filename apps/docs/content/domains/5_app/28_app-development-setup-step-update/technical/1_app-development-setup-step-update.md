# Technical Contract: `orbit app-development-setup-step:update`

**Owner:** `app`. **Effects:** `write`.

The command updates the selected app-owned default by ID. It accepts the
signature shown on the public page; supplied fields replace command, order, or
timeout, and omitted fields remain unchanged. It writes no instance rows and
does not execute or migrate existing pipelines. New app-dev instances use the
updated defaults. Unknown IDs return `app.setup_step_not_found`; denied calls
return `authorization_failed`.

## Renderers

- [Human](6.1_app-development-setup-step-update_output-render_human.md)
- [JSON](6.2_app-development-setup-step-update_output-render_json.md)
