# `orbit doctor`

[Back to Operation commands.](../README.md)

Verify gateway intent against observed node reality for one node at a time,
and optionally repair or adopt supported drift.

`doctor` is Orbit's convergence command. Each run targets a single node. It
orchestrates state-family probes for families such as `node`, `app`,
`workspace`, `process`, `proxy`, `schedule`, `tool`, and `firewall_rule`.
The global command owns scope resolution, mode selection, authorization,
exit status, and output envelopes. Family doctor contracts own concrete
probe facts, issue codes, and safe restore/adopt maps.

The categories rendered for a run are derived from the target node's role:

- `control` target: `Node`; `DNS/TLD` only when custom TLD resolvers are
  configured on the target.
- `gateway` target: `Node`, `DNS`.
- `app` target: `Node`, `DNS/TLD`, `Apps`, `Workspaces`, `Processes`,
  `Proxy routes`, `Firewall`, `Tools`, `Scheduling`.

## Usage

```bash
orbit doctor [--app=<app>] [--workspace=<workspace>] [--node=<node>|--self] [--family=<family>] [--fix] [--restore|--adopt] [--json]
```

## Examples

```bash
orbit doctor
orbit doctor --family=node --self
orbit doctor --fix --family=app --app=docs --restore
orbit doctor --fix --family=workspace --app=docs --workspace=feature-api --adopt --json
```

## Arguments And Options

- `--family`: Limit the run to one product state family. Repeatable.
- `--node`: Limit the run to one gateway-known node.
- `--self`: Limit the run to the caller's gateway-known node identity.
- `--app`: Limit the run to one app and the family facts owned by that app.
- `--workspace`: Limit the run to one workspace and its owned facts.
- `--fix`: Enter resolution mode to interactively or bulk-restore drift.
- `--restore`: Non-interactively restore all supported findings from gateway intent to node reality. Requires `--fix`.
- `--adopt`: Non-interactively adopt all supported findings from node reality into gateway intent. Requires `--fix`.
- `--json`: Output JSON.

## What Happens

`doctor` resolves a single-node scope, authorizes that scope on the gateway,
runs the matching family probes for the target node's role, and reports the
final diagnostic. Without `--self` or `--node`, the target defaults to the
local caller's node.

In verify mode (no `--fix`), it compares only and does not mutate gateway intent or node reality.

With `--fix`, it enters resolution mode. Without `--restore` or `--adopt`, the command is interactive: it walks each finding and prompts for restore, adopt, skip, or details. With `--restore`, it bulk-applies gateway intent to all supported findings on nodes. With `--adopt`, it bulk-applies compatible observed node reality into gateway intent.

`--restore` and `--adopt` are mutually exclusive. `--restore` is explicit repair-mode consent for family-declared safe actions. `--adopt` is explicit adoption-mode consent and the only doctor mode that mutates gateway intent.

App-node callers may run authorized verify-mode doctor checks. App-node callers may not initiate `--fix` or `--adopt` unless a family doctor contract documents a narrow app-node write exception.

## Output

Human output renders a framed check-up panel for the single target node.
While the command is running, the panel shows each category in the target's
role-derived set and its current state. The final result uses the same
category rows, marks healthy categories as `OK`, renders issue tables inline
below the category that owns them, and ends with a summary line. Healthy
output must still say what was checked. In `--fix` modes (interactive,
restore, adopt), action results render inline below the owning category.
Verify-mode runs do not render action tables.

JSON output uses the shared command envelope. Healthy diagnostics are returned
under `success.data.doctor`. Drift or probe/action failures return the same
doctor diagnostic under `error.data.doctor`.

When no drift or probe errors remain, `doctor` exits successfully. When drift
remains, a probe fails, or scope cannot be resolved, `doctor` exits failed and
returns the diagnostic payload.

## Family Contracts

The global `doctor` command owns generic orchestration. Family doctor contracts
own concrete issue codes and action maps:

- [`doctor --family=node`](../../1_node/node-doctor.md)
- [`doctor --family=tool`](../../3_tool/tool-doctor.md)
- [`doctor --family=firewall_rule`](../../4_firewall/firewall-doctor.md)
- [`doctor --family=app`](../../5_app/app-doctor.md)
- [`doctor --family=workspace`](../../6_workspace/workspace-doctor.md)
- [`doctor --family=process`](../../7_process/process-doctor.md)
- [`doctor --family=proxy`](../../8_proxy/proxy-doctor.md)
- [`doctor --family=schedule`](../../9_schedule/schedule-doctor.md)

## Related Commands

- [`update`](../1_update/update.md) - update only the local Orbit checkout
- [`update:all`](../2_update-all/update-all.md) - update Orbit installations
  before running convergence checks

## Technical Contract

See [`doctor` technical contract](technical/1_doctor.md).
