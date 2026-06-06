# Tool Catalog: `hermes`

[Back to tool catalog.](README.md)

## Catalog

These fields describe the Hermes tool's identity, backend, and support
model in Orbit.

| Field | Value |
| --- | --- |
| Slug | `hermes` |
| Label | Hermes |
| Backend | Docker-managed runtime as `agent` |
| Support model | Installable and removable by Orbit |
| Category | `agent` |
| Required node role | `agent` |

## Capabilities

`hermes` supports `tool:install`, `tool:remove`, `tool:update`,
`tool:credentials`, proxy route metadata, safe doctor fix, and safe doctor
adopt. Compatibility lifecycle and log commands may route to the related
Hermes runtime process; lifecycle ownership belongs to the process row.

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

Returning credentials requires the caller's grant to the node to
include `tool:credentials`. The default agent self-grant does not include
`tool:credentials`.

## Service Endpoint

`hermes` exposes an internal HTTPS proxy route owned by the tool at
`https://hermes.<agent-tld>` (default `https://hermes.agent`). The route
is internal: it is reachable only over the Orbit/WireGuard network and
has no ingress baseline.

## Orbit Notes

Hermes is a first-party autonomous agent tool. Orbit installs it for the shared
unprivileged `agent` user. `tool:update hermes` runs Hermes's native update
path through the Orbit-managed binary. It does not implicitly restart related
runtime processes.

`tool:update hermes` from the node itself requires `tool:update` on the
self-grant. `tool:install hermes`, `tool:remove hermes`, `tool:stop hermes`,
`tool:reconfigure hermes`, and `tool:credentials` are not part of the default
agent self-grant; they require explicit permissions from a gateway-admin.

Installing or starting Hermes while another agent tool is already running
on the same node emits the `tool.multiple_agent_tools_running`
warning. Orbit attributes activity at the node level, and the warning
surfaces that this attribution is weaker when more than one agent tool
runs on the same node.

Activity emitted while Hermes is working is attributed to the node
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

`doctor --family=tool` verifies that the Hermes capability is installed and
that managed credential metadata is present. It also checks that the Hermes
binary version matches the gateway expected version when version tracking is
enabled, and that the tool's internal proxy route metadata resolves to the
active agent role TLD. Runtime process lifecycle drift belongs to the process
family.
