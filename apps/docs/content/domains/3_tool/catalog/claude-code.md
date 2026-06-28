# Tool Catalog: `claude-code`

[Back to tool catalog.](README.md)

## Catalog

These fields describe the Claude Code CLI tool's identity, backend, and support model in Orbit.

| Field | Value |
| --- | --- |
| Slug | `claude-code` |
| Label | Claude Code |
| Backend | Anthropic native installer (`https://claude.ai/install.sh`) |
| Support model | Installable by Orbit on authorized active non-gateway Linux nodes |
| Required node role | None |
| Category | `runtime` |

## Capabilities

`claude-code` supports `tool:install` only.

`tool:install claude-code` installs Claude Code through Anthropic's native
installer for the target node's stored default user (`nodes.user`), falling
back to `orbit` when the node user is missing. Each install runs in that user's
home directory under `~/.local/bin/claude` with settings and state isolated to
that user's `~/.claude` and `~/.claude.json`.

Repeat `--user=<name>` on `tool:install claude-code` to install additional
user-scoped copies on the same node. `--user` is not accepted for other tools.
Example:

```bash
orbit tool:install claude-code --node=app-1 --user=agent
```

`--user=agent` names an additional existing Linux OS user to install for. It is
not a node-role eligibility gate and does not create the OS account. This
pattern lets Hermes or another runtime execute `claude` as the `agent` OS user
without sharing auth or config with the node's default user.

`--tool-version` forwards installer targets accepted by the native script:
`latest`, `stable`, or a specific version string. `latest` and `stable` are
floating installer channels; live probes validate that Claude Code is installed
and report the concrete binary version returned by `claude --version`.

## Credentials

`claude-code` does not support `tool:credentials`. Authentication and session
state belong to Anthropic's per-user Claude Code install.

## Orbit Notes

These notes define Orbit-specific boundaries for Claude Code installs.

- `claude-code` is a runtime tool with `requiredNodeRole() === null`, not an
  `agent` category tool. Eligibility is explicit target authorization, active
  non-gateway node selection, and supported operating system metadata.
- It does not create proxy routes, managed credentials, or agent-server
  assumptions.
- Orbit does not create a shared `/usr/local/bin/claude` launcher. Each managed
  user runs the binary from that user's `~/.local/bin/claude`.
- Doctor probes the persisted default managed user for the `claude-code` tool
  row. The binary path is the user's `~/.local/bin/claude`, and the version
  check runs as that same OS user. Additional `install_users` are install
  targets only; the default user remains the convergence probe target.
- Doctor treats `latest` and `stable` as floating Claude Code installer
  channels instead of comparing those words literally to the concrete binary
  version.

## Doctor Relationship

`doctor --family=tool` can report a missing `claude-code` capability on nodes
where the tool row is expected. Convergence uses Anthropic's native installer
once for each managed user, matching `tool:install`.

Live retained-topology proof for the native installer is intentionally deferred
until an operator chooses a node with outbound access to `claude.ai`. The
focused Orbit tests verify catalog registration, row config, shell escaping, and
the exact generated install/probe commands without mutating a live node home.
