# `orbit doctor`

[Back to Operation commands.](../README.md)

Verify gateway configuration against observed node reality for one node at a time, and optionally repair or adopt supported drift.

`doctor` is Orbit's convergence command. Each run targets a single node. It orchestrates state-family probes for families such as `node`, `app`, `workspace`, `process`, `proxy`, `schedule`, `tool`, and `firewall_rule`. The global command owns scope resolution, mode selection, authorization, exit status, and output envelopes. Family doctor contracts own concrete probe facts, issue codes, and safe restore/adopt maps.

The categories rendered for a run are derived from the target node's role:

- `control` target: `Node`.
- `gateway` target: `Node`.
- `app` target: `Node`, `Apps`, `Workspaces`, `Processes`, `Proxy routes`,
  `Firewall`, `Tools`, `Scheduling`.

A separate `DNS/TLD` row (control/app targets) and `DNS` row (gateway target)
is planned as a slice of the `node` family. It will render once a DNS
diagnostic source exists; until then DNS-related findings stay inside the
`Node` row.

## Usage

```bash
orbit doctor [--app=<app>] [--workspace=<workspace>] [--node=<node>|--self] [--family=<family>] [--fix|--restore|--adopt] [--json]
```

## Examples

```bash
orbit doctor
orbit doctor --family=node --self
orbit doctor --fix --family=app --app=docs
orbit doctor --restore --family=app --app=docs
orbit doctor --adopt --family=workspace --app=docs --workspace=feature-api --json
```

## Arguments And Options

- `--family`: Limit the run to one product state family. Repeatable.
- `--node`: Limit the run to one gateway-known node.
- `--self`: Limit the run to the caller's gateway-known node identity.
- `--app`: Limit the run to one app and the family facts owned by that app.
- `--workspace`: Limit the run to one workspace and its owned facts.
- `--fix`: Enter interactive resolution mode. Walks each finding and prompts for restore, adopt, skip, or details. Mutually exclusive with `--restore` and `--adopt`.
- `--restore`: Non-interactively restore all supported findings (gateway configuration to node reality). Mutually exclusive with `--fix` and `--adopt`.
- `--adopt`: Non-interactively adopt all supported findings (node reality into gateway configuration). Mutually exclusive with `--fix` and `--restore`.
- `--json`: Output JSON.

## What Happens

`doctor` resolves a single-node scope, asks the gateway to authorize that scope and run the matching family probes for the target node's role, and reports the final diagnostic. The CLI is a thin gateway client; the gateway identifies the calling WireGuard peer and applies authorization. Without `--self` or `--node`, the target defaults to the calling peer's node as the gateway identifies it.

The command supports four modes. Verify mode (no flag) compares only and does not mutate gateway configuration or node reality. Interactive mode (`--fix`) walks each finding and prompts for restore, adopt, skip, or details. Restore mode (`--restore`) bulk-applies gateway configuration to node reality for all supported findings. Adopt mode (`--adopt`) bulk-records compatible observed node reality into gateway configuration.

`--fix` is the interactive driver, not a direction. The two directions are restore (gateway to node) and adopt (node to gateway). `--restore` and `--adopt` are mutually exclusive. `--restore` is explicit repair-mode consent for family-declared safe actions. `--adopt` is explicit adoption-mode consent and the only doctor mode that mutates gateway configuration.

The gateway authorizes verify-mode runs for app-node peers. It denies `--fix`, `--restore`, or `--adopt` from app-node peers unless a family doctor contract documents a narrow app-node write exception.

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
