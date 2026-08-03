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
verbs. `tool:install` configures that related process by default.
`tool:reconfigure` restarts it when present so managed gateway fields take
effect. `tool:remove` removes the related `openclaw-gateway` process (name +
`tool=openclaw`) before tool home/binary teardown.

## Credentials

`tool:credentials openclaw` returns the web UI access metadata for the managed
OpenClaw gateway. Auth mode is token. The token is stored only at
`/home/agent/.openclaw/gateway.token` and is never written into
`openclaw.json`, process command argv, or logs.

Install, update, and reconfigure use one non-empty secret rule for that token
file. When the path is missing, zero-byte, or whitespace-only after stripping
spaces and newlines, Orbit regenerates it securely (`openssl rand -hex 32`) and
writes mode `0600`. The token stays out of argv, logs, and full-config rewrites.
Process startup for the related `openclaw-gateway` unit applies the same
non-empty-after-trim rule: it loads the token into `OPENCLAW_GATEWAY_TOKEN` only
when non-empty, and refuses to start when the material is missing or blank.

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
`http://host.docker.internal:18789` (OpenClaw's documented default gateway
port) so Hermes can keep port `8080` on the same agent node without colliding
with Orbit Caddy's private backend on `8081`.

## Orbit Notes

OpenClaw is a first-party autonomous agent tool. Orbit installs it for the
shared unprivileged `agent` user, never as the privileged `orbit` maintenance
user. `tool:update openclaw` runs OpenClaw's native update path through the
Orbit-managed binary. The agent runtime must be able to execute
`/home/agent/.local/bin/orbit --version --local` through the owner-user shim
without sudo or write access to owner Orbit config or install metadata.

The managed web gateway is process-owned: `tool:install` configures a related
`openclaw-gateway` `systemd` process that runs the local-prefix binary
`/home/agent/.openclaw/bin/openclaw gateway run --port 18789 --bind lan` under
`OPENCLAW_SUPERVISOR_MODE=external` so OpenClaw's native service install is not
used (no double supervision). Ambient PATH and any pre-existing global npm
install are unused by Orbit process/configure/probe paths. The process shell
loads `OPENCLAW_GATEWAY_TOKEN` from `/home/agent/.openclaw/gateway.token`
immediately before exec only when the file content is non-empty after trim; the
stored process command never contains the secret, and blank or missing token
material fails the unit closed.

Install/update/reconfigure merge only managed gateway fields through the
local-prefix binary's `config set` (`gateway.mode`, `gateway.port`,
`gateway.bind`, `gateway.auth.mode=token`, `gateway.controlUi.allowedOrigins`)
and never rewrite the full `~/.openclaw/openclaw.json`, preserving agents,
channels, models, and other settings. `gateway.auth.token` is unset from config
when present so the file-backed token remains the sole secret source. By
default, those lifecycle scripts regenerate blank `gateway.token` material
before converge completes so zero-byte or whitespace-only files do not remain.

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

`tool:install openclaw` installs through the official **local-prefix** installer
as the shared `agent` user. Orbit does **not** use `install.sh`, which provisions
system Node and requires administrator privileges; the unprivileged `agent`
account cannot sudo, so that path fails on managed agent nodes. `install-cli.sh`
keeps OpenClaw and its Node runtime under `$HOME/.openclaw` without a system-wide
Node dependency:

```bash
sudo -u agent -H bash -lc 'curl -fsSL --proto "=https" --tlsv1.2 https://openclaw.ai/install-cli.sh | bash -s -- --no-onboard --prefix "$HOME/.openclaw"'
```

The `--no-onboard` flag skips the interactive setup wizard so Orbit can
converge configuration itself, then merges managed gateway fields and
configures the related process. HTTPS transport uses `--proto '=https'
--tlsv1.2` against the official OpenClaw URL (no weaker than prior curl
install).

Before the tool row, proxy route, or installer is applied, Orbit verifies the
explicit Linux platform, mandatory node TLD, existence of the `agent` user, and
that the user is unprivileged. A failed check returns
`tool.constraint_unsatisfied` with stable constraint metadata.

## Update Command

`tool:update openclaw` re-runs the same local-prefix installer into
`$HOME/.openclaw` (idempotent), then re-applies managed gateway fields. It does
not use `npm install -g`.

```bash
sudo -u agent -H bash -lc 'curl -fsSL --proto "=https" --tlsv1.2 https://openclaw.ai/install-cli.sh | bash -s -- --no-onboard --prefix "$HOME/.openclaw"'
```

## Verify Commands

`doctor --family=tool` and `tool:show openclaw` use the local-prefix binary:

```bash
sudo -u agent -H bash -lc '/home/agent/.openclaw/bin/openclaw --version'
sudo -u agent -H bash -lc '/home/agent/.openclaw/bin/openclaw doctor'
sudo -u agent -H bash -lc '/home/agent/.openclaw/bin/openclaw gateway status'
```

## Doctor Relationship

`doctor --family=tool` verifies that the OpenClaw capability is installed and
that managed credential metadata is present. It also checks that the OpenClaw
binary version matches the gateway expected version when version tracking is
enabled, and that the tool's internal proxy route metadata resolves to the
target node's configured TLD (default upstream port `18789`). Runtime process
lifecycle drift belongs to the process family.
