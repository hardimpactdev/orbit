# Tool Catalog: `opencode-cli`

[Back to tool catalog.](README.md)

## Catalog

These fields describe the OpenCode CLI tool's identity, backend, and support model in Orbit.

| Field | Value |
| --- | --- |
| Slug | `opencode-cli` |
| Label | OpenCode CLI |
| Backend | Installed OpenCode CLI binary with a related node-owned `systemd` process named `opencode-server` |
| Support model | Installable and removable by Orbit |
| Category | `development` |

## Capabilities

`opencode-cli` supports `tool:install`, `tool:remove`,
`tool:reconfigure`, password reconfiguration, `tool:update`,
`tool:credentials`, proxy route metadata, safe doctor fix, and safe doctor
adopt. It also declares `tool:start`, `tool:stop`, `tool:restart`, and
`tool:logs` against exactly one process row whose canonical `tool` value is
`opencode-cli`.

`opencode-cli` declares a related singleton process, so `tool:install
opencode-cli` configures that process by default: a node-owned `systemd`
process named `opencode-server`, command `opencode serve -a`, with a
`tool=opencode-cli` dependency. The convergence is idempotent. Pass `--no-process`
to install the capability only.

## Credentials

`tool:credentials opencode-cli` returns connection and authentication fields
for the managed OpenCode Server service.

Example JSON shape:

```json
{
  "success": {
    "data": {
      "credentials": {
        "tool": "opencode-cli",
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
    "meta": []
  }
}
```

Orbit-managed OpenCode Server must have generated authentication credentials.
When adopted state has no password configured, the credential fields must
explicitly indicate that no authentication is set rather than returning a
misleading empty password.

## Service Endpoint

`opencode-cli` exposes a tool-owned HTTPS proxy route at
`https://opencode.<node-tld>` for development nodes.

## Orbit Notes

OpenCode CLI is the installed agent IDE capability. The related
`opencode-server` process is the runtime unit that serves the OpenCode API.
Password reset is owned by `tool:reconfigure opencode-cli --password=<password>`.

`tool:update opencode-cli` currently runs OpenCode's native `opencode
upgrade` command through the Orbit-managed binary. Update remains tool-owned.
Declared runtime verbs dispatch the owning process action for the exact
`opencode-server` row; missing or duplicate matching rows fail explicitly.

## Doctor Relationship

`doctor --family=tool` owns capability and expected-state checks, credential
metadata presence, and safe repair/adoption boundaries. The related `systemd`
process row and its drift belong to the process family even when a declared
`tool:*` runtime verb addresses it.
