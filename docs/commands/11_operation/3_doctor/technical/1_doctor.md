# Technical Contract: `orbit doctor`

[Back to public `doctor` documentation.](../doctor.md)

**Owner:** `operation`.

**Effects:** `read`, `stream`; `write` when `--fix`, `--restore`, or `--adopt` is used.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The gateway identifies the calling WireGuard peer and authorizes the selected scope.
- When the calling peer is identified as an app-node peer, the gateway rejects `--fix`, `--restore`, or `--adopt` before side effects unless the selected family doctor contract documents a narrow app-node write-mode exception.

## Signature

```bash
orbit doctor [--app=<app>] [--workspace=<workspace>] [--node=<node>|--self] [--family=<family>] [--fix|--restore|--adopt] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `family` | `--family` | Never. | Never. | The full category set derived from the target node's role. | Repeatable product family key: `node`, `app`, `workspace`, `process`, `proxy`, `firewall_rule`, `tool`, or `schedule`. Must intersect with the target role's category set. |
| `node` | `--node` | Never. | `--self` is present. | The calling peer's node as identified by the gateway (equivalent to `--self`). | Gateway-known node name. Selects the single target node. |
| `self` | `--self` | Never. | `--node` is present. | `true` when neither `--self` nor `--node` is supplied. | Forwarded to the gateway; the gateway resolves it to the calling peer's identified node. |
| `app` | `--app` | Never. | A selected family contract forbids app scoping. | Apps selected by each family contract after authorization and node/workspace filters. | Gateway-known app slug. |
| `workspace` | `--workspace` | Never. | A selected family contract forbids workspace scoping. | Workspaces selected by each family contract after authorization and node/app filters. | Gateway-known workspace name, resolved inside app scope when applicable. |
| `fix` | `--fix` | Never. | `--restore` or `--adopt` is present. | `false`. | Selects interactive resolution mode. Every attempted action must be declared safe by its family doctor contract. |
| `restore` | `--restore` | Never. | `--fix` or `--adopt` is present. | `false`. | Selects bulk restore mode (gateway configuration to node reality). |
| `adopt` | `--adopt` | Never. | `--fix` or `--restore` is present. | `false`. | Selects bulk adopt mode (node reality into gateway configuration). |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Authorization By Caller Role

The CLI is a thin gateway client. It does not classify its own role; it gathers input, calls the gateway, and renders the result. The gateway identifies the calling WireGuard peer, applies authorization, and answers. Every run targets exactly one node. The peer role the gateway identifies governs *who is allowed to ask*; the *target* node's role governs the rendered category set.

| Peer role identified by gateway | Verify behavior | `--fix` / `--restore` / `--adopt` behavior |
| --- | --- | --- |
| `control` peer | The gateway authorizes the scope, dispatches family probes, and streams progress for the single-node target. | Allowed when the gateway authorizes the resolved scope and every attempted action is supported by the owning family. |
| `gateway` peer | Authority path. The gateway inspects gateway-local facts and uses its node execution to read the target node's reality. | Allowed when every attempted action is supported by the owning family. |
| `app` peer | Allowed for gateway-authorized verify-mode single-node scopes. Family contracts may define narrow local-default behavior for the app peer's working directory. | Denied by the gateway before side effects unless the owning family contract documents a narrow app-node write-mode exception. |

Peer-specific contract details are documented in companion files:
- [Control node](2_doctor_on-control-node.md)
- [Gateway node](3_doctor_on-gateway-node.md)
- [App node](4_doctor_on-app-node.md)

The generic peer-role matrix is fully defined here; family contracts may only narrow
app-node context behavior for the family they own.

## Target Role And Category Set

The rendered category set is derived from the target node's role:

| Target role | Categories |
| --- | --- |
| `control` | `Node` |
| `gateway` | `Node` |
| `app` | `Node`, `Apps`, `Workspaces`, `Processes`, `Proxy routes`, `Firewall`, `Tools`, `Scheduling` |

Families outside the target role's set are rejected before probes. A narrow `--family` filter intersects with the target role's set. The renderer never shows placeholder rows for families that are not in the target's set.

A future `DNS/TLD` row is reserved for control/app targets and a `DNS` row for gateway targets. They will render as a slice of the `node` family once a DNS diagnostic source exists. Until then the renderer keeps DNS/TLD facts inside the `Node` row and produces no separate row.

## Input Resolution

1. Select the output renderer.
2. Resolve mode: `verify` (no flag), `interactive` (`--fix`), `restore` (`--restore`), or `adopt` (`--adopt`). `--fix`, `--restore`, and `--adopt` are mutually exclusive.
3. Resolve the single-node target.
   - `--self` is forwarded to the gateway; the gateway resolves it to the calling peer's identified node.
   - `--node=<node>` is forwarded to the gateway and resolved against gateway configuration.
   - Omitted node scope defaults to `--self`.
   - `--self` combined with `--node` is rejected before forwarding.
4. Call the gateway to authorize the scope, derive the target-role category set, dispatch family probes, and (in resolution modes) attempt actions. Family filters intersect with the target-role category set; families outside the set are rejected by the gateway.
5. Render the gateway's diagnostic.

Input-mode-specific contracts are required for resolution modes:

- [Interactive input mode](5.1_doctor_input-mode_interactive.md)
- [Non-interactive input mode (bulk restore/adopt)](5.2_doctor_input-mode_non-interactive.md)

## Behavior Contract

### Family Dispatch Rules

- Run only product-family doctor probes. Backend-shaped implementation probes are folded into product families before they become public scope keys.
- Dispatch each selected family through its family doctor contract.
- Do not duplicate family issue codes, probe facts, restore actions, or adopt actions in the global command.
- Preserve family-owned diagnostic details for the selected output renderer.

### Mode Direction Rules

| Mode | Selected by | Direction | Meaning |
| --- | --- | --- | --- |
| `verify` | No mode flag. | Compare only. | Report where gateway configuration and observed node reality differ. |
| `interactive` | `--fix`. | Per-finding choice. | Present each finding and prompt for restore, adopt, skip, or details. |
| `restore` | `--restore`. | Gateway configuration to node reality. | Re-apply gateway configuration on nodes when the family declares the repair safe. |
| `adopt` | `--adopt`. | Node reality to gateway configuration. | Ingest compatible observed node reality into gateway configuration when the family declares adoption supported. |

`--fix` is the interactive driver, not a direction. The two directions are restore and adopt.

`--restore` is explicit repair-mode consent for family-declared safe actions. It is not permission for arbitrary destructive cleanup. Families must leave unsafe or ambiguous repair paths as reported issues with manual next steps.

`--adopt` is explicit adoption-mode consent for family-declared adoption actions. It is the only doctor mode that may intentionally mutate gateway configuration.

### Scope And Authorization Rules

- Resolve and validate all scope filters before probes or side effects.
- Resolve a single-node target before probes; multi-node scopes are not supported.
- Apply gateway-owned authorization to the resolved scope before probes or side effects.
- Fail before probes when mutually exclusive options are combined: any pair of `--fix`, `--restore`, `--adopt`, or `--self` with `--node`.
- Fail before probes when a requested family, node, app, or workspace scope cannot be resolved.
- Fail before probes when a requested family is outside the target node's role-derived category set.
- Fail before side effects when the selected family does not support the requested mode for the attempted issue actions.

### App-Node Write Boundaries

- App-node CLI availability is not generic doctor write permission.
- The gateway authorizes verify-mode scopes for app-node peers.
- The gateway denies `--fix`, `--restore`, or `--adopt` from app-node peers unless the selected family doctor contract documents a narrow app-node exception.
- App-node working-directory hints may help family-specific verify-mode scope resolution only when the family contract defines that behavior. They are not authorization to mutate gateway configuration or node reality.

### Result Classification Rules

- Return healthy success when no drift or probe errors remain after the selected mode completes.
- Return a drift failure when issues remain after the selected mode completes.
- In verify mode, do not change gateway configuration or node reality.
- In resolution modes (`interactive`, `restore`, `adopt`), record every attempted, completed, skipped, failed, or conflicted action.
- A family probe error prevents a healthy result unless the family contract defines a more specific recoverable behavior.

### Scope Boundaries

`doctor` must not:
- Invent a state family outside the stable family keys.
- Treat backend names such as Caddy, Supervisor, systemd, UFW, or package
  managers as public doctor families.
- Create new fleet membership, apps, workspaces, processes, schedules, tools,
  proxy routes, or firewall rules unless the selected family explicitly declares
  a compatible adoption action.
- Hide remaining drift after a failed restore/adopt action.

Successful update commands are not doctor convergence; `doctor` must run its own selected family probes before reporting a healthy result.

## Issue Kinds

Generic doctor issue kinds describe the relationship between gateway configuration and observed reality:

- `missing`: gateway configuration expects reality that is absent.
- `extra`: reality exists without matching active gateway configuration.
- `divergent`: configuration and reality both exist but disagree.
- `unverifiable`: doctor cannot determine reality because a prerequisite is unavailable.

Family doctor contracts define the family-specific cases that produce these kinds.

## Renderer Contracts

- [Human renderer](6.1_doctor_output-render_human.md)
- [JSON renderer](6.2_doctor_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Validation failed | Mutually exclusive flags or invalid filter values are supplied. | Failure before forwarding |
| Caller role not allowed | The gateway identifies the calling peer as an app-node peer and rejects `--fix`, `--restore`, or `--adopt` without a family-owned exception. | Failure before side effects |
| Gateway unavailable | The CLI cannot reach the gateway. | Failure before probes |
| Authorization failed | The gateway denies the calling peer authorization for the selected scope or mode. | Failure before probes |
| Scope not found | A requested family, node, app, or workspace scope cannot be resolved. | Failure before probes |
| Mode not supported | A selected family or issue does not support the requested `--restore` or `--adopt` action. | Failure with diagnostic payload when available |
| Probe failed | A family probe fails in a way that prevents a healthy result. | Failure with diagnostic payload |
| Drift detected | Drift remains after the selected mode completes. | Failure with diagnostic payload |

The shared exit status policy applies: `0` for healthy success, `1` for
Orbit-handled command failures, and `2` only for console-runtime invalid usage
before Orbit can apply this command contract.

## Doctor Relationship

The global `doctor` command owns orchestration, scoping, mode semantics, output envelopes, generic issue kinds, and generic failure behavior.

Family doctor contracts own:

- probe layers;
- concrete issue codes and issue details;
- restore action maps;
- adopt action maps;
- family test mapping.

Converted family doctor contracts:

- [`node-doctor.md`](../../../1_node/node-doctor.md)
- [`tool-doctor.md`](../../../3_tool/tool-doctor.md)
- [`firewall-doctor.md`](../../../4_firewall/firewall-doctor.md)
- [`app-doctor.md`](../../../5_app/app-doctor.md)
- [`workspace-doctor.md`](../../../6_workspace/workspace-doctor.md)
- [`process-doctor.md`](../../../7_process/process-doctor.md)
- [`proxy-doctor.md`](../../../8_proxy/proxy-doctor.md)
- [`schedule-doctor.md`](../../../9_schedule/schedule-doctor.md)

## Test Mapping

Required contract tests:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Operations/DoctorCommandContractTest.php` | Generic doctor input contract, scope resolution, mutually exclusive flags, mode selection, family-key validation, gateway authorization by peer role, app-node write-mode denial, exit-code semantics, JSON success/error envelope, and family dispatch boundaries without asserting family implementation internals. |
| `tests/Feature/Commands/Operations/DoctorRoleAwareCategoriesTest.php` | Single-node scope default to `--self`, role-aware category set per target role, `--family` rejection for families outside the target role's set, and per-node probe scoping for app/workspace/proxy families. |
| `tests/Feature/Http/Api/DoctorRunControllerTest.php` | Gateway API verify and fix endpoints, target node resolution from request body, caller authorization, and family dispatch over the API path. |
| `tests/Unit/Services/Doctor/DoctorReportRunnerTest.php` | Per-target probe scoping, restore-mode action suppression, action failure recording, and family dispatch through the in-process runner. |

Family-specific doctor test mapping lives in family doctor contracts, such as
[`node-doctor.md`](../../../1_node/node-doctor.md#test-mapping).

## Activity Logging

The local CLI command emits a best-effort activity entry for successful and
failed doctor runs. The gateway API endpoints emit activity entries for remote
doctor orchestration requests.

| Field | Value |
| --- | --- |
| Type | `doctor` for local CLI; `api:POST /doctor/run` for gateway API verify transport |
| Effect | `read` for verify-mode runs; `write` for `--fix`, `--restore`, and `--adopt` mode orchestration |
| Subject | `none` |
| Properties | `mode`, selected `families`, `healthy`, and `issues` when available. Action counts and status when in resolution modes. API transport context is added by middleware. |
| Description | `Doctor verification run` for local CLI verify mode; `Doctor resolution run` for local CLI resolution modes; derived for gateway API |
