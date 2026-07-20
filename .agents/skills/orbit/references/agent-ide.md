# Agent IDE Commands

Agent IDE adapters integrate Orbit with headless coding-agent IDEs (OpenCode, Polyscope) so workspace creation, crash notifications, and session messaging route through the right adapter. Spec: [`apps/docs/content/domains/15_agent-ide/`](../../../apps/docs/content/domains/15_agent-ide/).

Default resolution: instance override -> node default -> none. Set defaults
with [`node:agent-ide`](node.md#orbit-node-agent-ide-name-adapter) and
[`instance:agent-ide`](app.md#configure-one-instance). Per-tool credentials
(e.g. OpenCode auth password) live in the `tool` family; see
[`tool.md`](tool.md).

## `orbit agent-ide:message [message]`

Send a message to an active Agent IDE session for an instance or workspace.

```bash
orbit agent-ide:message [<message>] [--instance=<name>] [--workspace=<name>]
                        [--stdin] [--json]
```

| Option | Notes |
|---|---|
| `message` | Message text. Omit when using `--stdin`. |
| `--instance` | Dotted `project.instance` selector or instance hostname. |
| `--workspace` | Workspace name or hostname. |
| `--stdin` | Read the message body from stdin (use for multi-line / piped input). |

Examples:

```bash
orbit agent-ide:message --instance=myapp.development 'Tests pass on feature-x  -  please review.'

# Pipe a long message
git log --oneline -20 | orbit agent-ide:message --workspace=feature-x --instance=myapp.development --stdin
```

If no active session is found for the resolved target, the command reports a typed error (no message is queued).

## Related

- Set node default: `orbit node:agent-ide [<name>] [opencode|polyscope|none]` ([`node.md`](node.md))
- Set instance override: `orbit instance:agent-ide [<project.instance>] [opencode|polyscope|inherit|none]` ([`app.md`](app.md))
- Install / configure adapter servers: `orbit tool:install opencode-server`, `orbit tool:install polyscope-server` ([`tool.md`](tool.md))
