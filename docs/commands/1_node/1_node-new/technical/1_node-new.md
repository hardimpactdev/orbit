# Technical Contract: `orbit node:new [name]`

[Back to public `node:new` documentation.](../node-new.md)

**Owner:** `node`.

**Effects:** `write`, `stream`.

**Prerequisites:**
- The gateway authorizes `node:new` for callers whose authenticated node record
  has role `control` or `gateway`. App-role callers are rejected by the
  gateway. First-gateway bootstrap is the one no-gateway path: an unconfigured
  CLI runs the SSH bootstrap directly.
- Role-specific network, platform, topology, and authorization prerequisites
  are applied as post-input path eligibility in the role companion contracts
  once the requested role and required fields are known.

## Signature

```bash
orbit node:new [name] [--role=<hosted-role>]... [--host=<host>] [--control-name=<name>] [--environment=development|production] [--tld=<tld>] [--user=<user>] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | `[name]` | Always. | Never. | None. | Valid gateway-registry node name following the [identity slug](../../../../ARCHITECTURE.md#identity-names) contract. Must be unique among active node records unless the existing record is compatible and the selected path is convergence or adoption. |
| `roles` | `--role` | Never required. | Never. | `[]`. | Repeatable hosted roles. Accepted canonical values: `app-development`, `app-production`, `database`. Legacy compatibility values: `control`, `app`, `gateway`. `gateway` remains a bootstrap path, not a public hosted-role assignment. |
| `host` | `--host` | First-gateway bootstrap, gateway convergence, `app-development`, or `app-production`. | Joined client/control identity with no hosted roles, or `database`-only identity. | None. | SSH/bootstrap endpoint, never the canonical node address. Must be an IP address or dotted DNS name. |
| `control_name` | `--control-name` | Requested role = `gateway` and no gateway is configured locally (first-gateway bootstrap). | Outside first-gateway bootstrap. | Normalized local short hostname. | Valid [identity slug](../../../../ARCHITECTURE.md#identity-names). Must not equal `node_new.name`. Must be unique among active node records unless the existing record is the compatible initiating control node for first-gateway convergence. |
| `environment` | `--environment` | Only when legacy `--role=app` is used. | Canonical hosted-role input, gateway bootstrap, and joined client/control identity. | None. | Legacy compatibility mapper: `development` => `app-development`; `production` => `app-production`. |
| `tld` | `--tld` | `app-development`, or legacy `--role=app --environment=development`. | Joined client/control identity, gateway bootstrap, `database`, `app-production`, or legacy `--role=app --environment=production`. | None. | Single lowercase DNS label without a leading dot. Unique among active node TLDs and gateway development DNS mappings. |
| `user` | `--user` | Never required from the operator; resolved when SSH provisioning is used. | Joined client/control identity with no host provisioning. | `root`. | Bootstrap SSH user. The gateway stores the steady-state runtime user after provisioning. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Input Resolution

1. Resolve `node_new.name` from `[name]`. Validate it immediately.
2. Resolve all `node_new.role` values from repeatable `--role`.
3. Normalize the requested role set before side effects.
   - No `--role` values means a joined client/control identity with no hosted
     roles.
   - Legacy `control` maps to no hosted roles.
   - Legacy `app` requires `--environment`; it maps to `app-development` or
     `app-production`.
   - Gateway bootstrap/convergence remains the special `gateway` path.
4. Resolve role-specific inputs.
   - For `app-development`, resolve `node_new.host`, `node_new.tld`, and
     `node_new.user`.
   - For `app-production`, resolve `node_new.host` and `node_new.user`.
   - For `database`, no extra input is required unless another requested role
     requires provisioning.
   - For `gateway`, resolve `node_new.host` always, plus
     `node_new.control_name` for first-gateway bootstrap.
5. Validate required, forbidden, and path-eligibility rules as soon as the
   fields needed for each rule are known.
   - Field-local validation runs when the field is supplied or submitted.
   - Path eligibility runs immediately when the requested path can be
     determined, not after unrelated prompts have completed.
   - In interactive input mode, path blockers show a validation message at the
     current corrective prompt when the user can safely choose a different path.
     Otherwise they stop the command before asking for later inputs that cannot
     affect the blocker.
6. Send the typed request to the gateway. The gateway authenticates the
   presented WireGuard identity and applies the authorization rules described
   in [`2_node-new_on-control-node.md`](2_node-new_on-control-node.md),
   [`3_node-new_on-gateway-node.md`](3_node-new_on-gateway-node.md), or
   [`4_node-new_on-app-node.md`](4_node-new_on-app-node.md) before any
   gateway-owned side effects. First-gateway bootstrap is the exception
   described in [`2_node-new_on-control-node.md`](2_node-new_on-control-node.md).
7. Select the output renderer and begin the side-effect flow. Renderer-specific
   progress and payload details live in the renderer contracts.

Field-local validation and path eligibility must run at the earliest point where
the needed inputs are known. Interactive input mode should keep the user at the
current prompt when changing that answer can resolve the blocker.

## Input Mode Contracts

Input mode behavior is split out of the canonical command contract:

- [`5.1_node-new_input-mode_interactive.md`](5.1_node-new_input-mode_interactive.md)
- [`5.2_node-new_input-mode_non-interactive.md`](5.2_node-new_input-mode_non-interactive.md)

## Behavior Contract

### Shared Registry Rules

- Check the gateway registry before creating node configuration.
- If the requested node already exists with compatible role, host,
  environment, and node identity, converge or confirm it.
- If the requested node exists with incompatible role, host, environment, or
  identity, fail before destructive changes.

### Joined Client Identity

- `node:new <name>` with no hosted roles creates the base node identity only.
- The legacy shadow node role column stays conservative:
  `control` for joined client identities with no active hosted roles,
  `app` while legacy compatibility fields are still needed for initial app-role
  creation, and `gateway` for gateway bootstrap/convergence identities.

### Gateway bootstrap and convergence

- Provision the target host over SSH only when the host has not already been
  provisioned for the requested identity.
- First-gateway bootstrap stores the resolved `node_new.host` as the initial
  gateway endpoint used in generated WireGuard peer configs. The endpoint is a
  connectivity fact and must be an IP address or dotted DNS name reachable by
  nodes joining the fleet.
- First-gateway bootstrap mints and installs the initiating control-node
  identity using `node_new.control_name`. This is a separate node identity from
  the gateway node named by `node_new.name`.
- First-gateway bootstrap also creates exactly one internal `gateway`
  assignment. It must not duplicate that assignment during later convergence.
- Gateway `--host` is required during first bootstrap and later convergence
  checks. If the requested gateway is already provisioned and active, and the
  supplied host is compatible with that gateway identity, converge idempotently
  without reprovisioning. The selected output renderer owns how
  already-provisioned convergence is reported.
- If a compatible existing gateway is drifted or incomplete, do not reprovision
  it from `node:new`; report the drift or incomplete provisioning and point to
  `doctor --family=node --restore`.

### Hosted Role Provisioning

- Provision host-capable identities over SSH before initial hosted-role
  assignments are created.
- Validate conflicts before side effects where possible. For example,
  `app-development` plus `app-production` must fail before node creation or
  provisioning.
- Create the node identity first, then add each requested hosted role.
- `app-development` assignments store `settings.tld`.
- `app-production` and `database` assignments use empty settings.
- `database` may be combined with `app-development` or `app-production` on the
  same provisioned host.
- If an initial hosted role is persisted with `status=error` because its first
  convergence failed, `node:new` fails and returns the role status and
  `last_error` in failure metadata. The persisted assignment remains available
  for later doctor recovery.

### Shared Provisioning Details

- The `node_new.user` value is the bootstrap SSH credential. Successful
  gateway and app-node provisioning creates or verifies the Orbit-managed SSH
  user, normally `orbit`, stores that steady-state user in gateway node
  configuration as `nodes.user`, and `RemoteShell` uses that stored user for
  later gateway-to-node applying.

### Adoption and drift boundaries

- `node:new` is an explicit node-membership adoption and convergence path. It may
  adopt compatible app hosts into gateway configuration as part of adding that
  node and may converge an already-known gateway. Missing gateway-row
  materialization is outside `node:new` because gateway caller authority is
  derived from an active local gateway node row. Broader drift adoption,
  disaster recovery, and adoption of observed node reality outside this
  explicit membership flow remain owned by a future explicit recovery path or
  `doctor --family=node --adopt` where the doctor contract already allows it.
- Apply only role bootstrap requirements. Other state families own their own
  artifacts, except node-owned bootstrap artifacts such as minimum app-node
  runtime readiness, node identity readiness, and development TLD mapping.

### Out of scope

- `node:new` does not detect, infer, or store public IPv4/IPv6 metadata.
  `node_new.host` is the SSH/bootstrap endpoint the operator supplies, and for
  first-gateway bootstrap it also seeds the initial gateway endpoint.
  Public IP metadata may be recorded later through
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

## Activity Logging

Emitted through the cross-cutting Loggable contract. See
[`activity-concepts.md`](../../../17_activity/activity-concepts.md).

| Field | Value |
| --- | --- |
| Channel | `api` (gateway controller) for gateway API node creation requests. |
| Type | `node.created` |
| Effect | `write` |
| Subject | The created, enrolled, provisioned, adopted, or converged `Node` when the node record exists; otherwise `null` for early validation or authorization failures. |
| Properties | `name` (string\|null), `role` (`gateway`\|`app`\|`control`\|null), `environment` (`development`\|`production`\|null), `tld` (string\|null). No secrets, no raw argv, no SSH bootstrap user. |
| Description | `derived`, for example `"Created node app-dev-1."` |

The first-gateway bootstrap path can run before a gateway API activity sink is
available; that local CLI emission is tracked separately from this gateway API
contract.

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
- Report partial provisioning when gateway configuration was written and a
  usable gateway exists, but host applying did not complete. That node appears
  as drift until provisioning is repaired by `doctor --family=node --restore`,
  adopted where safe, or removed.
- First-gateway bootstrap failures that happen before gateway configuration and
  API access exist cannot be handed to doctor yet. Report the failed step and
  the manual retry or cleanup path through the selected output renderer.

## Doctor Relationship

See [Node Doctor](../../node-doctor.md) for the authoritative node-family
probe, drift, restore, and adopt contract.

`node:new` can create or resolve node-family drift by writing gateway
configuration before host applying completes, installing gateway/control/app
bootstrap artifacts, and creating development TLD readiness artifacts. Drift in
tools, firewall rules, apps, workspaces, processes, schedules, and proxy
routes is verified by those family contracts after the node exists.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Nodes/NodeNewInputContractTest.php` | Owns the canonical input contract: fields, sources, required/forbidden conditions, defaults, value validation, `control_name` required only for first-gateway bootstrap, and post-input path eligibility timing. Asserts resolved input and validation outcomes — not resolver internals. Input-mode prompting and gateway-side authorization outcomes belong to the split contracts. |

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
