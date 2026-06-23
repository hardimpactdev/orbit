# Operation Commands

Cross-family or local commands. Spec:
[`apps/docs/content/domains/11_operation/`](../../../apps/docs/content/domains/11_operation/).

## `orbit doctor`

Diagnose state-family drift across nodes; optionally repair.

```bash
orbit doctor [--node=<name>] [--self] [--all] [--app=<name>] [--workspace=<name>]
             [--family=<key>]... [--fix] [--restore] [--adopt]
             [--json|--stream-json]
```

| Option | Default | Notes |
|---|---|---|
| `--node` | local `node:default`, then caller | Target one node by name. `--node=all` is invalid; use `--all`. |
| `--self` |  -  | Limit to the calling node identity. |
| `--all` | off | Verify every eligible active role-bearing fleet node. Verify-only and mutually exclusive with node/app/workspace scope. |
| `--app` |  -  | Scope to one app. |
| `--workspace` |  -  | Scope to one workspace. |
| `--family` | all | State family key (repeatable): `node`, `app`, `workspace`, `process`, `proxy`, `firewall_rule`, `tool`, `schedule`, `database_connection`. |
| `--fix` | off | Enter interactive resolution mode. |
| `--restore` | off | Re-enact gateway intent on node reality. |
| `--adopt` | off | Pull observed node reality into gateway intent (DR / fleet adoption). |
| `--json` | off | One final machine-readable terminal frame. |
| `--stream-json` | off | Newline-delimited gateway progress frames for non-interactive agents. Mutually exclusive with `--json`; rejected with `--fix`. |

Examples:

```bash
orbit doctor --self                                 # local identity health
orbit doctor --node=beast                           # full drift report on beast
orbit doctor --all                                  # fleet verification
orbit doctor --node=beast --family=proxy --family=process
orbit doctor --node=beast --restore                 # repair drift toward intent
orbit doctor --node=beast --adopt --family=app      # adopt only apps
orbit doctor --app=myapp                            # app-scoped report
orbit doctor --node=beast --stream-json             # agent progress stream
orbit doctor --all --stream-json                    # fleet agent progress stream
```

Plain `orbit doctor` sends the configured local default node when one is set
with `orbit node:default`; otherwise it omits `node` so the gateway resolves
the caller identity. For long-running LLM-agent checks, prefer
`orbit doctor --stream-json` so the agent receives incremental NDJSON progress
frames. Broader `--stream-json` rollout to other long-running commands is a
separate follow-up.

The process family is available for every node with at least one active role
assignment. Role-less client/operator identities remain node-family only.

**Important:** `--adopt` for the `app` family treats filesystem presence as intent. A directory left over from a previous `app:remove` will be re-created. Clean the node first.

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
orbit update:all [--json]
```

Runs from the gateway or an authorized client. Failures on one fan-out node do
not abort the others.

## `orbit profile`

Profile one HTTP request against an Orbit-managed app. The gateway resolves and
authorizes the target, then the CLI performs the timed HTTP request from the
caller machine. Reports DNS, connect, TLS, TTFB, download, and total timing.
When the target app has Laravel Toolbar installed, enriches with route, memory,
and query data.

```bash
orbit profile [<target>] [--app=<name>] [--node=<name>] [--uri=/]
              [--as-first-user | --user=<id>] [--json]
```

| Argument / option | Notes |
|---|---|
| `target` | Domain (`myapp.beast`), app hostname, full URL (`https://myapp.test/login`), or absolute app path. Path becomes `--uri` when a URL is passed. |
| `--app` | Resolve target by app name/hostname (alternative to positional). |
| `--node` | Constrain app resolution to a node when names overlap. |
| `--uri` | Request URI [default `/`]. |
| `--as-first-user` | Authenticate as the first user (Toolbar required). |
| `--user=<id>` | Authenticate as the user with that primary key. |
| `--json` | JSON output. |

Examples:

```bash
orbit profile                                  # cwd app on an app-role node
orbit profile myapp.beast --uri=/login
orbit profile https://myapp.beast/dashboard --as-first-user
orbit profile --app=myapp --user=42 --json
```

The CLI does not follow redirects; a 3xx response is a completed profile
result. The caller-side HTTP request uses the active gateway timeout as its
total timeout, while connection setup keeps a shorter fast-fail timeout. TLS
remains verified; when the active gateway has a local CA PEM, Orbit adds that
CA to the profile request trust material. Caller-side DNS, connection, TLS,
timeout, or HTTP transport failures return `profile_request_failed` and never
fall back to a gateway-origin request.
