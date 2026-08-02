# Tool Catalog: `openclaw`

[Back to tool catalog.](README.md)

## Catalog

These fields describe the OpenClaw tool's identity, backend, and support
model in Orbit.

| Field | Value |
| --- | --- |
| Slug | `openclaw` |
| Label | OpenClaw |
| Backend | Host-managed runtime as the unprivileged `agent` user |
| Support model | Installable and removable by Orbit |
| Category | `agent` |
| Supported operating systems | Linux |
| Required container provider | None; host-managed runtime |
| Runtime user | `agent` |
| Route requirement | The target node has its mandatory TLD |
| Isolation | Shared unprivileged `agent` user; no privileged `orbit` runtime |

## Capabilities

`openclaw` supports `tool:install`, `tool:remove`, `tool:update`,
`tool:reconfigure`, `tool:credentials`, proxy route metadata, safe doctor fix,
and safe doctor adopt. Lifecycle and logs for the managed web runtime belong to
the related `openclaw-gateway` process (`process:*`), not to tool lifecycle
verbs.

## Credentials

`tool:credentials openclaw` returns the web UI access metadata for the managed
OpenClaw gateway. Auth mode is token. The token is stored only at
`/home/agent/.openclaw/gateway.token` and is never written into
`openclaw.json`, process command argv, or logs.

Example JSON shape:

```json
{
  "success": {
    "data": {
      "credentials": {
        "tool": "openclaw",
        "node": "agent-1",
        "fields": {
          "url": "https://openclaw.agent-1",
          "auth_mode": "token",
          "token": "<generated-token>"
        }
      }
    },
    "meta": []
  }
}
```

Returning credentials requires the caller's grant to the node to
include `tool:credentials`. The default agent self-grant does not include
`tool:credentials`, so the node cannot read its own OpenClaw
credentials from its own CLI.

## Service Endpoint

`openclaw` exposes an internal HTTPS proxy route owned by the tool at
`https://openclaw.<node-tld>` (for example `https://openclaw.agent`). The
route is internal: it is reachable only over the Orbit/WireGuard network
and has no ingress baseline. The default reverse-proxy upstream is
`http://host.docker.internal:8081` so Hermes can keep port `8080` on the same
agent node.

## Orbit Notes

OpenClaw is a first-party autonomous agent tool. Orbit installs it for the
shared unprivileged `agent` user, never as the privileged `orbit` maintenance
user. `tool:update openclaw` runs OpenClaw's native update path through the
Orbit-managed binary. The agent runtime must be able to execute
`/home/agent/.local/bin/orbit --version --local` through the owner-user shim
without sudo or write access to owner Orbit config or install metadata.

The managed web gateway is process-owned: `tool:install` configures a related
`openclaw-gateway` `systemd` process that runs
`openclaw gateway run --port 8081 --bind lan` under
`OPENCLAW_SUPERVISOR_MODE=external` so OpenClaw's native service install is not
used (no double supervision). The process shell loads
`OPENCLAW_GATEWAY_TOKEN` from `/home/agent/.openclaw/gateway.token` immediately
before exec; the stored process command never contains the secret.

Install/update/reconfigure merge only managed gateway fields through
`openclaw config set` (`gateway.mode`, `gateway.port`, `gateway.bind`,
`gateway.auth.mode=token`, `gateway.controlUi.allowedOrigins`) and never
rewrite the full `~/.openclaw/openclaw.json`, preserving agents, channels,
models, and other settings. `gateway.auth.token` is unset from config when
present so the env-file token remains the sole secret source.

`tool:update openclaw` from the node itself requires `tool:update` on the
self-grant. `tool:install openclaw`, `tool:remove openclaw`,
`tool:reconfigure openclaw`, and `tool:credentials` are not part of the
default agent self-grant; they require explicit permissions from a
gateway-admin.

Installing OpenClaw while another agent tool is already
running on the same node emits the
`tool.multiple_agent_tools_running` warning. Orbit attributes activity
at the node level, and the warning surfaces that this attribution is
weaker when more than one agent tool runs on the same node.

Activity emitted while OpenClaw is working is attributed to the agent
node identity. Orbit does not claim per-tool sub-identities.

## Install Command

`tool:install openclaw` runs the official OpenClaw installer as the
shared `agent` user:

```bash
sudo -u agent -H bash -lc 'curl -fsSL https://openclaw.ai/install.sh | bash -s -- --no-onboard'
```

The `--no-onboard` flag skips the interactive setup wizard so Orbit can
converge configuration itself, then merges managed gateway fields and
configures the related process.

Before the tool row, proxy route, or installer is applied, Orbit verifies the
explicit Linux platform, mandatory node TLD, existence of the `agent` user, and
that the user is unprivileged. A failed check returns
`tool.constraint_unsatisfied` with stable constraint metadata.

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

`doctor --family=tool` verifies that the OpenClaw capability is installed and
that managed credential metadata is present. It also checks that the OpenClaw
binary version matches the gateway expected version when version tracking is
enabled, and that the tool's internal proxy route metadata resolves to the
target node's configured TLD (default upstream port `8081`). Runtime process
lifecycle drift belongs to the process family.
