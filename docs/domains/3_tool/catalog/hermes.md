# Tool Catalog: `hermes`

[Back to tool catalog.](README.md)

## Catalog

These fields describe the Hermes tool's identity, backend, and support
model in Orbit.

| Field | Value |
| --- | --- |
| Slug | `hermes` |
| Label | Hermes |
| Backend | Supervisor-managed runtime as `agent` |
| Support model | Installable and removable by Orbit |
| Category | `agent` |
| Required node role | `agent` |

## Capabilities

`hermes` supports `tool:install`, `tool:remove`, lifecycle actions,
`tool:update`, snapshot and streamed `tool:logs`, `tool:credentials`,
tool-owned proxy route management, safe doctor fix, and safe doctor adopt.

## Credentials

`tool:credentials hermes` returns the web UI access metadata Orbit has
generated for the managed Hermes service.

Example JSON shape:

```json
{
  "success": {
    "data": {
      "credentials": {
        "tool": "hermes",
        "node": "agent-1",
        "fields": {
          "url": "https://hermes.agent",
          "username": "orbit",
          "password": "<generated-password>"
        }
      }
    },
    "meta": {}
  }
}
```

Returning credentials requires the caller's grant to the agent node to
include `tool:credentials`. The default agent self grant does not include
`tool:credentials`.

## Service Endpoint

`hermes` exposes an internal HTTPS proxy route owned by the tool at
`https://hermes.<agent-tld>` (default `https://hermes.agent`). The route
is internal: it is reachable only over the Orbit/WireGuard network and
has no public ingress baseline.

## Orbit Notes

Hermes is a first-party autonomous agent tool. Orbit installs and runs it
as the shared unprivileged `agent` user. `tool:update hermes` runs
Hermes's native update path through the Orbit-managed binary and then
restarts the Supervisor program that wraps it.

`tool:update hermes` from the agent node itself requires
`tool:update:agent-tools` on the self grant. `tool:install hermes`,
`tool:remove hermes`, `tool:stop hermes`, `tool:reconfigure hermes`, and
updates to baseline tools are not part of the default agent self grant;
they require explicit permissions from a gateway-admin.

Installing or starting Hermes while another agent tool is already running
on the same agent node emits the `tool.multiple_agent_tools_running`
warning. Orbit attributes activity at the node level, and the warning
surfaces that this attribution is weaker when more than one agent tool
runs on the same agent node.

Activity emitted while Hermes is working is attributed to the agent node
identity. Orbit does not claim per-tool sub-identities.

## Install Command

`tool:install hermes` runs the official Hermes installer as the shared
`agent` user:

```bash
sudo -u agent -H bash -lc 'curl -fsSL https://raw.githubusercontent.com/NousResearch/hermes-agent/main/scripts/install.sh | bash -s -- --skip-setup'
```

## Update Command

`tool:update hermes` runs Hermes's native self-update path:

```bash
sudo -u agent -H bash -lc 'hermes update'
```

## Verify Commands

`doctor --family=tool` and `tool:show hermes` use this verification
command:

```bash
sudo -u agent -H bash -lc 'hermes doctor'
```

## Doctor Relationship

`doctor --family=tool` verifies that the Supervisor program exists, that
the lifecycle state matches gateway expectations, and that managed
credential metadata is present. It also checks that the Hermes binary
version matches the gateway expected version when version tracking is
enabled, and that the tool's internal proxy route resolves to the active
agent role TLD.
