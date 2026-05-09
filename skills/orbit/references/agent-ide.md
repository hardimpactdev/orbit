# Agent IDE Commands

Agent IDE adapters integrate Orbit with headless coding-agent IDEs (OpenCode, Polyscope) so workspace creation, crash notifications, and session messaging route through the right adapter. Spec: [`docs/commands/15_agent-ide/`](../../../docs/commands/15_agent-ide/).

Default resolution: app override → node default → none. Set defaults with [`node:agent-ide`](node.md#orbit-node-agent-ide-name-adapter) and [`app:agent-ide`](app.md#orbit-app-agent-ide-app-adapter). Per-tool credentials (e.g. OpenCode auth password) live in the `tool` family — see [`tool.md`](tool.md).

## `orbit agent-ide:message [message]`

Send a message to an active Agent IDE session for an app or workspace.

```bash
orbit agent-ide:message [<message>] [--app=<name>] [--workspace=<name>]
                        [--stdin] [--json]
```

| Option | Notes |
|---|---|
| `message` | Message text. Omit when using `--stdin`. |
| `--app` | App name or hostname. |
| `--workspace` | Workspace name or hostname. |
| `--stdin` | Read the message body from stdin (use for multi-line / piped input). |

Examples:

```bash
orbit agent-ide:message --app=myapp 'Tests pass on feature-x — please review.'

# Pipe a long message
git log --oneline -20 | orbit agent-ide:message --workspace=feature-x --app=myapp --stdin
```

If no active session is found for the resolved target, the command reports a typed error (no message is queued).

## Related

- Set node default: `orbit node:agent-ide [<name>] [opencode|polyscope|none]` ([`node.md`](node.md))
- Set app override: `orbit app:agent-ide [<app>] [opencode|polyscope|inherit|none]` ([`app.md`](app.md))
- Install / configure adapter servers: `orbit tool:install opencode-server`, `orbit tool:install polyscope-server` ([`tool.md`](tool.md))
