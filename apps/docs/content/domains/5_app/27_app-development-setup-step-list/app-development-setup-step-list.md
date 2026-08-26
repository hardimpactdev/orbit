# `orbit app-development-setup-step:list`

List an app's ordered reusable development setup defaults.

```bash
orbit app-development-setup-step:list [app] [--json]
```

This reads gateway-owned defaults only. It does not inspect nodes, execute
steps, or report instance runs. An authorized empty list succeeds. The caller
needs `app:read`, checked through a visible app instance.

See [technical contract](technical/1_app-development-setup-step-list.md), [human output](technical/6.1_app-development-setup-step-list_output-render_human.md), and [JSON output](technical/6.2_app-development-setup-step-list_output-render_json.md).
