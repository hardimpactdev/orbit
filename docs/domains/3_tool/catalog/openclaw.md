# Tool Catalog: `openclaw`

[Back to tool catalog.](README.md)

## Catalog

These fields describe the OpenClaw tool's identity, backend, and support
model in Orbit.

| Field | Value |
| --- | --- |
| Slug | `openclaw` |
| Label | OpenClaw |
| Backend | Supervisor-managed runtime as `agent` |
| Support model | Installable and removable by Orbit |
| Category | `agent` |
| Required node role | `agent` |

## Capabilities

`openclaw` supports `tool:install`, `tool:remove`, lifecycle actions,
`tool:update`, snapshot and streamed `tool:logs`, `tool:credentials`,
tool-owned proxy route management, safe doctor fix, and safe doctor adopt.

## Credentials

`tool:credentials openclaw` returns the web UI access metadata Orbit has
generated for the managed OpenClaw service.

Example JSON shape:

```json
{
  "success": {
    "data": {
      "credentials": {
        "tool": "openclaw",
        "node": "agent-1",
        "fields": {
          "url": "https://openclaw.agent",
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
`tool:credentials`, so the agent node cannot read its own OpenClaw
credentials from its own CLI.

## Service Endpoint

`openclaw` exposes an internal HTTPS proxy route owned by the tool at
`https://openclaw.<agent-tld>` (default `https://openclaw.agent`). The
route is internal: it is reachable only over the Orbit/WireGuard network
and has no public ingress baseline.

## Orbit Notes

OpenClaw is a first-party autonomous agent tool. Orbit installs and runs
it as the shared unprivileged `agent` user, never as the privileged
`orbit` maintenance user. `tool:update openclaw` runs OpenClaw's native
update path through the Orbit-managed binary and then restarts the
Supervisor program that wraps it.

`tool:update openclaw` from the agent node itself requires
`tool:update:agent-tools` on the self grant. `tool:install openclaw`,
`tool:remove openclaw`, `tool:stop openclaw`, `tool:reconfigure openclaw`,
and updates to baseline tools (Caddy, Supervisor, WireGuard) are not part
of the default agent self grant; they require explicit permissions from a
gateway-admin.

Installing or starting OpenClaw while another agent tool is already
running on the same agent node emits the
`tool.multiple_agent_tools_running` warning. Orbit attributes activity
at the node level, and the warning surfaces that this attribution is
weaker when more than one agent tool runs on the same agent node.

Activity emitted while OpenClaw is working is attributed to the agent
node identity. Orbit does not claim per-tool sub-identities.

## Install Command

`tool:install openclaw` runs the official OpenClaw installer as the
shared `agent` user:

```bash
sudo -u agent -H bash -lc 'curl -fsSL https://openclaw.ai/install.sh | bash -s -- --no-onboard'
```

The `--no-onboard` flag skips the interactive setup wizard so Orbit can
converge configuration itself.

## Update Command

`tool:update openclaw` upgrades the OpenClaw binary through its native
npm path:

```bash
sudo -u agent -H bash -lc 'npm install -g openclaw@latest'
```

## Verify Commands

`doctor --family=tool` and `tool:show openclaw` use these verification
commands:

```bash
sudo -u agent -H bash -lc 'openclaw --version'
sudo -u agent -H bash -lc 'openclaw doctor'
sudo -u agent -H bash -lc 'openclaw gateway status'
```

## Doctor Relationship

`doctor --family=tool` verifies that the Supervisor program exists, that
the lifecycle state matches gateway expectations, and that managed
credential metadata is present. It also checks that the OpenClaw binary
version matches the gateway expected version when version tracking is
enabled, and that the tool's internal proxy route resolves to the active
agent role TLD.
