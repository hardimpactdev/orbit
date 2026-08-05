# Technical Contract: `orbit node:update [name]`

[Back to public `node:update` documentation.](../node-update.md)

**Owner:** `node`.

**Effects:** `write`.

**Prerequisites:**
- The gateway authenticates the CLI and authorizes `node:update` with the
  `node:update` permission on the target node. Gateway callers are implicitly
  authorized. A grant using the `gateway-admin` preset also authorizes the
  write.
- Non-gateway callers have configured gateway access as defined in
  [`2_node-update_on-client.md`](2_node-update_on-client.md) and a covering
  node access grant for this operation.

**Post-input path eligibility:**
- The target node name resolves to an existing active node record.
- At least one supported field flag is provided in non-interactive input mode.
- The caller is authorized to mutate the target node through gateway-owned node
  access policy.

## Signature

```bash
orbit node:update [name] [--host=<host>] [--user=<user>] [--tld=<tld>] [--gateway-endpoint=<endpoint>] [--public-ipv4=<address>] [--public-ipv6=<address>] [--managed|--no-managed] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | `[name]` | Always. | Never. | None. | Must match an existing active node record. |
| `host` | `--host` | Optional. | Target is an operator-identity node with no host metadata. | None. | SSH/bootstrap endpoint, never the canonical node address. Updating this does not change the gateway endpoint used in WireGuard peer configs; use `--gateway-endpoint` for that. |
| `user` | `--user` | Optional. | Target is the gateway node. | None. | Orbit owner/runtime user for node-local Agent work. Orbit stores this value as node metadata; it does not create the OS user. |
| `tld` | `--tld` | Optional. | Never. | None. | Single lowercase DNS label without a leading dot and other than reserved `orbit`. Unique among active node TLDs. |
| `gateway_endpoint` | `--gateway-endpoint` | Optional. | Target is an operator-identity node. | None. | IP address or dotted DNS name that this node's WireGuard peer should use to reach the gateway. The WireGuard port is appended by Orbit. |
| `public_ipv4` | `--public-ipv4` | Optional. | Target is an operator-identity node. | None. | Operator-supplied public IPv4 metadata. |
| `public_ipv6` | `--public-ipv6` | Optional. | Target is an operator-identity node. | None. | Operator-supplied public IPv6 metadata. |
| `managed` | `--managed` or `--no-managed` | Optional. | `--managed` is forbidden for gateway or role-bearing nodes; `--no-managed` is never forbidden. | Omitted/unchanged; stored default is `false`. | Explicit roleless-operator Agent intent. `--managed` requires an active supported platform and valid WireGuard identity. Both flags in one invocation fail validation. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode according to the shared invocation model in [`docs/domains/README.md`](../../../README.md#invocation-model). |

Each field flag may be supplied at most once per invocation. Supplying the same
field flag more than once fails with `validation_failed` and `meta.field` set
to the duplicated field name. Last-wins is not an accepted behavior.

### Field eligibility by target node role

The target node role restricts which fields are valid to update. Bootstrap
fields shared with `node:new` keep the same role constraints, and public address
metadata is valid only for gateway and nodes. Concretely:

| Field | Valid target roles | Forbidden when |
| --- | --- | --- |
| `--host` | `gateway`, workload-role-bearing nodes | Operator-identity target with no host metadata. |
| `--user` | workload-role-bearing nodes and role-less operator nodes | Gateway target. |
| `--tld` | every active node | Never. |
| `--gateway-endpoint` | `gateway`, workload-role-bearing nodes | Operator-identity targets. |
| `--public-ipv4` | `gateway`, workload-role-bearing nodes | Operator-identity targets. |
| `--public-ipv6` | `gateway`, workload-role-bearing nodes | Operator-identity targets. |
| `--managed` | eligible roleless non-gateway operator nodes | Gateway, role-bearing, inactive, unsupported-platform, or invalid-WireGuard targets. |
| `--no-managed` | every node | Never. |

Clients are CLI callers reached through WireGuard. They have no SSH bootstrap
endpoint and no ingress, so `--host`, `--gateway-endpoint`, `--public-ipv4`,
and `--public-ipv6` are forbidden on operator-identity targets. Public IPv4 and
IPv6 metadata is supported on `gateway` and workload-role-bearing nodes; the
gateway endpoint used in WireGuard peer configs lives on a separate field and is
not updated by `--public-ipv4` or `--public-ipv6`.

`node:update --user` updates the Orbit owner/runtime user stored for the target
node. Non-gateway node-local tooling, doctor restore/adopt, updates, and
role-owned artifact convergence use Agent push and the stored user context;
gateway work remains local. Changing the field does not create, rename, or
validate an operating-system account. If the account is absent, the
configuration write may succeed while node drift remains. Repair belongs to
`doctor --family=node --restore` after the operator fixes the account.

`node:update --tld` updates the mandatory node-level `tld` for any active node.
The `tld` is a shared node-level field (at most one per node); changing it
triggers gateway DNS reconciliation and baseline convergence for every active
role assignment that depends on it, such as `app-dev` or `agent`. Changing a
node's roles is a role-assignment change outside `node:update`; use
[`node role:remove`](../../14_node-role-remove/node-role-remove.md) and
[`node role:add`](../../12_node-role-add/node-role-add.md).

`node:update --gateway-endpoint` updates the gateway endpoint stored for the
target node. For nodes with workload roles, Orbit updates the WireGuard endpoint
in `/etc/wireguard/wg-orbit.conf` or `/etc/wireguard/wg0.conf` when present,
writes a timestamped backup before editing, and applies the live peer endpoint
with `wg set` without restarting the interface. For gateway nodes, the field is
advertised endpoint metadata used by future peer configs.

`node:update --managed` and
`node:update --no-managed` toggle the node registry's
`managed` flag. The flag is explicit Agent intent only for eligible roleless
non-gateway operators. Active workload roles derive Agent intent without this
field, and gateway nodes are never Agent targets. It does not install the Agent, start a menu-bar
process, grant a role, create credentials, or prove that the node-local agent
is currently reachable.

Role-conditional validity is enforced after the target node is resolved.
Incompatible fields fail with `node.field_role_incompatible` before any
gateway-owned side effects.

## Input Resolution

1. Resolve `node_update.name` from `[name]` or the selected input mode.
   - In interactive mode, prompt when `[name]` is missing.
   - In non-interactive mode, fail before side effects when `[name]` is absent.
2. Validate `node_update.name` immediately.
   - Must match an existing active node record.
3. Resolve field flags.
   - Resolve `node_update.host` from `--host` when present.
   - Resolve `node_update.user` from `--user` when present.
   - Resolve `node_update.tld` from `--tld` when present.
   - Resolve `node_update.gateway_endpoint` from `--gateway-endpoint` when
     present.
   - Resolve `node_update.public_ipv4` from `--public-ipv4` when present.
   - Resolve `node_update.public_ipv6` from `--public-ipv6` when present.
   - Resolve `node_update.managed=true` from
     `--managed` when present.
   - Resolve `node_update.managed=false` from
     `--no-managed` when present.
4. Validate role-conditional field eligibility.
   - If `--host`, `--gateway-endpoint`, `--public-ipv4`, or `--public-ipv6` is
     present and the target is an operator-identity node, fail before side
     effects with `node.field_role_incompatible`.
   - If `--user` is present and the target is the gateway node, fail before
     side effects with `node.field_role_incompatible`.
   - If both managed Agent intent flags are present, fail before side effects
     with `validation_failed` and `meta.field=managed`.
   - If `--managed` targets anything other than an eligible active roleless
     non-gateway operator, fail before side effects with
     `node.field_role_incompatible`. `--no-managed` remains valid everywhere.
   - If the same field flag is supplied more than once in a single invocation,
     fail before side effects with `validation_failed` and `meta.field` set to
     the duplicated field name. Symfony last-wins is not accepted.
5. Validate that at least one supported field is provided.
   - In non-interactive input mode, fail before side effects when no supported
     field flags are provided.
   - In interactive input mode, prompt for which field to change when no field
     flags are provided.
6. Send the typed request to the gateway over HTTPS through WireGuard. The
   gateway authenticates the caller's WireGuard identity and authorizes the
   request through the node access policy it owns before any side effects.

## Input Mode Contracts

Input mode behavior is split out of the canonical command contract:

- [`5.1_node-update_input-mode_interactive.md`](5.1_node-update_input-mode_interactive.md):
  prompt mapping, prompt validation, retry behavior, and interactive
  missing-input behavior.
- [`5.2_node-update_input-mode_non-interactive.md`](5.2_node-update_input-mode_non-interactive.md):
  no-prompt resolution, missing-input failures, forbidden-input failures, and
  `--json` input behavior.

## Behavior Contract

### Target and field validation rules

- Find the node record by name. If not found, fail before side effects.
- Check role-conditional field rules. If a field is supplied for an
  incompatible role, fail before side effects with
  `node.field_role_incompatible`.
- Check TLD uniqueness before side effects. If another active node already owns
  the supplied TLD, fail with `node.tld_in_use`.
- Reject the reserved node TLD `orbit` before side effects because it belongs
  to the proxy-owned `.orbit` namespace.

### Configuration Delta Rules

- Compare each supplied field with the current stored value.
- Fields that match the current value are no-ops and do not appear in
  `changed`.
- Update the node record with the new values for changed fields.
- Changing `tld` updates the mandatory node identity for an active node. The
  node family reconciles its concrete `orbit.<node-tld>` record for every role
  and reconciles wildcard/local directives only when the node carries
  `app-dev` or `agent`. Repair after that metadata write belongs to the
  node-family Doctor path.
- Changing `gateway_endpoint` updates the endpoint stored at node level. The
  changed field triggers node-owned artifact re-applying for workload-role
  targets.
- Changing `user` updates the Orbit owner/runtime user stored at node level.
  The changed field affects subsequent Agent-push execution context and may
  trigger node-owned artifact re-applying where artifacts depend on that user.
- Changing `public_ipv4` on an `app-dev` node may change the managed
  `orbit-caddy` HTTP/HTTPS bindings when the value is an RFC1918 caller-facing
  LAN address. The private backend port remains WireGuard-only.
- Changing `managed` records explicit roleless-operator Agent intent. Workload
  role intent remains derived. The changed field appears in `changed` when the
  supplied boolean differs from the stored value.

### Artifact Re-applying Rules

- When a changed field has node-side effects, re-apply the node-owned host
  artifacts associated with that changed field.
- The set of fields that triggers node-side re-applying is implementation
  detail; the contract only promises that any drift after a successful
  configuration write surfaces under the warning channel.

### Drift Warning Rules

- If artifact applying fails after configuration was committed, the command
  result remains a top-level `success` because gateway-owned configuration was
  written.
- The remaining node-side artifact drift is node-family drift owned by
  `doctor --family=node`.
- The selected output renderer reports the warning with the recovery path
  `doctor --family=node --restore`.
- Renderer contracts own the exact warning shape.
- Return the updated node name, the `changed` array, and any drift warnings.

### Scope Boundaries

`node:update` must not:
- Change the target node's role assignments. There is no `--role` input flag on
  `node:update`; use
  [`node role:remove`](../../14_node-role-remove/node-role-remove.md) and
  [`node role:add`](../../12_node-role-add/node-role-add.md) for role-assignment
  changes.
- Update operating system packages, Orbit installations, tools, or system
  services beyond the artifacts that the node owns and that are directly affected by the changed field.
- Update app runtime policy, tool state, firewall policy, proxy routes,
  processes, schedules, or deployment pipelines.
- Use SSH for steady-state artifact re-applying. Gateway work must remain local;
  non-gateway node-local work uses Agent push.
- Mint identity, write peer material, or grant access.
- Treat an unchanged-value update as a failure.
- Install, start, update, restart, or uninstall the Orbit Agent process.
- Persist inferred role-derived intent in the `managed` column of the `nodes`
  table; workload roles are evaluated dynamically, while only roleless
  `--managed` opt-in is stored.
- Re-apply node-owned artifacts when configuration did not change. Re-applying
  unchanged gateway-tracked configuration is owned by
  `doctor --family=node --restore`, not `node:update`.

No-op updates where all supplied values equal the current stored values return
success with an empty `changed` array. The `changed` array represents the
gateway configuration delta only. There is no separate `re_applied` array;
artifact re-applying is an implementation side effect of a configuration
change, not a separately reported delta.

## Renderer Contracts

Output renderer behavior is split out of the canonical command contract:

- [`6.1_node-update_output-render_human.md`](6.1_node-update_output-render_human.md): progress
  tree, exact human-rendered strings, prose errors, summaries, warnings, and
  next steps.
- [`6.2_node-update_output-render_json.md`](6.2_node-update_output-render_json.md): JSON
  envelope, data shape, error codes, error messages, error metadata, validation
  errors, and the `success.meta.warnings[]` shape used for partial-success
  artifact applying drift.

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| No field provided | Non-interactive mode and no supported field flags are provided. | Failure |
| Duplicate field flag | The same field flag is supplied more than once in a single invocation. | Failure |
| Node not found | No active node record matches `name`. | Failure |
| Field role-incompatible | A field is supplied for a target role assignment that does not support it. | Failure |
| TLD already in use | `--tld` matches another active node's stored TLD. | Failure |

Examples: `--host`, `--gateway-endpoint`, `--public-ipv4`, or `--public-ipv6`
for an operator-identity node, or `--user` for the gateway node.

Artifact applying failure after a successful configuration write is **not** a
command failure. It returns a top-level `success` with a structured warning
under `success.meta.warnings[]`. See the
[JSON renderer contract](6.2_node-update_output-render_json.md#success-with-artifact-apply-warning).

## Doctor Relationship

- `doctor --family=node` verifies role-owned host artifacts and may report
  drift created by failed artifact re-applying.
- `node:update` does not probe, infer, validate, restore, adopt, or drift-check
  public IPv4/IPv6 metadata. See [`node-doctor.md`](../../node-doctor.md).
- Broader drift in tools, firewall rules, projects, instances, workspaces, processes,
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
| `apps/cli/tests/Feature/Commands/Node/NodeWriteCommandTest.php` | CLI update post, render, drift reporting, human renderer prose, non-interactive required-input validation, and JSON envelopes. |
| `apps/gateway/tests/Feature/Http/Api/NodeUpdateControllerTest.php` | Gateway field updates, TLD handling, no-op updates, and authorization envelopes. |

Input-mode-specific test mapping lives in:

- [`5.1_node-update_input-mode_interactive.md`](5.1_node-update_input-mode_interactive.md#test-mapping)
- [`5.2_node-update_input-mode_non-interactive.md`](5.2_node-update_input-mode_non-interactive.md#test-mapping)

Renderer-specific test mapping lives in:

- [`6.1_node-update_output-render_human.md`](6.1_node-update_output-render_human.md#test-mapping)
- [`6.2_node-update_output-render_json.md`](6.2_node-update_output-render_json.md#test-mapping)

Deployment-context-specific test mapping lives in:

- [`2_node-update_on-client.md`](2_node-update_on-client.md#test-mapping)
- [`3_node-update_on-gateway-node.md`](3_node-update_on-gateway-node.md#test-mapping)
Warning payload coverage note: linked tests cover only the mapped warning payload shape assertions above; remaining variants of the warning payload stay as coverage gaps until focused tests land.
