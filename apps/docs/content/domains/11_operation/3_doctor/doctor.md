# `orbit doctor`

[Back to Operation commands.](../README.md)

Verify gateway configuration against observed node reality for one resolved
target node, or explicitly inspect the fleet with `--all`.

`doctor` is Orbit's convergence command. Plain `orbit doctor`, `--self`, and
`--node=<name>` each resolve exactly one target node. Fleet verification is
available only through explicit `--all`. It orchestrates state-family probes for
families such as `node`, `instance`, `database_connection`, `firewall_rule`,
`process`, `proxy`, `schedule`, `tool`, and `workspace`. The global command
owns scope resolution, mode selection, authorization, result handling, and
output selection. Family doctor contracts own concrete probe facts, issue
codes, and safe restore/adopt maps.

The categories rendered for a run start with the target node's active role
assignments, then add families derived from gateway-owned facts and platform
eligibility. A displayed role label is derived output and grants nothing. The
role-derived base set is:

- client target (no active role): `Node`.
- `gateway` target: `Node`, `Processes`.
- `database` target: `Node`, `Tools`, `Processes`.
- `agent` target: `Node`, `Tools`, `Proxy routes`, `Processes`.
- `router` target: `Node`, `Proxy routes`, `Processes`.
- `app-dev` target: `Node`, `Instances`, `Workspaces`, `Processes`, `Proxy routes`,
  `Tools`, `Databases`.
- `app-prod` target: `Node`, `Instances`, `Processes`, `Proxy routes`, `Tools`,
  `Databases`.
- `ingress` target: `Node`, `Proxy routes`, `Tools`, `Processes`.
- `websocket` target: `Node`, `Tools`, `Processes`.
- `s3` target: `Node`, `Tools`, `Proxy routes`, `Processes`.
- `metrics` target: `Node`, `Tools`, `Processes`, `Proxy routes`.
- `vpn` or `analytics` target (no other role-specific category): `Node`, `Processes`.

For an `app-prod` target, `Processes`, `Proxy routes`, and `Databases` diagnose
only production instance and node facts. Workspace rows and workspace-derived facts
are removed before those probes run. The gateway rejects an explicit workspace
family or scope before dispatch, and an `app-prod` caller cannot use a mixed or
workspace-adjacent doctor request to inspect an `app-dev` workspace.

Owned-fact/platform overlays then add:

- `Tools` when the node owns tool rows or a role baseline owns a tool
  capability, including the VPN DNS capability on an active gateway+VPN node;
- `Firewall` for every active Ubuntu target eligible to own Orbit-protected
  firewall rules, including exporter rules on workload nodes; macOS is
  excluded;
- `Scheduling` for the gateway and for every node targeted by at least one
  gateway schedule definition, independent of workload role; and
- other families when the selected node owns their valid gateway facts, rather
  than because of a caller role.

Every node with at least one active role assignment includes `Processes`; a
roleless client/operator includes only `Node` unless an owned-fact overlay
admits another family.

On macOS workload nodes, the `Firewall` category is skipped: macOS nodes are
not eligible firewall targets and macOS firewall mutation is unsupported in
v1. Tool checks on macOS report the Docker capability through the node's
reachable Docker-compatible container provider and recommend Colima when no
provider is reachable.

`Scheduling` on the gateway surfaces the singleton scheduler daemon's health
(presence, heartbeat, and stuck locks) plus gateway-target run health. On every
other scheduled target it surfaces target dispatch reachability and recent-run
health only; no workload node is expected to run a scheduler singleton.

DNS findings stay in their owning family rows: `Node` reports the node record
projection, `Proxy routes` reports the private `.orbit` and exact-backend
projection, and `Tools` reports DNS base configuration and runtime capability.
Doctor does not create a separate DNS row or DNS state family.

## Usage

```bash
orbit doctor [--instance=<project.instance>] [--workspace=<workspace>] [--node=<node>|--self|--all] [--family=<family>] [--key=<key>] [--fix|--restore|--adopt] [--dry-run] [--json|--stream-json]
```

## Examples

```bash
orbit doctor
orbit doctor --family=node --self
orbit doctor --fix --family=instance --instance=docs.development
orbit doctor --restore --family=instance --instance=docs.development
orbit doctor --adopt --family=workspace --instance=docs --workspace=feature-api --json
orbit doctor --restore --family=node --key=node.security.public_ssh_deny --dry-run --json
orbit doctor --node=app-1 --stream-json
orbit doctor --all --stream-json
```

## Arguments and options

**Scope filters:**

- `--family`: Limit the run to one product state family. Repeatable.
  `security` is not a family. Security issue keys are reported inside their
  owning families, such as `node.security.*`, `instance.security.*`, and
  `workspace.security.*`.
- `--key`: Limit reported drift to a single exact issue-key filter inside the selected family/families.
- `--node`: Limit the run to one gateway-known node.
- `--self`: Limit the run to the caller's gateway-known node identity.
- `--all`: Verify every eligible active role-bearing fleet node. This is the
  only fleet mode and is mutually exclusive with `--node`, `--self`, `--instance`,
  and `--workspace`. Use `--all`; `--node=all` is rejected as
  `validation_failed` before probes.
- `--instance`: Limit the run to one concrete `<project.instance>` and its owned family facts.
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

Node-scoped `--restore` is convergence-complete for supported genuine drift.
It applies family-declared restore actions, re-probes the same selected node,
families, key, instance, and workspace fence, and continues multi-pass repair
while new restorable genuine drift appears. It stops when the scope is clean,
when the restorable set makes no progress (repeated findings), or when the
bounded pass cap is reached. Structured `convergence` / `summary` metadata
records passes and stop reason. Action receipts never hide remaining findings;
the final fresh observation is authoritative.

Every issue carries an explicit catalog `disposition`
(`genuine_drift`, `blocked_inspection`, `invalid_intent`, `runtime_incident`)
and, for genuine drift, a declared `restore_action`. Unknown issue codes fail
closed. Generic `kind` remains for compatibility. Probe errors are
`blocked_inspection` / Unverifiable findings and prevent healthy. Dry-run and
verify do not apply mutations or re-probe for resolution verification.
`--all` stays verify-only. `--adopt` remains explicit disaster-recovery and is
not widened. Invalid gateway intent is never repaired by guessing.

On supported macOS Agent-eligible nodes, a restore or adopt action that needs
protected local work may trigger the OS privilege prompt through the
node-local Orbit Agent. V1 has no separate Orbit approval UI or pending/approve
flow; the prompt is the operating system prompt, and agent-push results remain
in gateway operation/activity history.

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

**Project runtime families:**

- [`doctor --family=instance`](../../5_project/instance-doctor.md)
- [`doctor --family=workspace`](../../6_workspace/workspace-doctor.md)
- [`doctor --family=process`](../../7_process/process-doctor.md)
- [`doctor --family=proxy`](../../8_proxy/proxy-doctor.md)
- [`doctor --family=schedule`](../../9_schedule/schedule-doctor.md)
- [`doctor --family=database_connection`](../../18_database/database-doctor.md)

## Related Commands

Use these commands together with `orbit doctor` for update and convergence workflows.

- [`update`](../1_update/update.md) - update only the local Orbit installation
- [`update:all`](../2_update-all/update-all.md) - update Orbit installations
  before running convergence checks

## Technical Contract

See [`doctor` technical contract](technical/1_doctor.md).
