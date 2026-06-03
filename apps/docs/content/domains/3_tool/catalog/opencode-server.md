# Tool Catalog: `opencode-server`

[Back to tool catalog.](README.md)

## Catalog

These fields describe the OpenCode Server tool's identity, backend, and support model in Orbit.

| Field | Value |
| --- | --- |
| Slug | `opencode-server` |
| Label | OpenCode Server |
| Backend | Transitional Supervisor program; planned `systemd` process |
| Support model | Installable and removable by Orbit |
| Category | `development` |

## Capabilities

`opencode-server` supports `tool:install`, `tool:remove`, transitional
lifecycle actions, `tool:reconfigure`, password reconfiguration, `tool:update`,
snapshot and streamed `tool:logs`, `tool:credentials`, tool-owned proxy route
management, safe doctor fix, and safe doctor adopt.

## Credentials

`tool:credentials opencode-server` returns connection and authentication fields
for the managed OpenCode Server service.

Example JSON shape:

```json
{
  "success": {
    "data": {
      "credentials": {
        "tool": "opencode-server",
        "node": "app-1",
        "fields": {
          "host": "127.0.0.1",
          "port": 4096,
          "url": "https://opencode.test",
          "username": "orbit",
          "password": "<generated-password>"
        }
      }
    },
    "meta": {}
  }
}
```

Orbit-managed OpenCode Server must have generated authentication credentials.
When adopted state has no password configured, the credential fields must
explicitly indicate that no authentication is set rather than returning a
misleading empty password.

## Service Endpoint

`opencode-server` exposes a tool-owned HTTPS proxy route at
`https://opencode.<node-tld>` for development nodes.

## Orbit Notes

OpenCode Server is an agent IDE server capability. Password reset is owned by
`tool:reconfigure opencode-server --password=<password>`.

Runtime-model migration treats `opencode` as the installed capability and
`opencode-server` as the process name. The process will own lifecycle with
`runtime=systemd`; the current catalog slug remains transitional until that
migration lands.

`tool:update opencode-server` currently runs OpenCode's native `opencode
upgrade` command through the Orbit-managed binary and then restarts the
Supervisor program. After process migration, update remains tool-owned while
restart/log lifecycle belongs to the related process.

## Doctor Relationship

`doctor --family=tool` currently verifies the managed Supervisor program,
expected lifecycle state, logs availability, credential metadata presence, and
safe repair/adoption boundaries. After process migration, tool doctor owns
capability and expected-state checks while `doctor --family=process` owns the
related `systemd` process lifecycle.
