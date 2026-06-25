# `orbit doctor`

[Back to Operation commands.](../README.md)

Verify gateway configuration against observed node reality for one resolved
target node, or explicitly inspect the fleet with `--all`.

`doctor` is Orbit's convergence command. Plain `orbit doctor`, `--self`, and
`--node=<name>` each resolve exactly one target node. Fleet verification is
available only through explicit `--all`. It orchestrates state-family probes for
families such as `node`, `app`, `database_connection`, `firewall_rule`,
`process`, `proxy`, `schedule`, `tool`, and `workspace`. The global command
owns scope resolution, mode selection, authorization, result handling, and
output selection. Family doctor contracts own concrete probe facts, issue
codes, and safe restore/adopt maps.

The categories rendered for a run are derived from the target node's active
role assignments. The compatibility node role field is only a shadow value
and does not by itself grant workload-family probes:

- client target (no active role): `Node`.
- `gateway` target: `Node`, `Processes`, `Scheduling`.
- `database` target: `Node`, `Tools`, `Processes`.
- `agent` target: `Node`, `Tools`, `Processes`.
- `router` target: `Node`, `Proxy routes`, `Processes`.
- `app-dev` target: `Node`, `Apps`, `Workspaces`, `Processes`, `Proxy routes`,
  `Firewall`, `Tools`, `Scheduling`, `Databases`.
- `app-prod` target: `Node`, `Apps`, `Processes`, `Proxy routes`,
  `Firewall`, `Tools`, `Scheduling`, `Databases`.
- `ingress` target: `Node`, `Proxy routes`, `Firewall`, `Tools`, `Processes`.
- `websocket` target: `Node`, `Tools`, `Processes`.
- `s3` target: `Node`, `Tools`, `Proxy routes`, `Processes`.
- `metrics` target: `Node`, `Tools`, `Processes`, `Proxy routes`.
- `vpn` or `analytics` target (no other role-specific category): `Node`, `Processes`.

Every node with at least one active role assignment includes `Processes`; a
client or operator identity with no active role renders only `Node`.

`Scheduling` on a `gateway` target surfaces the scheduler daemon's health
(presence, heartbeat, stuck locks) plus per-target dispatch reachability.
`Scheduling` on an `app-dev` or `app-prod` target surfaces the
run health of schedules targeting that node.

A separate `DNS/TLD` row (operator/app targets) and `DNS` row (gateway target)
is planned as a slice of the `node` family. It will render once a DNS
diagnostic source exists; until then, findings related to DNS stay inside the
`Node` row.

## Usage

```bash
orbit doctor [--app=<app>] [--workspace=<workspace>] [--node=<node>|--self|--all] [--family=<family>] [--key=<key>] [--fix|--restore|--adopt] [--dry-run] [--json|--stream-json]
```

## Examples

```bash
orbit doctor
orbit doctor --family=node --self
orbit doctor --fix --family=app --app=docs
orbit doctor --restore --family=app --app=docs
orbit doctor --adopt --family=workspace --app=docs --workspace=feature-api --json
orbit doctor --restore --family=node --key=node.security.host_key.app-1 --dry-run --json
orbit doctor --node=app-1 --stream-json
orbit doctor --all --stream-json
```

## Arguments and options

**Scope filters:**

- `--family`: Limit the run to one product state family. Repeatable.
  `security` is not a family. Security issue keys are reported inside their
  owning families, such as `node.security.*`, `app.security.*`, and
  `workspace.security.*`.
- `--key`: Limit reported drift to a single exact issue-key filter inside the selected family/families.
- `--node`: Limit the run to one gateway-known node.
- `--self`: Limit the run to the caller's gateway-known node identity.
- `--all`: Verify every eligible active role-bearing fleet node. This is the
  only fleet mode and is mutually exclusive with `--node`, `--self`, `--app`,
  and `--workspace`. Use `--all`; `--node=all` is rejected as
  `validation_failed` before probes.
- `--app`: Limit the run to one app and the family facts owned by that app.
- `--workspace`: Limit the run to one workspace and its owned facts.

**Resolution modes:**

- `--fix`: Enter interactive resolution mode. Walks each finding and prompts for restore, adopt, skip, or details. Mutually exclusive with `--restore` and `--adopt`.
- `--restore`: Non-interactively restore all supported findings (gateway configuration to node reality). Mutually exclusive with `--fix` and `--adopt`.
- `--adopt`: Non-interactively adopt all supported findings (node reality into gateway configuration). Mutually exclusive with `--fix` and `--restore`.
- `--dry-run`: Valid with `--restore` or `--adopt`; returns planned actions without applying fixers or adopters.
- `--json`: Output one final machine-readable JSON terminal frame.
- `--stream-json`: Stream gateway progress as newline-delimited JSON frames.
  Mutually exclusive with `--json` and rejected with `--fix`.

## What Happens

`doctor` resolves scope before probes. Plain `orbit doctor` first uses the
locally configured default node from `orbit node:default` when one is selected.
If no default node is selected and no explicit scope is supplied, the CLI sends
`self=true` so the caller's identified node is selected. `--node=<name>` targets
exactly one named node. `--self` targets the caller identity. `--all` is the
only fleet mode and runs verify-mode fleet inspection. Resolution modes
(`--fix`, `--restore`, `--adopt`) require a single target node.

The command supports four modes. Verify mode (no flag) compares only and does not mutate gateway configuration or node reality. Interactive mode (`--fix`) walks each finding and prompts for restore, adopt, skip, or details. Restore mode (`--restore`) bulk-applies gateway configuration to node reality for all supported findings. Adopt mode (`--adopt`) records compatible observed node reality into gateway configuration in bulk.

`--fix` is the interactive driver, not a direction. The two directions are restore (gateway to node) and adopt (node to gateway). `--restore` and `--adopt` are mutually exclusive. `--restore` is explicit repair-mode consent for family-declared safe actions. `--adopt` is explicit adoption-mode consent and the only doctor mode that mutates gateway configuration.

The gateway authorizes each run against the resolved target node. Verify mode
requires `doctor:verify`; resolution actions require `doctor:restore` or
`doctor:adopt` for the selected direction.

## Output

Human output renders one bordered doctor check-up panel. Single-node runs show
each category in the target's active-role set. `--all` uses the same panel with
node-keyed rows. While checks run, the panel updates in place and omits the
summary section; the terminal panel adds summary prose. Issue details render
inline under the owning row. In resolution modes (`--fix`, `--restore`,
`--adopt`), action results render inline below the owning category. Verify-mode
runs do not render action tables. `--json` and `--stream-json` keep complete
machine payloads; human truncation does not apply to machine output.

Use `--json` for one final machine-readable diagnostic result. Use
`--stream-json` for long-running non-interactive agents that need incremental
progress frames as the gateway reports them. The stream scope matches human
mode, including default-node, caller fallback, named-node, and `--all` fleet
scope. Broader `--stream-json` rollout to other long-running commands is a
separate follow-up, not part of this doctor contract.

When no drift or probe errors remain, `doctor` exits successfully. When drift
remains, a probe fails, or scope cannot be resolved, `doctor` exits failed and
returns the diagnostic payload.

## Family Contracts

The global `doctor` command owns generic orchestration. Family doctor contracts
own concrete issue codes and action maps:

**Infrastructure families:**

- [`doctor --family=node`](../../1_node/node-doctor.md)
- [`doctor --family=tool`](../../3_tool/tool-doctor.md)
- [`doctor --family=firewall_rule`](../../4_firewall/firewall-doctor.md)

**App families:**

- [`doctor --family=app`](../../5_app/app-doctor.md)
- [`doctor --family=workspace`](../../6_workspace/workspace-doctor.md)
- [`doctor --family=process`](../../7_process/process-doctor.md)
- [`doctor --family=proxy`](../../8_proxy/proxy-doctor.md)
- [`doctor --family=schedule`](../../9_schedule/schedule-doctor.md)
- [`doctor --family=database_connection`](../../18_database/database-doctor.md)

## Related Commands

Use these commands together with `orbit doctor` for update and convergence workflows.

- [`update`](../1_update/update.md) - update only the local Orbit checkout
- [`update:all`](../2_update-all/update-all.md) - update Orbit installations
  before running convergence checks

## Technical Contract

See [`doctor` technical contract](technical/1_doctor.md).
