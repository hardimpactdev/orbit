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
`tool:reconfigure`, `tool:credentials`, proxy route metadata, safe doctor fix,
and safe doctor adopt. Lifecycle and logs for the managed web dashboard belong
to the related `orbit-hermes-dashboard` process (`process:*`), not to tool
lifecycle verbs.

## Credentials

`tool:credentials hermes` returns the web UI access metadata for the managed
Hermes dashboard. Auth mode is basic (username/password) per Hermes' June 2026
gated-mode requirement for non-loopback binds. The password is stored only at
`/home/agent/.hermes/dashboard.password` and the session-signing secret only at
`/home/agent/.hermes/dashboard.secret`. Neither secret is written into process
command argv, logs, or gateway intent rows.

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
          "auth_mode": "basic",
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
without sudo or write access to owner Orbit config or install metadata.

The managed web dashboard is process-owned: `tool:install` configures a related
`orbit-hermes-dashboard` `systemd` process that runs
`hermes dashboard --host 0.0.0.0 --port 8080 --no-open`. The process name is
Orbit-prefixed so it does not collide with Hermes' native
`hermes-dashboard.service`. Binding `0.0.0.0` engages Hermes' auth gate and
accepts reverse-proxy Host headers such as `hermes.agent`. The process shell
loads `HERMES_DASHBOARD_BASIC_AUTH_USERNAME`,
`HERMES_DASHBOARD_BASIC_AUTH_PASSWORD`, and
`HERMES_DASHBOARD_BASIC_AUTH_SECRET` from agent-home credential files
immediately before exec; the stored process command never contains those
secrets. `HERMES_DASHBOARD_PUBLIC_URL` is set from
`/home/agent/.hermes/dashboard.public_url` when present.

Install/update/reconfigure generate durable password and secret files when they
are absent or empty after whitespace/newline normalization (zero-length and
whitespace-only files are treated as missing and securely regenerated with
mode `0600`) and write the public URL for the resolved tool route hostname
(`hermes.<node-tld>`). Process startup uses the same non-empty-after-trim
rule for password and secret files. They stop unmanaged listeners only when the Orbit
unit's `ActiveState` is not `active`, `activating`, or `reloading` (read-only
`systemctl show`), so first install frees port `8080` without racing a managed
unit mid-start/reload. They do not run interactive
`hermes setup`.

`tool:reconfigure hermes` reconverges managed dashboard credential files and
the public URL, then re-runs the Hermes credentials script. It replaces
gateway-stored credential fields with the parsed JSON object, including the
password read from `/home/agent/.hermes/dashboard.password`. That keeps
`tool:credentials hermes` truthful after reconfigure instead of retaining a
stale install-time placeholder such as `<generated-password>`.

Reconfigure success output never includes those credential values. When the
credentials script cannot run, exits unsuccessfully, or returns
malformed/non-object JSON, reconfigure fails and does not claim success. When
the related `orbit-hermes-dashboard` process row is present, reconfigure
restarts it after a successful credentials refresh so public-URL and auth env
loaded at unit start take effect.

`tool:remove hermes` removes that process (name + `tool=hermes`) before
deleting Hermes home and binary paths so the managed unit cannot restart-loop
after the tool is gone.

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

After install, Orbit converges dashboard credentials and configures the related
`orbit-hermes-dashboard` process by default.

## Update Command

`tool:update hermes` runs Hermes's native self-update path:

```bash
sudo -u agent -H bash -lc 'hermes update'
```

Then reconverges managed dashboard credential files and public URL.

## Verify Commands

`doctor --family=tool` and `tool:show hermes` use this verification
command:

```bash
sudo -u agent -H bash -lc 'hermes --version'
```

Runtime process lifecycle for the web dashboard belongs to the process family
(`orbit-hermes-dashboard`).

## Doctor Relationship

`doctor --family=tool` verifies that the Hermes capability is installed and
that managed credential metadata is present. It also checks that the Hermes
binary version matches the gateway expected version when version tracking is
enabled, and that the tool's internal proxy route metadata resolves to the
target node's configured TLD. Runtime process lifecycle drift belongs to the process
family.
