# Tool Catalog: `hermes`

[Back to tool catalog.](README.md)

## Catalog

These fields describe the Hermes tool's identity, backend, and support
model in Orbit.

| Field | Value |
| --- | --- |
| Slug | `hermes` |
| Label | Hermes |
| Backend | Host-managed runtime as the unprivileged `agent` user |
| Support model | Installable and removable by Orbit |
| Category | `agent` |
| Supported operating systems | Linux |
| Required container provider | None; host-managed runtime |
| Runtime user | `agent` |
| Route requirement | The target node has its mandatory TLD |
| Isolation | Shared unprivileged `agent` user; no privileged `orbit` runtime |

## Capabilities

`hermes` supports `tool:install`, `tool:remove`, `tool:update`,
`tool:credentials`, proxy route metadata, safe doctor fix, and safe doctor
adopt. It does not declare generic lifecycle or logs verbs; any future runtime
verb must name one exact runtime under the capability gate.

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
          "url": "https://hermes.agent-1",
          "username": "orbit",
          "password": "<generated-password>"
        }
      }
    },
    "meta": []
  }
}
```

Returning credentials requires the caller's grant to the node to
include `tool:credentials`. The default agent self-grant does not include
`tool:credentials`.

## Service Endpoint

`hermes` exposes an internal HTTPS proxy route owned by the tool at
`https://hermes.<node-tld>` (for example `https://hermes.agent`). The route
is internal: it is reachable only over the Orbit/WireGuard network and
has no ingress baseline. The default reverse-proxy upstream is
`http://host.docker.internal:8080`. OpenClaw uses its documented default port
`18789` on the same agent node so both tool UIs can co-host without colliding
with each other or with Orbit Caddy's private backend on `8081`.

## Orbit Notes

Hermes is a first-party autonomous agent tool. Orbit installs it for the shared
unprivileged `agent` user. `tool:update hermes` runs Hermes's native update
path through the Orbit-managed binary. The agent runtime must be able to execute
`/home/agent/.local/bin/orbit --version --local` through the owner-user shim
without sudo or write access to owner Orbit config or install metadata. It does
not implicitly restart related runtime processes.

`tool:update hermes` from the node itself requires `tool:update` on the
self-grant. `tool:install hermes`, `tool:remove hermes`,
`tool:reconfigure hermes`, and `tool:credentials` are not part of the default
agent self-grant; they require explicit permissions from a gateway-admin.

Installing Hermes while another agent tool is already running
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

Before the tool row, proxy route, or installer is applied, Orbit verifies the
explicit Linux platform, mandatory node TLD, existence of the `agent` user, and
that the user is unprivileged. A failed check returns
`tool.constraint_unsatisfied` with stable constraint metadata.

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
target node's configured TLD. Runtime process lifecycle drift belongs to the process
family.
