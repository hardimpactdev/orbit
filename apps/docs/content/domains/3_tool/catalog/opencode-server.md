# Tool Catalog: `opencode-server`

[Back to tool catalog.](README.md)

## Catalog

These fields describe the OpenCode Server tool's identity, backend, and support model in Orbit.

| Field | Value |
| --- | --- |
| Slug | `opencode-server` |
| Label | OpenCode Server |
| Backend | Node-owned `systemd` process named `opencode-server` with `tool=opencode` |
| Support model | Installable and removable by Orbit |
| Category | `development` |

## Capabilities

`opencode-server` supports `tool:install`, `tool:remove`,
`tool:reconfigure`, password reconfiguration, `tool:update`,
`tool:credentials`, proxy route metadata, safe doctor fix, and safe doctor
adopt. Start, stop, restart, and logs for the long-running server belong to
the related `opencode-server` process.

`opencode-server` declares a related singleton process, so `tool:install
opencode-server` configures that process by default: a node-owned `systemd`
process named `opencode-server`, command `opencode serve -a`, with a
`tool=opencode` dependency. The convergence is idempotent. Pass `--no-process`
to install the capability only.

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
`opencode-server` as the process name. The backfilled node-owned process row
owns lifecycle with `runtime=systemd`; the `opencode-server` tool row remains
the capability and compatibility payload record.

`tool:update opencode-server` currently runs OpenCode's native `opencode
upgrade` command through the Orbit-managed binary. Update remains tool-owned
while start, stop, restart, and logs belong to the related process.

## Doctor Relationship

`doctor --family=tool` owns capability and expected-state checks, credential
metadata presence, and safe repair/adoption boundaries. The related
`systemd` process lifecycle belongs to the process family.
