# `orbit app:log [target]`

[Back to App and Instance commands.](../README.md)

Read or follow the fixed Laravel application log for the Instance or Workspace
resolved from a registered proxy URL or hostname.

`app:log` accepts only HTTP(S) URLs or bare hostnames. An explicit bare
hostname is always eligible for exact registered proxy-route resolution—no
`https://` is required—even when the same text equals a canonical
`app.instance` selector. Exact registered proxy hostnames win for `app:log`.
Use `instance:log` for explicit `app.instance` selectors and `workspace:log`
for bare workspace names. Authorization is `instance:read` or `workspace:read`
for the **resolved** target type.

## Usage

```bash
orbit app:log https://docs.test --lines=100
orbit app:log feature-docs.docs.test --follow
orbit app:log servauto-app.nmbp --json
orbit app:log https://docs.test --json
```

## Behavior Summary

- **Target**: Strict URL/hostname only; resolves via exact registered proxy
  route to exactly one Instance or Workspace. Bare hostnames do not need a
  scheme, and a hostname that collides with an `app.instance` spelling still
  resolves as a proxy host when that domain is registered.
- **Fixed path**: Always `storage/logs/laravel.log` under the authorized root.
- **Flags**: Shared application-log flags (`--lines`, `--follow`, `--json`,
  `--node`) with the same conflict and placement rules as `instance:log` /
  `workspace:log`.

## Related

- [`instance:log`](../29_instance-log/instance-log.md)
- [`workspace:log`](../../6_workspace/15_workspace-log/workspace-log.md)

***

**Technical Contract:** [`technical/1_app-log.md`](technical/1_app-log.md)
