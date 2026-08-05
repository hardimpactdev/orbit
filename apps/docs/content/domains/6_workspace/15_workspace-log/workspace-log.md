# `orbit workspace:log [target]`

[Back to Workspace commands.](../README.md)

Read or follow the fixed Laravel application log for a Workspace.

`workspace:log` reads only
`<resolved authorized workspace root>/storage/logs/laravel.log` on the
workspace's instance serving node. The public logical path is always
`storage/logs/laravel.log`. Authorization is `workspace:read` only.

Lifecycle run history is [`orbit workspace:run:log`](../7_workspace-run-log/workspace-run-log.md).

## Usage

```bash
orbit workspace:log feature-docs --instance=docs.development --lines=100
orbit workspace:log feature-docs --instance=docs.development --follow
orbit workspace:log https://feature-docs.docs.test --json
orbit workspace:log feature-docs --instance=docs.development --node=app-dev-1
```

## Behavior Summary

- **Target**: Public workspace name (slug) or URL/hostname that resolves to a
  workspace proxy route. Optional `--instance=<app.instance>` disambiguates
  duplicate workspace names.
- **Fixed path**: Always `storage/logs/laravel.log` under the authorized
  workspace root. No arbitrary `--path`.
- **Flags**: `--lines=100`, `--follow`, `--json`, `--node` (serving-node
  constraint only). Rejects `--json` with `--follow`.
- **Missing file**: Bounded read succeeds with empty lines and
  `file_exists=false`.
- **Follow**: `tail -F` semantics—wait for creation, survive rotation,
  history-before-live.

## Related

- [`workspace:run:log`](../7_workspace-run-log/workspace-run-log.md)
- [`instance:log`](../../5_app/29_instance-log/instance-log.md)
- [`app:log`](../../5_app/30_app-log/app-log.md)

***

**Technical Contract:** [`technical/1_workspace-log.md`](technical/1_workspace-log.md)
