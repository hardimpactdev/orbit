# Tool Catalog: `opencode-server`

[Back to tool catalog.](README.md)

## Catalog

These fields describe the OpenCode Server tool's identity, backend, and support model in Orbit.

| Field | Value |
| --- | --- |
| Slug | `opencode-server` |
| Label | OpenCode Server |
| Backend | user systemd service |
| Support model | Installable and removable by Orbit |
| Category | `development` |

## Capabilities

`opencode-server` supports `tool:install`, `tool:remove`, lifecycle actions,
`tool:reconfigure`, password reconfiguration, `tool:update`, snapshot and
streamed `tool:logs`, `tool:credentials`, tool-owned proxy route management,
safe doctor fix, and safe doctor adopt.

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

`tool:update opencode-server` runs OpenCode's native `opencode upgrade` command
through the Orbit-managed binary and then restarts the user systemd service.

## Doctor Relationship

`doctor --family=tool` verifies the managed user service, expected lifecycle
state, logs availability, credential metadata presence, and safe
repair/adoption boundaries.
