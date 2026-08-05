# `orbit app:log [url]`

[Back to App and Instance commands.](../README.md)

Read or follow the fixed Laravel application log for the Instance or Workspace
resolved from a registered proxy URL or hostname.

`app:log` accepts only HTTP(S) URLs or bare hostnames. It does not accept dotted
`app.instance` or bare workspace names—use `instance:log` or `workspace:log`
for those. Authorization is `instance:read` or `workspace:read` for the
**resolved** target type.

## Usage

```bash
orbit app:log https://docs.test --lines=100
orbit app:log feature-docs.docs.test --follow
orbit app:log https://docs.test --json
```

## Behavior Summary

- **Target**: Strict URL/hostname only; resolves via registered proxy route to
  exactly one Instance or Workspace.
- **Fixed path**: Always `storage/logs/laravel.log` under the authorized root.
- **Flags**: Shared application-log flags (`--lines`, `--follow`, `--json`,
  `--node`) with the same conflict and placement rules as `instance:log` /
  `workspace:log`.

## Related

- [`instance:log`](../29_instance-log/instance-log.md)
- [`workspace:log`](../../6_workspace/15_workspace-log/workspace-log.md)

***

**Technical Contract:** [`technical/1_app-log.md`](technical/1_app-log.md)
