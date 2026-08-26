# Technical Contract: `orbit app-development-setup-step:remove`

**Owner:** `app`. **Effects:** `write`.

The command removes one app-owned default by ID after `--force` consent and
`app:write` authorization through a visible app instance. It does not contact
nodes, execute a step, or alter any existing instance pipeline. New app-dev
instances no longer receive the removed default. Unknown IDs return
`app.setup_step_not_found`; missing consent returns the shared destructive
confirmation failure.

## Renderers

- [Human](6.1_app-development-setup-step-remove_output-render_human.md)
- [JSON](6.2_app-development-setup-step-remove_output-render_json.md)
