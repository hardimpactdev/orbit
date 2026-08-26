# `orbit app-development-setup-step:remove`

Remove one app-owned development default.

```bash
orbit app-development-setup-step:remove [app] <step> --force [--json]
```

Removal requires explicit destructive consent and `app:write`. It removes only
the app default; copied rows in existing instances remain unchanged. See the
[technical contract](technical/1_app-development-setup-step-remove.md), [human output](technical/6.1_app-development-setup-step-remove_output-render_human.md), and [JSON output](technical/6.2_app-development-setup-step-remove_output-render_json.md).
