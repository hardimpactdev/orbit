# Operation Commands

Cross-family or local commands. Spec: [`docs/domains/11_operation/`](../../../docs/domains/11_operation/).

## `orbit doctor`

Diagnose state-family drift across nodes; optionally repair.

```bash
orbit doctor [--node=<name>] [--self] [--app=<name>] [--workspace=<name>]
             [--family=<key>]... [--fix] [--restore] [--adopt] [--json]
```

| Option | Default | Notes |
|---|---|---|
| `--node` | — | Target node name. |
| `--self` | — | Limit to the calling node identity. |
| `--app` | — | Scope to one app. |
| `--workspace` | — | Scope to one workspace. |
| `--family` | all | State family key (repeatable): `node`, `app`, `workspace`, `process`, `proxy`, `firewall_rule`, `tool`, `schedule`. |
| `--fix` | off | Enter resolution mode (required for `--restore` or `--adopt`). |
| `--restore` | off | Re-enact gateway intent on node reality. |
| `--adopt` | off | Pull observed node reality into gateway intent (DR / fleet adoption). |
| `--json` | off | JSON output. |

Examples:

```bash
orbit doctor --self                                 # local identity health
orbit doctor --node=beast                           # full drift report on beast
orbit doctor --node=beast --family=proxy --family=process
orbit doctor --node=beast --fix --restore           # repair drift toward intent
orbit doctor --node=beast --fix --adopt --family=app  # adopt only apps
orbit doctor --app=myapp                            # app-scoped report
```

**Important:** `--fix --adopt` for the `app` family treats filesystem presence as intent. A directory left over from a previous `app:remove` will be re-created. Clean the node first.

## `orbit update`

Update this Orbit checkout in place: `git pull` + `composer install --no-dev` + migrate. Local action only.

```bash
orbit update [--json]
```

## `orbit update:all`

Update the local checkout and every active registered node sequentially. Streams per-node progress.

```bash
orbit update:all [--json]
```

Runs from the gateway or a control node. Failures on one node don't abort the others.

## `orbit profile`

Profile one HTTP request against an Orbit-managed app. Reports DNS, connect, TLS, TTFB, and total timing. When the target app has Laravel Toolbar installed, enriches with route, memory, and query data.

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
orbit profile                                  # cwd app on an app node
orbit profile myapp.beast --uri=/login
orbit profile https://myapp.beast/dashboard --as-first-user
orbit profile --app=myapp --user=42 --json
```
