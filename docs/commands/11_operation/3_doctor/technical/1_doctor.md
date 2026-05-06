# Technical Contract: `orbit doctor`

[Back to public `doctor` documentation.](../doctor.md)

**Owner:** `operation`.

**Effects:** `read`, `stream`; `write` when `--fix` or `--adopt` is used.

**Prerequisites:**
- The local caller role can be resolved according to the foundation
  [local node role setting](../../../../BLUEPRINT.md#local-node-role-setting)
  contract and the node-family
  [Local Caller Role](../../../1_node/README.md#local-caller-role) contract.
- The caller can reach the Orbit gateway when the selected scope requires
  gateway intent, gateway authorization, or node reality inspection.
- The current node identity is authorized to inspect the selected scope.
- App-node callers using `--fix` or `--adopt` are rejected before side effects
  unless the selected family doctor contract documents a narrow app-node
  write-mode exception.

## Signature

```bash
orbit doctor [--app=<app>] [--workspace=<workspace>] [--node=<node>|--self] [--family=<family>] [--fix|--adopt] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `family` | `--family` | Never. | Never. | All doctor-supported product families. | Repeatable product family key: `node`, `app`, `workspace`, `process`, `proxy`, `firewall_rule`, `tool`, or `schedule`. |
| `node` | `--node` | Never. | `--self` is present. | Nodes selected by each family contract after authorization and app/workspace filters. | Gateway-known node name. |
| `self` | `--self` | Never. | `--node` is present. | `false`. | Resolves to the caller's gateway-known node identity. |
| `app` | `--app` | Never. | A selected family contract forbids app scoping. | Apps selected by each family contract after authorization and node/workspace filters. | Gateway-known app slug. |
| `workspace` | `--workspace` | Never. | A selected family contract forbids workspace scoping. | Workspaces selected by each family contract after authorization and node/app filters. | Gateway-known workspace name, resolved inside app scope when applicable. |
| `fix` | `--fix` | Never. | `--adopt` is present. | `false`. | Selects fix mode. Every attempted action must be declared safe by its family doctor contract. |
| `adopt` | `--adopt` | Never. | `--fix` is present. | `false`. | Selects adopt mode. Every attempted action must be deliberately declared adoptable by its family doctor contract. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Caller Role Behavior

`doctor` resolves the caller role before probes, actions, or remote transport.

| Caller role | Verify behavior | `--fix` / `--adopt` behavior |
| --- | --- | --- |
| `control` | Calls the gateway for scope authorization, family probe orchestration, and streamed progress. | Allowed when the gateway authorizes the resolved scope and every attempted action is supported by the owning family. |
| `gateway` | Authority path. May inspect gateway-local facts and use gateway-owned node execution for selected node reality. | Allowed when every attempted action is supported by the owning family. |
| `app` | Allowed for authorized verify-mode scopes. Local app/workspace context may help resolve defaults only when a family contract defines that behavior. | Denied before side effects unless the owning family contract documents a narrow app-node write-mode exception. |
| `unknown` | Invalid local context. Fail before probes or side effects. | Invalid local context. Fail before probes or side effects. |

No role-specific companion files are required. The generic role matrix is fully
defined here; family contracts may only narrow app-node context behavior for the
family they own.

## Input Resolution

1. Resolve caller role before probes or side effects.
2. Select the output renderer.
3. Resolve mode: `verify`, `fix`, or `adopt`.
4. Resolve family filters. Omitted `--family` means all doctor-supported product
   families.
5. Resolve node scope.
   - `--self` resolves to the caller's gateway-known node identity.
   - `--node=<node>` resolves to that gateway-known node.
   - Omitted node scope means each selected family contract chooses authorized
     nodes after applying the resolved app and workspace filters.
6. Resolve app and workspace scope when supplied.
7. Apply gateway authorization for the resolved scope and mode.
8. Dispatch selected family probes and optional fix/adopt actions.

No input-mode-specific contracts are required. The command has no required
fields and does not prompt for missing input. Invalid combinations fail before
probes or side effects.

## Behavior Contract

### Family Dispatch Rules

- Run only product-family doctor probes. Backend-shaped implementation probes
  are folded into product families before they become public scope keys.
- Dispatch each selected family through its family doctor contract.
- Do not duplicate family issue codes, probe facts, fix actions, or adopt
  actions in the global command.
- Preserve family-owned diagnostic details for the selected output renderer.

### Mode Direction Rules

| Mode | Selected by | Direction | Meaning |
| --- | --- | --- | --- |
| `verify` | No `--fix` or `--adopt`. | Compare only. | Report where gateway intent and observed node reality differ. |
| `fix` | `--fix`. | Gateway intent to node reality. | Re-apply gateway intent on the node when the family declares the repair safe. |
| `adopt` | `--adopt`. | Node reality to gateway intent. | Ingest compatible observed node reality into gateway intent when the family declares adoption supported. |

`--fix` is explicit repair-mode consent for family-declared safe actions. It is
not permission for arbitrary destructive cleanup. Families must leave unsafe or
ambiguous repair paths as reported issues with manual next steps.

`--adopt` is explicit adoption-mode consent for family-declared adoption actions.
It is the only doctor mode that may intentionally mutate gateway intent.

### Scope And Authorization Rules

- Resolve and validate all scope filters before probes or side effects.
- Apply gateway-owned authorization to the resolved scope before probes or
  side effects.
- Fail before probes when `--fix` and `--adopt` are combined.
- Fail before probes when `--self` and `--node` are combined.
- Fail before probes when a requested family, node, app, or workspace scope
  cannot be resolved.
- Fail before side effects when the selected family does not support the
  requested mode for the attempted issue actions.

### App-Node Write Boundaries

- App-node CLI availability is not generic doctor write permission.
- App-node callers may run authorized verify-mode scopes.
- App-node callers may not initiate `--fix` or `--adopt` unless the selected
  family doctor contract documents a narrow app-node exception.
- App-node local context may help family-specific verify-mode scope resolution
  only when the family contract defines that behavior. It is not authorization
  to mutate gateway intent or node reality.

### Result Classification Rules

- Return healthy success when no drift or probe errors remain after the selected
  mode completes.
- Return a drift failure when issues remain after the selected mode completes.
- In verify mode, do not change gateway intent or node reality.
- In fix mode, record every attempted, completed, skipped, failed, or conflicted
  action.
- In adopt mode, record every attempted, completed, skipped, failed, or
  conflicted action.
- A family probe error prevents a healthy result unless the family contract
  defines a more specific recoverable behavior.

### Scope Boundaries

`doctor` must not:
- Invent a state family outside the stable family keys.
- Treat backend names such as Caddy, Supervisor, systemd, UFW, or package
  managers as public doctor families.
- Create new fleet membership, apps, workspaces, processes, schedules, tools,
  proxy routes, or firewall rules unless the selected family explicitly declares
  a compatible adoption action.
- Hide remaining drift after a failed fix/adopt action.

Successful update commands are not doctor convergence; `doctor` must run its
own selected family probes before reporting a healthy result.

## Issue Kinds

Generic doctor issue kinds describe the relationship between gateway intent and
observed reality:

- `missing`: gateway intent expects reality that is absent.
- `extra`: reality exists without matching active gateway intent.
- `divergent`: intent and reality both exist but disagree.
- `unverifiable`: doctor cannot determine reality because a prerequisite is
  unavailable.

Family doctor contracts define the family-specific cases that produce these
kinds.

## Renderer Contracts

- [Human renderer](6.1_doctor_output-render_human.md)
- [JSON renderer](6.2_doctor_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Validation failed | Mutually exclusive flags or invalid filter values are supplied. | Failure before probes |
| Caller role not allowed | An app-node caller requests generic `--fix` or `--adopt` without a family-owned exception. | Failure before side effects |
| Local context invalid | The local caller role is unreadable or unsupported. | Failure before probes |
| Gateway unavailable | The selected scope requires the gateway and the caller cannot reach it. | Failure before probes |
| Authorization failed | The current node identity is not authorized for the selected scope or mode. | Failure before probes |
| Scope not found | A requested family, node, app, or workspace scope cannot be resolved. | Failure before probes |
| Mode not supported | A selected family or issue does not support the requested `--fix` or `--adopt` action. | Failure with diagnostic payload when available |
| Probe failed | A family probe fails in a way that prevents a healthy result. | Failure with diagnostic payload |
| Drift detected | Drift remains after the selected mode completes. | Failure with diagnostic payload |

The shared exit status policy applies: `0` for healthy success, `1` for
Orbit-handled command failures, and `2` only for console-runtime invalid usage
before Orbit can apply this command contract.

## Doctor Relationship

The global `doctor` command owns orchestration, scoping, mode semantics, output
envelopes, generic issue kinds, and generic failure behavior.

Family doctor contracts own:

- probe layers;
- concrete issue codes and issue details;
- fix action maps;
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

Required split contract tests:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Operations/DoctorCommandContractTest.php` | Generic doctor input contract, scope resolution, mutually exclusive flags, mode selection, family-key validation, caller-role behavior, app-node write-mode denial, exit-code semantics, and family dispatch boundaries without asserting family implementation internals. |
| `tests/Feature/Commands/Operations/DoctorJsonRendererTest.php` | JSON renderer selection, success payload, drift failure under `error.data`, error codes, summary shape, issue/action shape, and `--json` forcing non-interactive mode. |
| `tests/Feature/Commands/Operations/DoctorHumanRendererTest.php` | Progress tree shape, grouped human output, verify/fix/adopt headings, issue summaries, action summaries, unhealthy result text, and manual next-step prose. |
| `tests/E2E/Read/DoctorTest.php` | Real read-only doctor verification from a control node against an active fleet. |

Family-specific doctor test mapping lives in family doctor contracts, such as
[`node-doctor.md`](../../../1_node/node-doctor.md#test-mapping).

## Activity Logging

The local CLI command emits a best-effort activity entry for successful and
failed doctor verification runs. The gateway API endpoint emits an activity
entry for remote doctor orchestration requests.

| Field | Value |
| --- | --- |
| Type | `doctor` for local CLI; `api:POST /doctor/run` for gateway API transport |
| Effect | `read` for verify-mode runs; `write` for `--fix` and `--adopt` mode orchestration |
| Subject | `none` |
| Properties | `mode`, selected `families`, `healthy`, and `issues` when available. API transport context is added by middleware. |
| Description | `Doctor verification run` for local CLI; derived for gateway API |
