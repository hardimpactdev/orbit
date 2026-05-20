# Technical Contract: `orbit doctor`

[Back to public `doctor` documentation.](../doctor.md)

**Owner:** `operation`.

**Effects:** `read`, `stream`; `write` when `--fix`, `--restore`, or `--adopt` is used. `--dry-run` keeps bulk restore/adopt in `read` effect because it returns the action plan without applying fixers.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The gateway identifies the calling WireGuard peer and authorizes the selected scope.
- For app-role peers, the gateway rejects `--fix`, `--restore`, or `--adopt` before side effects.
- The selected family doctor contract may document a narrow exception that permits resolution modes for app-role peers.

## Signature

```bash
orbit doctor [--app=<app>] [--workspace=<workspace>] [--node=<node>|--self] [--family=<family>] [--key=<key>] [--fix|--restore|--adopt] [--dry-run] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `family` | `--family` | Never. | Never. | The full category set derived from the target node's active roles. | Repeatable product family key: `node`, `app`, `database_connection`, `firewall_rule`, `process`, `proxy`, `schedule`, `tool`, or `workspace`. `security` is not a valid family; security-section findings live under the owning family key. Must intersect with the target's role-assignment category set. |
| `key` | `--key` | Never. | Never. | All issue keys from the selected family/families. | Single exact doctor issue-key filter. Filters reported drift after probes and before action planning. Does not imply or select a family. |
| `node` | `--node` | Never. | `--self` is present. | The calling peer's node as identified by the gateway (equivalent to `--self`). | Gateway-known node name. Selects the single target node. |
| `self` | `--self` | Never. | `--node` is present. | `true` when neither `--self` nor `--node` is supplied. | Forwarded to the gateway; the gateway resolves it to the calling peer's identified node. |
| `app` | `--app` | Never. | A selected family contract forbids app scoping. | Apps selected by each family contract after authorization and node/workspace filters. | Gateway-known app slug. |
| `workspace` | `--workspace` | Never. | A selected family contract forbids workspace scoping. | Workspaces selected by each family contract after authorization and node/app filters. | Gateway-known workspace name, resolved inside app scope when applicable. |
| `fix` | `--fix` | Never. | `--restore` or `--adopt` is present. | `false`. | Selects interactive resolution mode. Every attempted action must be declared safe by its family doctor contract. |
| `restore` | `--restore` | Never. | `--fix` or `--adopt` is present. | `false`. | Selects bulk restore mode (gateway configuration to node reality). |
| `adopt` | `--adopt` | Never. | `--fix` or `--restore` is present. | `false`. | Selects bulk adopt mode (node reality into gateway configuration). |
| `dry_run` | `--dry-run` | Never. | No `--restore` or `--adopt` flag is present. | `false`. | Returns planned bulk actions without invoking family fixers or adopters. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Target Roles and Category Set

The rendered category set is derived from the target node's active role
assignments. The legacy node role field remains a compatibility shadow for
identity and output, but workload-family doctor eligibility comes from
`node_roles`.

| Target role assignment state | Categories |
| --- | --- |
| client with no active role | `Node` |
| active `gateway` role | `Node`, `Scheduling` |
| active `database` role only | `Node`, `Tools` |
| active `agent` role | `Node`, `Tools` |
| active `app-development` role | `Node`, `Apps`, `Workspaces`, `Processes`, `Proxy routes`, `Firewall`, `Tools`, `Scheduling`, `Databases` |
| active `app-production` role | `Node`, `Apps`, `Processes`, `Proxy routes`, `Firewall`, `Tools`, `Scheduling`, `Databases` |

Families outside the target's role-assignment set are rejected before probes. A narrow `--family` filter intersects with that set. The renderer never shows placeholder rows for families that are not in the target's set.

A future `DNS/TLD` row is reserved for operator/app targets and a `DNS` row for gateway targets. They will render as a slice of the `node` family once a DNS diagnostic source exists. Until then the renderer keeps DNS/TLD facts inside the `Node` row and produces no separate row.

## Input Resolution

1. Select the output renderer.
2. Resolve mode: `verify` (no flag), `interactive` (`--fix`), `restore` (`--restore`), or `adopt` (`--adopt`). `--fix`, `--restore`, and `--adopt` are mutually exclusive. `--dry-run` is valid only with `--restore` or `--adopt`.
3. Resolve the single-node target.
   - `--self` is forwarded to the gateway; the gateway resolves it to the calling peer's identified node.
   - `--node=<node>` is forwarded to the gateway and resolved against gateway configuration.
   - Omitted node scope defaults to `--self`.
   - `--self` combined with `--node` is rejected before forwarding.
4. Call the gateway to authorize the scope, derive the target-role category set, and dispatch family probes.
   - In resolution modes, the gateway also attempts actions.
   - Family filters intersect with the target-role category set.
   - Families outside the set are rejected by the gateway.
   - `--key` filters the resulting issue list to the exact key before action planning.
5. Render the gateway's diagnostic.

Input-mode-specific contracts are required for resolution modes:

- [Interactive input mode](5.1_doctor_input-mode_interactive.md)
- [Non-interactive input mode (bulk restore/adopt)](5.2_doctor_input-mode_non-interactive.md)

## Behavior Contract

### Family Dispatch Rules

- Run only product-family doctor probes. Backend-shaped implementation probes are folded into product families before they become public scope keys.
- Security is a cross-family issue-code section, not a product family. `node.security.*`, `app.security.*`, `workspace.security.*`, and future firewall-owned `firewall_rule.security.*` findings dispatch through their owning families.
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

### Scope, Authorization, and App-Node Write Boundaries

Cross-peer scope-resolution rules and the app-role write boundary list are
owned by
[`7_doctor_scope-and-authorization.md`](7_doctor_scope-and-authorization.md).
Peer-specific authorization remains in the on-node companion contracts:
[`2_doctor_on-client.md`](2_doctor_on-client.md),
[`3_doctor_on-gateway-node.md`](3_doctor_on-gateway-node.md), and
[`4_doctor_on-app-role.md`](4_doctor_on-app-role.md).

### Result Classification Rules

- After the selected mode completes with no remaining drift or probe errors, return healthy success.
- After the mode completes with remaining issues, return a drift failure.
- In verify mode, do not change gateway configuration or node reality.
- In resolution modes (`interactive`, `restore`, `adopt`), record every attempted, completed, skipped, failed, or conflicted action.
- In dry-run mode, record planned actions with `status=planned`, leave issues unresolved, and return command success because no mutation was attempted.
- A family probe error prevents a healthy result.
- Exception: a family contract may define more specific recoverable behavior for that family's probe errors.

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
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Scope not found | A requested family, node, app, or workspace scope cannot be resolved. | Failure before probes |
| Mode not supported | A selected family or issue does not support the requested `--restore` or `--adopt` action. | Failure with diagnostic payload when available |
| Probe failed | A family probe fails in a way that prevents a healthy result. | Failure with diagnostic payload |
| Drift detected | Drift remains after the selected mode completes. | Failure with diagnostic payload |
| Dry-run mode invalid | `--dry-run` is supplied without `--restore` or `--adopt`. | Failure before probes |

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
| `tests/Feature/Commands/Operations/DoctorCommandContractTest.php` | Generic input contract, `--key`, `--dry-run`, scope resolution, mutually exclusive flags, mode selection, family-key validation, gateway authorization by peer role, app-role write-mode denial, exit-code semantics, JSON envelope, and family dispatch boundaries. |
| `tests/Feature/Commands/Operations/DoctorRoleAwareCategoriesTest.php` | Single-node scope default to `--self`, role-aware category set per target active roles, app-development/app-production workspace split, `--family` rejection for families outside the target's role-assignment set, and per-node probe scoping for app/workspace/proxy families. |
| `tests/Feature/Http/Api/DoctorRunControllerTest.php` | Gateway API verify and fix endpoints, target node resolution from request body, caller authorization, and family dispatch over the API path. |
| `tests/Unit/Services/Doctor/DoctorReportRunnerTest.php` | Per-target probe scoping, restore-mode action suppression, action failure recording, and family dispatch through the in-process runner. |

Test mapping for each family lives in its family doctor contract, such as
[`node-doctor.md`](../../../1_node/node-doctor.md#test-mapping).

Peer-specific behavior and test mapping live in:

- [`2_doctor_on-client.md`](2_doctor_on-client.md)
- [`3_doctor_on-gateway-node.md`](3_doctor_on-gateway-node.md)
- [`4_doctor_on-app-role.md`](4_doctor_on-app-role.md)

## Activity Logging

The local CLI command emits a best-effort activity entry for successful and
failed doctor runs. The gateway API endpoints emit activity entries for remote
doctor orchestration requests.

| Field | Value |
| --- | --- |
| Type | `doctor` for local CLI; `api:POST /doctor/run` for gateway API verify transport |
| Effect | `read` for verify-mode and `--dry-run` runs; `write` for `--fix`, `--restore`, and `--adopt` mode orchestration that actually applies changes |
| Subject | `none` |
| Properties | `mode`, selected `families`, optional `key`, `dry_run`, `healthy`, and `issues` when available. Action counts and status when in resolution modes. API transport context is added by middleware. |
| Description | `Doctor verification run` for local CLI verify mode; `Doctor resolution run` for local CLI resolution modes; derived for gateway API |
