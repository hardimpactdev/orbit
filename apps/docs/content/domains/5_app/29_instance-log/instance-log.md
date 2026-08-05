# `orbit instance:log [instance]`

[Back to App and Instance commands.](../README.md)

Read or follow the fixed Laravel application log for an Instance.

`instance:log` reads only
`<resolved authorized instance application root>/storage/logs/laravel.log`
on the instance serving node. The public logical path is always
`storage/logs/laravel.log`. Authorization is `instance:read` only.

## Usage

```bash
orbit instance:log docs.development --lines=100
orbit instance:log docs.development --follow
orbit instance:log https://docs.test --json
orbit instance:log docs.development --node=app-dev-1
```

## Behavior Summary

- **Target**: Canonical `app.instance` or URL/hostname that resolves to an
  instance proxy route. No bare workspace-only hostnames.
- **Fixed path**: Always `storage/logs/laravel.log` under the authorized
  application root (ordinary root or active-release `live/` layout consistent
  with runtime root resolution). No arbitrary `--path`.
- **Flags**: `--lines=100`, `--follow`, `--json`, `--node` (serving-node
  constraint only). Rejects `--json` with `--follow`.
- **Missing file**: Bounded read succeeds with empty lines and
  `file_exists=false`.
- **Follow**: `tail -F` semantics—wait for creation, survive rotation,
  history-before-live.

## Related

- [`app:log`](../30_app-log/app-log.md)
- [`workspace:log`](../../6_workspace/15_workspace-log/workspace-log.md)
- [`process:log`](../../7_process/8_process-log/process-log.md)

***

**Technical Contract:** [`technical/1_instance-log.md`](technical/1_instance-log.md)
