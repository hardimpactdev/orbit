# Technical Contract: `orbit node:update [name]`

[Back to public `node:update` documentation.](../node-update.md)

**Owner:** `node`.

**Effects:** `write`.

**Prerequisites:**
- The local caller role can be resolved according to the foundation
  [local node role setting](../../../../BLUEPRINT.md#local-node-role-setting)
  contract and the node-family
  [Local Caller Role](../../README.md#local-caller-role) contract.
- `node:update` is invoked from a control or gateway caller. App-node callers are
  rejected before prompts, forwarding, or side effects.
- Gateway callers can read and write gateway-owned node intent.
- Control callers have configured gateway access as defined in
  [`2_node-update_on-control-node.md`](2_node-update_on-control-node.md).

**Post-input path eligibility:**
- The target node name resolves to an existing active node record.
- At least one supported field flag is provided in non-interactive input mode.
- The caller is authorized to mutate the target node through gateway-owned node
  access policy.

## Signature

```bash
orbit node:update [name] [--host=<host>] [--environment=<development|production>] [--public-ipv4=<address>] [--public-ipv6=<address>] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | `[name]` | Caller role = `control` or `gateway`. | Never. | None. | Must match an existing active node record. |
| `host` | `--host` | Optional. | Target node role = `control`. | None. | SSH/bootstrap endpoint, never the canonical node address. Updating this does not change the gateway endpoint used in WireGuard peer configs. |
| `environment` | `--environment` | Optional. | Target node role ≠ `app`. | None. | One of `development`, `production`. |
| `public_ipv4` | `--public-ipv4` | Optional. | Target node role = `control`. | None. | Operator-supplied public IPv4 metadata. |
| `public_ipv6` | `--public-ipv6` | Optional. | Target node role = `control`. | None. | Operator-supplied public IPv6 metadata. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode according to the shared invocation model in [`docs/commands/README.md`](../../../README.md#invocation-model). |

Each field flag may be supplied at most once per invocation. Supplying the same
field flag more than once fails with `validation_failed` and `meta.field` set
to the duplicated field name. Last-wins is not an accepted behavior.

### Field eligibility by target node role

The target node role restricts which fields are valid to update. Bootstrap
fields shared with `node:new` keep the same role constraints, and public address
metadata is valid only for gateway and app nodes. Concretely:

| Field | Valid target roles | Forbidden when |
| --- | --- | --- |
| `--host` | `gateway`, `app` | Target node role = `control`. |
| `--environment` | `app` | Target node role ≠ `app`. |
| `--public-ipv4` | `gateway`, `app` | Target node role = `control`. |
| `--public-ipv6` | `gateway`, `app` | Target node role = `control`. |

Control nodes are CLI callers reached through WireGuard. They have no SSH
bootstrap endpoint and no public ingress, so `--host`, `--public-ipv4`, and
`--public-ipv6` are all forbidden on control targets. Public IPv4 and IPv6
metadata is supported on `gateway` and `app` target nodes; the gateway
endpoint used in WireGuard peer configs lives on a separate field and is not
updated by `--public-ipv4` or `--public-ipv6`.

`node:update --host` also does not update the gateway endpoint used in
already-issued WireGuard peer configs. During first-gateway
`node:new --role=gateway --host=<host>`, no peer configs exist yet, so the
bootstrap host seeds the initial gateway endpoint. After bootstrap, gateway
endpoint rotation needs a separate identity/network contract and is outside
`node:update`.

Role-conditional validity is enforced after the target node is resolved.
Incompatible fields fail with `node.field_role_incompatible` before any
gateway-owned side effects.

## Caller Role Behavior

`node:update` resolves the caller role from the local node role setting before it
reads command arguments or renders prompts. See the node-family
[Local Caller Role](../../README.md#local-caller-role) contract.

If `general.local_node_role` is unset or `null`, the caller role is `control`.
Gateway and app callers must be explicit through `general.local_node_role`.

| Caller role | Behavior |
| --- | --- |
| `control` | With configured gateway access, forward to the gateway over HTTPS through WireGuard. Without configured gateway access, fail before prompts or side effects. See [`2_node-update_on-control-node.md`](2_node-update_on-control-node.md). |
| `gateway` | Executes locally on the gateway. See [`3_node-update_on-gateway-node.md`](3_node-update_on-gateway-node.md). |
| `app` | Not allowed. Fail before prompts or side effects with `This command may only be run from a control or gateway node.` See [`4_node-update_on-app-node.md`](4_node-update_on-app-node.md). |
| `unknown` | Invalid local context. Used only when `general.local_node_role` contains an unsupported value or cannot be read. Fail before prompts or side effects with a local context error. Missing `general.local_node_role` does not produce `unknown`; it defaults to `control`. |

Role-specific behavior is defined in these companion contracts:

- [`2_node-update_on-control-node.md`](2_node-update_on-control-node.md):
  control caller gateway-forwarding behavior.
- [`3_node-update_on-gateway-node.md`](3_node-update_on-gateway-node.md):
  gateway-local execution behavior.
- [`4_node-update_on-app-node.md`](4_node-update_on-app-node.md): app caller
  behavior.

The role-specific companion pages are authoritative for caller-role behavior.
This canonical page owns shared inputs, shared behavior, output links, failure
categories, doctor relationship, and test mapping.

## Input Resolution

1. Resolve caller role.
   - Read `general.local_node_role` before reading command arguments,
     rendering prompts, forwarding requests, or starting side effects.
   - If `general.local_node_role` is unset or `null`, resolve caller role as
     `control`.
   - If `general.local_node_role` is `control`, `gateway`, or `app`, use that
     value for local path selection.
   - If the local role setting contains an unsupported value or cannot be read,
     resolve caller role as `unknown` and fail before prompts, forwarding, or
     side effects.
   - If the caller is an app node, apply
     [`4_node-update_on-app-node.md`](4_node-update_on-app-node.md) and fail
     before resolving `node_update.name` or any field flags.
2. Resolve execution context.
   - If the caller role is `gateway`, execute locally on the gateway.
   - If the caller role is `control`, require configured gateway access and
     prepare a typed gateway request.
3. Resolve `node_update.name` from `[name]` or the selected input mode.
   - In interactive mode, prompt when `[name]` is missing.
   - In non-interactive mode, fail before side effects when `[name]` is absent.
4. Validate `node_update.name` immediately.
   - Must match an existing active node record.
5. Resolve field flags.
   - Resolve `node_update.host` from `--host` when present.
   - Resolve `node_update.environment` from `--environment` when present.
   - Resolve `node_update.public_ipv4` from `--public-ipv4` when present.
   - Resolve `node_update.public_ipv6` from `--public-ipv6` when present.
6. Validate role-conditional field eligibility.
   - If `--environment` is present and the target node role is not `app`, fail
     before side effects with `node.field_role_incompatible`.
   - If `--host`, `--public-ipv4`, or `--public-ipv6` is present and the target
     node role is `control`, fail before side effects with
     `node.field_role_incompatible`.
   - If the same field flag is supplied more than once in a single invocation,
     fail before side effects with `validation_failed` and `meta.field` set to
     the duplicated field name. Symfony last-wins is not accepted.
7. Validate that at least one supported field is provided.
   - In non-interactive input mode, fail before side effects when no supported
     field flags are provided.
   - In interactive input mode, prompt for which field to change when no field
     flags are provided.
8. If running from a control node, forward the typed request to the gateway over
   HTTPS through WireGuard. The gateway authenticates the control node identity
   and authorizes the request through gateway-owned node access policy before
   gateway-owned side effects begin.

## Input Mode Contracts

Input mode behavior is split out of the canonical command contract:

- [`5.1_node-update_input-mode_interactive.md`](5.1_node-update_input-mode_interactive.md):
  prompt mapping, prompt validation, retry behavior, and interactive
  missing-input behavior.
- [`5.2_node-update_input-mode_non-interactive.md`](5.2_node-update_input-mode_non-interactive.md):
  no-prompt resolution, missing-input failures, forbidden-input failures, and
  `--json` input behavior.

## Behavior Contract

### Target And Field Validation Rules

- Find the node record by name. If not found, fail before side effects.
- Check role-conditional field rules. If a field is supplied for an
  incompatible role, fail before side effects with
  `node.field_role_incompatible`.

### Intent Delta Rules

- Compare each supplied field with the current stored value.
- Fields that match the current value are no-ops and do not appear in
  `changed`.
- Update the node record with the new values for changed fields.

### Artifact Re-enactment Rules

- When a changed field has node-side effects, re-enact the node-owned host
  artifacts associated with that changed field.
- The set of fields that triggers node-side re-enactment is implementation
  detail; the contract only promises that any drift after a successful intent
  write surfaces under the warning channel.

### Drift Warning Rules

- If artifact enactment fails after intent was committed, the command result
  remains a top-level `success` because gateway-owned intent was written.
- The remaining node-side artifact drift is node-family drift owned by
  `doctor --family=node`.
- The selected output renderer reports the warning with the recovery path
  `doctor --fix --family=node --restore`.
- Exit code stays at `0`. See the JSON renderer contract for the exact warning
  shape.
- Return the updated node name, the `changed` array, and any drift warnings.

### Scope Boundaries

`node:update` must not:
- Change the target node's role. Role change is an identity migration and is
  outside `node:update` scope. There is no `--role` input flag and no future
  migration command is named yet; a future explicit role-migration contract
  will own that flow.
- Change a development app node's TLD after creation. Node doctor may repair
  drift back to the TLD already stored in gateway node intent, but intentional
  TLD migration requires a future explicit command contract.
- Update operating system packages, Orbit installations, tools, or system
  services beyond node-owned artifacts directly affected by the changed field.
- Update app runtime policy, tool state, firewall policy, proxy routes,
  processes, schedules, or deployment pipelines.
- SSH into the target node unless re-enactment of a changed field requires it.
- Mint identity, write peer material, or grant access.
- Treat an unchanged-value update as a failure.
- Re-enact node-owned artifacts when intent did not change. Re-applying
  unchanged gateway-tracked configuration is owned by
  `doctor --fix --family=node --restore`, not `node:update`.

No-op updates where all supplied values equal the current stored values return
success with an empty `changed` array. The `changed` array represents the
gateway intent delta only. There is no separate `re_enacted` array; artifact
re-enactment is an implementation side effect of an intent change, not a
separately reported delta.

## Renderer Contracts

Output renderer behavior is split out of the canonical command contract:

- [`6.1_node-update_output-render_human.md`](6.1_node-update_output-render_human.md): progress
  tree, exact human-rendered strings, prose errors, summaries, warnings, and
  next steps.
- [`6.2_node-update_output-render_json.md`](6.2_node-update_output-render_json.md): JSON
  envelope, data shape, error codes, error messages, error metadata, validation
  errors, and the `success.meta.warnings[]` shape used for partial-success
  artifact enactment drift.

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| No field provided | Non-interactive mode and no supported field flags are provided. | Failure |
| Duplicate field flag | The same field flag is supplied more than once in a single invocation. | Failure |
| Node not found | No active node record matches `name`. | Failure |
| Field role-incompatible | A field is supplied for a node role that does not support it (e.g. `--environment` for a non-app node, or `--host`/`--public-ipv4`/`--public-ipv6` for a control node). | Failure |
| Caller role not allowed | The caller role is `app`. | Failure |
| Gateway unavailable | A control caller has no configured gateway or cannot reach the gateway API. | Failure |
| Authorization failed | A forwarded control caller is not authorized to operate on the gateway node or the target node. | Failure |
| Local context invalid | `general.local_node_role` is unreadable or unsupported. | Failure |

Artifact enactment failure after a successful intent write is **not** a
command failure. It returns a top-level `success` with a structured warning
under `success.meta.warnings[]` and exit code `0`. See the
[JSON renderer contract](6.2_node-update_output-render_json.md#success-with-artifact-enactment-warning).

## Doctor Relationship

- `doctor --family=node` verifies role-owned host artifacts and may report
  drift created by failed artifact re-enactment.
- `node:update` does not probe, infer, validate, fix, adopt, or drift-check
  public IPv4/IPv6 metadata. See [`node-doctor.md`](../../node-doctor.md).
- Broader drift in tools, firewall rules, apps, workspaces, processes,
  schedules, and proxy routes is verified by those family contracts.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed node
metadata writes.

| Field | Value |
| --- | --- |
| Type | `api:PUT /nodes/{name}` |
| Effect | `write` |
| Subject | Target `Node` when the node is resolved; `none` when validation or lookup fails before target resolution. |
| Properties | `target_node` is the requested node name; `changed_fields` lists stored fields that changed. |
| Description | `Node <name> updated` |

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Nodes/NodeUpdateCommandTest.php` | Command contract: updating node fields, role-conditional field validation, no-op success with empty `changed`, node-not-found failure, control-caller gateway forwarding, app-node caller denial before prompts or side effects, artifact re-enactment reporting, and warning payload shape for partial-success drift. |
| `tests/Feature/Commands/Nodes/NodeUpdateOnControlNodeContractTest.php` | Primary owner for control-caller behavior: configured control callers forward over HTTPS through WireGuard, unconfigured control callers fail before prompts or side effects, forwarded requests require access to the gateway node, and no SSH-to-gateway path is used. |

Input-mode-specific test mapping lives in:

- [`5.1_node-update_input-mode_interactive.md`](5.1_node-update_input-mode_interactive.md#test-mapping)
- [`5.2_node-update_input-mode_non-interactive.md`](5.2_node-update_input-mode_non-interactive.md#test-mapping)

Renderer-specific test mapping lives in:

- [`6.1_node-update_output-render_human.md`](6.1_node-update_output-render_human.md#test-mapping)
- [`6.2_node-update_output-render_json.md`](6.2_node-update_output-render_json.md#test-mapping)

Role-specific and E2E test mapping lives in:

- [`2_node-update_on-control-node.md`](2_node-update_on-control-node.md#test-mapping)
- [`3_node-update_on-gateway-node.md`](3_node-update_on-gateway-node.md#test-mapping)
- [`4_node-update_on-app-node.md`](4_node-update_on-app-node.md#test-mapping)
