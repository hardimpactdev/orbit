# Operation Commands

Cross-family or local commands. Spec:
[`apps/docs/content/domains/11_operation/`](../../../../apps/docs/content/domains/11_operation/).

## `orbit doctor`

Diagnose state-family drift across nodes; optionally repair.

```bash
orbit doctor [--node=<name>] [--self] [--all] [--instance=<name>] [--workspace=<name>]
             [--family=<key>]... [--key=<key>]... [--fix] [--restore] [--adopt]
             [--dry-run] [--json|--stream-json]
```

| Option | Default | Notes |
|---|---|---|
| `--node` | local `node:default`, then caller | Target one node by name. `--node=all` is invalid; use `--all`. |
| `--self` |  -  | Limit to the calling node identity. |
| `--all` | off | Verify every eligible active role-bearing fleet node. Verify-only and mutually exclusive with node/instance/workspace scope. |
| `--instance` |  -  | Scope to one `app.instance` placement. |
| `--workspace` |  -  | Scope to one workspace. |
| `--family` | all | State family key (repeatable): `node`, `instance`, `workspace`, `process`, `proxy`, `firewall_rule`, `tool`, `schedule`, `database_connection`. |
| `--key` |  -  | Exact doctor issue key (repeatable). Filters reported drift before action planning; does not select a family. |
| `--fix` | off | Enter interactive resolution mode. |
| `--restore` | off | Re-enact gateway intent on node reality. |
| `--adopt` | off | Pull observed node reality into gateway intent (DR / fleet adoption). |
| `--dry-run` | off | With `--restore` or `--adopt`, preview planned repair/adoption actions without applying them. |
| `--json` | off | One final machine-readable terminal frame. |
| `--stream-json` | off | Newline-delimited gateway progress frames for non-interactive agents. Mutually exclusive with `--json`; rejected with `--fix`. |

Examples:

```bash
orbit doctor --self                                 # local identity health
orbit doctor --node=beast                           # full drift report on beast
orbit doctor --all                                  # fleet verification
orbit doctor --node=beast --family=proxy --family=process
orbit doctor --node=beast --restore                 # repair drift toward intent
orbit doctor --node=beast --restore --dry-run       # preview restore actions only
orbit doctor --node=beast --adopt --family=instance # adopt only instances
orbit doctor --family=instance --key=instance.security.runtime_container_isolation
orbit doctor --instance=myapp.development           # instance-scoped report
orbit doctor --node=beast --stream-json             # agent progress stream
orbit doctor --all --stream-json                    # fleet agent progress stream
```

Plain `orbit doctor` sends the configured local default node when one is set
with `orbit node:default`; otherwise it sends `self=true` so the gateway uses
the caller identity. For long-running LLM-agent checks, prefer
`orbit doctor --stream-json` so the agent receives incremental NDJSON progress
frames. Other command families list `--stream-json` in their own references
when that renderer is available.

The process family is available for every node with at least one active role
assignment. Role-less client/operator identities remain node-family only.

**Important:** `--adopt` for the `instance` family treats filesystem presence
as intent. A directory left over from a previous `app:remove` will be
re-created. Clean the node first.

## `orbit update`

Update the caller-local Orbit CLI binary in place. The command checks for the
latest release, skips when the local CLI is current, and never updates past the
active gateway version; when the gateway is behind it exits successfully with
`Skipped: please update your gateway first`.

```bash
orbit update [--json]
```

## `orbit update:all`

Update managed Orbit nodes through the gateway. The command checks the latest
release and fleet versions, skips all-current fleets, updates the gateway first
as the version ceiling, then updates the caller-local CLI and workload nodes as
fan-out targets. Streams per-node progress.

```bash
orbit update:all [--json|--stream-json]
```

Runs from the gateway or an authorized client. Failures on one fan-out node do
not abort the others.
Use `--json` for the final result envelope, or `--stream-json` for
newline-delimited gateway progress frames followed by one terminal frame. The
two flags are mutually exclusive. A caller-local fan-out failure after the
gateway phase is reported as a terminal `event=error` frame under
`--stream-json` and as a `local_update_failed` error envelope under `--json`.

## `orbit version`

Show caller-local Orbit version, release timestamp, install timestamp, and
best-effort newer-release metadata. This command never contacts the gateway.

```bash
orbit version [--local] [--json]
orbit --version [--local] [--json]
```

`--local` skips public release lookups. On zsh login shells it also ensures the
supported Orbit `noglob` shell integration. Without matching install metadata,
Orbit falls back to the invoked launcher modification time.

## `orbit manifest:update <url>`

Select a custom HTTP or HTTPS release manifest on the gateway for future
`update:all` runs. Gateway-admin authority is required.

```bash
orbit manifest:update <url> [--json]
```

The command stores the URL but does not fetch, validate, or install its
contents. The next `update:all` snapshots and validates the selected manifest
before side effects.

## `orbit manifest:remove`

Clear the custom gateway manifest so future `update:all` runs use the
configured default release source. Gateway-admin authority is required.

```bash
orbit manifest:remove [--json]
```

This does not start an update or delete candidate or GitHub release assets.

## `orbit profile`

Profile one HTTP request directly from the caller machine. This local-only,
read-only command never calls the gateway, resolves gateway state, checks
grants, dispatches remote work, or records gateway activity. It reports DNS,
connect, TLS, TTFB, download, and total timing.
When the target app has Laravel Toolbar installed, enriches with route, memory,
and query data.

```bash
orbit profile [<url>] [--as-first-user | --user=<id>] [--json]
```

| Argument / option | Notes |
|---|---|
| `url` | Absolute HTTP or HTTPS URL, including any path and query to profile. |
| `--as-first-user` | Authenticate as the first user (Toolbar required). |
| `--user=<id>` | Authenticate as the user with that primary key. |
| `--json` | JSON output. |

Examples:

```bash
orbit profile                                  # nearest ancestor .env APP_URL, otherwise prompt
orbit profile https://myapp.beast/login
orbit profile https://myapp.beast/dashboard --as-first-user
orbit profile https://myapp.beast/profile --user=42 --json
```

The CLI does not follow redirects; a 3xx response is a completed profile
result. Without `[url]`, Orbit walks from the current directory to the nearest
ancestor `.env` with a valid absolute `APP_URL`; if none exists, interactive
mode prompts locally and non-interactive mode fails. TLS remains verified using
caller-local trust. DNS, connection, TLS, timeout, or HTTP transport failures
return `profile_request_failed`; there is no gateway-origin fallback.
