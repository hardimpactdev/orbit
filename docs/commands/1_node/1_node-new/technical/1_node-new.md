# Technical Contract: `orbit node:new [name]`

[Back to public `node:new` documentation.](../node-new.md)

**Owner:** `node`.

**Effects:** `write`, `stream`.

**Prerequisites:**
- The local caller role can be resolved according to the foundation
  [local node role setting](../../../../BLUEPRINT.md#local-node-role-setting)
  contract and the node-family
  [Local Caller Role](../../README.md#local-caller-role) contract.
- `node:new` is invoked from a control or gateway caller. App-node callers are
  rejected before prompts, forwarding, or side effects.
- Role-specific network, platform, topology, and authorization prerequisites
  are applied as post-input path eligibility in the role companion contracts
  once the requested role and required fields are known.

## Signature

```bash
orbit node:new [name] [--role=gateway|app|control] [--host=<host>] [--control-name=<name>] [--environment=development|production] [--tld=<tld>] [--ssh-user=<user>] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | `[name]` | Caller role = `control` or `gateway`. | Never. | None. | Valid gateway-registry node name following the [identity slug](../../../../BLUEPRINT.md#identity-names) contract. Must be unique among active node records unless the existing record is compatible and the selected path is convergence or adoption. |
| `role` | `--role` | Caller role = `control` or `gateway`. | Never. | None. | One of `gateway`, `app`, `control`. |
| `host` | `--host` | Requested role = `app` or `gateway`. | Requested role = `control`. | None. | SSH/bootstrap endpoint, never the canonical node address. |
| `control_name` | `--control-name` | Caller role = `control`, requested role = `gateway`, and no gateway is configured locally. | Outside first-gateway bootstrap. | Normalized local short hostname. | Valid gateway-registry node name following the [identity slug](../../../../BLUEPRINT.md#identity-names) contract. Must not equal `node_new.name`. Must be unique among active node records unless the existing record is the compatible initiating control node for first-gateway convergence. |
| `environment` | `--environment` | Requested role = `app`. | Requested role = `gateway` or `control`. | None. | One of `development`, `production`. |
| `tld` | `--tld` | Requested role = `app` and `environment=development`. | Requested role = `gateway`, requested role = `control`, or requested role = `app` and `environment=production`. | None. | Single lowercase DNS label without a leading dot. Unique among active node TLDs and gateway development DNS mappings. |
| `ssh_user` | `--ssh-user` | Never required from the operator; resolved when SSH provisioning is used. | Requested role = `control`. | `root`. | Bootstrap-only SSH user. It is not the steady-state `RemoteShell` user. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Caller Role Behavior

`node:new` resolves the caller role from the local node role setting before it
reads command inputs, renders prompts, forwards requests, or starts side
effects. See the node-family [Local Caller Role](../../README.md#local-caller-role)
contract and the foundation
[local node role setting](../../../../BLUEPRINT.md#local-node-role-setting)
contract.

If `general.local_node_role` is unset or `null`, the caller role is `control`.
Gateway and app callers must be explicit through `general.local_node_role`.
Gateway configuration is separate from caller-role resolution: a control caller
with no gateway configuration is still a control caller, but only the
first-gateway bootstrap path is eligible.

| Caller role | Behavior |
| --- | --- |
| `control` | Client call to the gateway for normal operation. May bootstrap the first gateway over SSH when no gateway exists yet. See [`2_node-new_on-control-node.md`](2_node-new_on-control-node.md). |
| `gateway` | Authority path. May enroll control nodes, provision or adopt app nodes, and converge gateway intent. See [`3_node-new_on-gateway-node.md`](3_node-new_on-gateway-node.md). |
| `app` | Not allowed. Fail before prompts or side effects with `This command may only be run from a control or gateway node.` See [`4_node-new_on-app-node.md`](4_node-new_on-app-node.md). |
| `unknown` | Invalid local context. Used only when `general.local_node_role` contains an unsupported value or cannot be read. Fail before prompts or side effects with a local context error. Missing `general.local_node_role` does not produce `unknown`; it defaults to `control`. |

Role-specific behavior is defined in these companion contracts:

- [`2_node-new_on-control-node.md`](2_node-new_on-control-node.md): first-gateway
  bootstrap and gateway-forwarded operation from control nodes.
- [`3_node-new_on-gateway-node.md`](3_node-new_on-gateway-node.md): gateway-owned
  enrollment, provisioning, adoption, and convergence rules.
- [`4_node-new_on-app-node.md`](4_node-new_on-app-node.md): app-node denial and
  exact error contract.

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
     [`4_node-new_on-app-node.md`](4_node-new_on-app-node.md) and fail before
     resolving `node_new.name` or `node_new.role`.
2. Resolve `node_new.name` from `[name]`. Validate it immediately.
3. Resolve `node_new.role` from `--role`. Validate it before resolving role-specific fields.
4. Resolve role-specific inputs.
   - For `app`, resolve `node_new.environment`, `node_new.host`, and
     `node_new.ssh_user`.
   - If `node_new.environment` is `development`, also resolve `node_new.tld`.
   - If `node_new.environment` is `production`, do not ask for `node_new.tld`.
   - For `gateway`, resolve `node_new.host` always.
   - For first-gateway bootstrap, also resolve `node_new.control_name`.
   - For `gateway`, resolve `node_new.ssh_user` when SSH bootstrap or adoption
     is used.
   - For `control`, do not ask for host, control name, environment, TLD, or
     SSH user.
5. Validate required, forbidden, and path-eligibility rules as soon as the
   fields needed for each rule are known.
   - Field-local validation runs when the field is supplied or submitted.
   - Path eligibility runs immediately when the requested path can be
     determined, not after unrelated prompts have completed.
   - In interactive input mode, path blockers show a validation message at the
     current corrective prompt when the user can safely choose a different path.
     Otherwise they stop the command before asking for later inputs that cannot
     affect the blocker.
6. Apply any remaining post-input path eligibility before side effects.
   - For control callers, apply
     [`2_node-new_on-control-node.md`](2_node-new_on-control-node.md) using
     the resolved requested role and role-specific inputs.
   - For gateway callers, apply
     [`3_node-new_on-gateway-node.md`](3_node-new_on-gateway-node.md) using
     the resolved requested role and role-specific inputs.
7. Select the output renderer and begin the side-effect flow. The human
   renderer owns progress output; the JSON renderer owns the final JSON
   envelope.

Field-local validation and path eligibility must run at the earliest point where
the needed inputs are known. Interactive input mode should keep the user at the
current prompt when changing that answer can resolve the blocker.

## Input Mode Contracts

Input mode behavior is split out of the canonical command contract:

- [`5.1_node-new_input-mode_interactive.md`](5.1_node-new_input-mode_interactive.md)
- [`5.2_node-new_input-mode_non-interactive.md`](5.2_node-new_input-mode_non-interactive.md)

## Behavior Contract

### Shared Registry Rules

- Check the gateway registry before creating node intent.
- If the requested node already exists with compatible role, host,
  environment, and node identity, converge or confirm it.
- If the requested node exists with incompatible role, host, environment, or
  identity, fail before destructive changes.

### Control Node Enrollment

- Create the gateway registry row with `role=control`, mint a WireGuard peer,
  and return the interface config.
- The returned control-node WireGuard peer address must match `nodes.wg_ip`.
  A generic `vpn-client:new` peer without a matching active control-node row is
  ignored by gateway API authorization for Orbit CLI calls protected by
  WireGuard identity.

### Gateway Bootstrap And Convergence

- Provision the target host over SSH only when the host has not already been
  provisioned for the requested identity.
- Gateway bootstrap writes `general.local_node_role=gateway` on the gateway host
  after gateway state and reachability are established.
- First-gateway bootstrap stores the resolved `node_new.host` as the initial
  gateway endpoint used in generated WireGuard peer configs. The endpoint is a
  connectivity fact and may be DNS, public IP, private IP, or any address
  reachable by nodes joining the fleet.
- First-gateway bootstrap mints and installs the initiating control-node
  identity using `node_new.control_name`. This is a separate node identity from
  the gateway node named by `node_new.name`.
- Gateway `--host` is required during first bootstrap and later convergence
  checks. If the requested gateway is already provisioned and active, and the
  supplied host is compatible with that gateway identity, converge idempotently
  without reprovisioning. The selected output renderer owns how
  already-provisioned convergence is reported.
- If a compatible existing gateway is drifted or incomplete, do not reprovision
  it from `node:new`; report the drift or incomplete provisioning and point to
  `doctor --family=node --fix`.

### App Node Provisioning

- App nodes are always gateway-provisioned or gateway-adopted over SSH.
  `node:new --role=app` does not mint a detached WireGuard config for manual
  app-node installation.
- Provision the target host over SSH only when the host has not already been
  provisioned for the requested identity.
- App-node provisioning writes `general.local_node_role=app` on the app host
  after app-node identity and readiness are established.
- For development app nodes, persist `nodes.tld`, configure the app node's local
  TLD default, and create the gateway-owned development DNS mapping so
  `*.tld` resolves to the app node's WireGuard IP.
- Future development apps created on that app node use the node TLD for app and
  workspace route domains.
- Production app nodes do not use a development TLD; they rely on production
  domain workflows.

### Shared Provisioning Details

- The `node_new.ssh_user` value is a bootstrap credential only. Successful
  gateway and app-node provisioning creates or verifies the Orbit-managed SSH
  user, normally `orbit`, stores that steady-state user in gateway node intent
  as `nodes.user`, and `RemoteShell` uses that stored user for later
  gateway-to-node enactment.
- Control nodes may leave `general.local_node_role` unset because unset resolves
  to `control`.

### Adoption And Drift Boundaries

- `node:new` is an explicit node-membership adoption and convergence path. It may
  adopt a compatible already-provisioned gateway or app host into gateway intent
  as part of adding that node. Broader drift adoption, disaster recovery, and
  adoption of observed node reality outside this explicit membership flow remain
  owned by `doctor --family=node --adopt`.
- Apply only role bootstrap requirements. Other state families own their own
  artifacts, except node-owned bootstrap artifacts such as minimum app-node
  runtime readiness, node identity readiness, and development TLD mapping.

### Out Of Scope

- `node:new` does not detect, infer, or store public IPv4/IPv6 metadata.
  `node_new.host` is the operator-supplied SSH/bootstrap endpoint, and for
  first-gateway bootstrap it also seeds the initial gateway endpoint.
  Operator-supplied public IP metadata may be recorded later through
  `node:update`, but node doctor does not probe or drift-check it.
- `node:new` does not set the local default development app node. Operators must
  run [`node:default`](../../9_node-default/node-default.md) explicitly to set
  that local targeting preference.
- Destructive gateway reset is outside `node:new` and requires a future explicit
  reset contract.

## Renderer Contracts

Output renderer behavior is split out of the canonical command contract:

- [`6.1_node-new_output-render_human.md`](6.1_node-new_output-render_human.md): progress
  tree, exact human-rendered strings, prose errors, summaries, and manual next
  steps.
- [`6.2_node-new_output-render_json.md`](6.2_node-new_output-render_json.md): JSON
  envelope, data shape, error codes, error messages, error metadata, validation
  errors, and partial-provisioning errors.

## Failure Semantics

- Exit `0` on success or compatible idempotent convergence.
- Exit `1` on validation, authorization, network, SSH, platform, or
  provisioning failure.
- Exit `2` only for invalid command usage before command execution.
- Input contract violations fail before side effects through the selected input
  mode and output renderer.
- Fail when an app node is requested before a gateway is available.
- Fail before provisioning when the observed target host platform is not
  supported for the requested role. Supported role/platform pairs are defined in
  [`node-concepts.md`](../../node-concepts.md#role-platform-support).
- Fail when SSH bootstrap cannot reach the host or loses access mid-run.
- Report partial provisioning when gateway intent was written and a usable
  gateway exists, but host enactment did not complete. That node appears as
  drift until provisioning is repaired by `doctor --family=node --fix`, adopted
  where safe, or removed.
- First-gateway bootstrap failures that happen before gateway intent and API
  access exist cannot be handed to doctor yet. Report the failed step and the
  manual retry or cleanup path through the selected output renderer.

## Doctor Relationship

See [Node Doctor](../../node-doctor.md) for the authoritative node-family
probe, drift, fix, and adopt contract.

`node:new` can create or resolve node-family drift by writing gateway intent
before host enactment completes, installing gateway/control/app bootstrap
artifacts, and creating development TLD readiness artifacts. Drift in tools,
firewall rules, apps, workspaces, processes, schedules, and proxy routes is
verified by those family contracts after the node exists.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Nodes/NodeNewInputContractTest.php` | Primary owner for the canonical input contract table: fields, sources, required conditions, forbidden conditions, defaults, value validation, `control_name` being required only for first-gateway bootstrap, caller-role resolution from `general.local_node_role` with unset/null defaulting to `control`, caller-role resolution timing, and post-input path eligibility timing/delegation. It asserts resolved input and validation outcomes, not resolver classes or handler internals. Input-mode-specific prompting/failure behavior and role-specific path eligibility contents/outcomes are owned by the split contracts. |

Input-mode-specific test mapping lives in:

- [`5.1_node-new_input-mode_interactive.md`](5.1_node-new_input-mode_interactive.md#test-mapping)
- [`5.2_node-new_input-mode_non-interactive.md`](5.2_node-new_input-mode_non-interactive.md#test-mapping)

Renderer-specific test mapping lives in:

- [`6.1_node-new_output-render_human.md`](6.1_node-new_output-render_human.md#test-mapping)
- [`6.2_node-new_output-render_json.md`](6.2_node-new_output-render_json.md#test-mapping)

Role-specific and E2E test mapping lives in:

- [`2_node-new_on-control-node.md`](2_node-new_on-control-node.md#test-mapping)
- [`3_node-new_on-gateway-node.md`](3_node-new_on-gateway-node.md#test-mapping)
- [`4_node-new_on-app-node.md`](4_node-new_on-app-node.md#test-mapping)
