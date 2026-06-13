# Technical Contract: `orbit node:new [name]`

[Back to public `node:new` documentation.](../node-new.md)

**Owner:** `node`.

**Effects:** `write`, `stream`.

**Prerequisites:**
- The gateway authorizes `node:new` with the `node:new` permission on the
  active gateway node. Gateway callers are implicitly authorized; non-gateway
  callers require a covering grant to the gateway node. First-gateway bootstrap
  is the one no-gateway path: an unconfigured CLI runs the SSH bootstrap
  directly and materializes an initial gateway-admin grant from the initiating
  client's operator identity to the new gateway.
- Role-specific network, platform, topology, and authorization prerequisites
  are applied as post-input path eligibility in the role companion contracts
  once the requested role set and required fields are known.

## Signature

```bash
orbit node:new [name] [--template=<template>] [--operator] [--roles=<roles>] [--host=<host>] [--operator-name=<name>] [--tld=<tld>] [--user=<user>] [--ingress=<node>] [--redis-node=<node>] [--s3-data-path=<path>] [--host-key-fingerprint=<fingerprint>] [--self-grant=<mode>] [--self-grant-permissions=<permissions>] [--grant-to=<node>] [--grant-to-preset=<preset>] [--grant-to-permissions=<permissions>] [--grant-from=<node>] [--grant-from-preset=<preset>] [--grant-from-permissions=<permissions>] [--agent-tool=<tool>] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | `[name]` | Always. | Never. | None. | Valid gateway-registry node name following the [identity slug](../../../../architecture.md#identity-names) contract. Must be unique among active node records unless the existing record is compatible and the selected path is convergence or adoption. |
| `template` | `--template` | Never required. | When `--operator` is present unless `--template=operator`, or when `--roles` is present. | None. | One of `operator`, `app-development`, `app-production`, `gateway`, `ingress`, `database`, `s3`, `websocket`, or `agent`. |
| `operator` | `--operator` | Never required. | When `--template` is present unless `--template=operator`, or when `--roles` is present. | `false`. | Creates a client identity with the operator permission preset and no workload roles. Operator is not a node role. |
| `roles` | `--roles` | Never required. | When `--template` or `--operator` is present. | `[]`. | Comma-separated canonical role values (see role values below). |
| `host` | `--host` | First-gateway bootstrap, gateway convergence, `app-dev`, `app-prod`, `ingress`, `agent`, `websocket`, `s3`, and every template that provisions a host. | Client identity with no roles, `--operator`, or `database`-only identity without host provisioning. | None. | SSH/bootstrap endpoint, never the canonical node address. Must be an IP address or dotted DNS name. |
| `operator_name` | `--operator-name` | `--template=gateway` and no gateway is configured locally (first-gateway bootstrap). | Outside first-gateway bootstrap. | Normalized local short hostname. | Valid [identity slug](../../../../architecture.md#identity-names). Must not equal `node_new.name`. Must be unique among active node records unless the existing record is the compatible initiating client for first-gateway convergence. |
| `tld` | `--tld` | `app-dev` or `app-development` template/path. | Client identity, gateway bootstrap, `database`-only identity, or `app-prod`. | None. | Single lowercase DNS label without a leading dot. Unique among active node TLDs and gateway development DNS mappings. |
| `user` | `--user` | Never required from the operator; resolved when SSH provisioning is used. | Client identity with no host provisioning. | `root`. | Bootstrap SSH user. The gateway stores the steady-state runtime user after provisioning. |
| `ingress_node` | `--ingress` | Private `app-prod` placement. | Every path other than private `app-prod` placement. | None. | Must match an active node with the `ingress` role. |
| `redis_node` | `--redis-node` | `websocket`. | Every path that does not include `websocket`. | None. | Must match an active node with the `database` role and Redis expected or installed. |
| `s3_data_path` | `--s3-data-path` | Never. | Every path that does not include `s3`. | `/srv/orbit/s3/data`. | Absolute host path mounted into SeaweedFS as `/data`. |
| `host_key_fingerprint` | `--host-key-fingerprint` | Optional. | Never. | None. | Expected SSH host key SHA256 fingerprint for verification during provisioning. |
| `self_grant` | `--self-grant` | Optional. | Never. | None. | Self-grant mode applied to this node identity. |
| `self_grant_permissions` | `--self-grant-permissions` | Optional. | Never. | None. | Custom permission set for the self-grant. Requires `--self-grant`. |
| `grant_to` | `--grant-to` | Optional. | Never. | None. | Grant this node access to another node. Multiple values allowed. |
| `grant_to_preset` | `--grant-to-preset` | Optional. | Never. | None. | Preset for the `--grant-to` permission set. |
| `grant_to_permissions` | `--grant-to-permissions` | Optional. | Never. | None. | Custom permission set for the `--grant-to` grant. |
| `grant_from` | `--grant-from` | Optional. | Never. | None. | Grant another node access to this node. Multiple values allowed. |
| `grant_from_preset` | `--grant-from-preset` | Optional. | Never. | None. | Preset for the `--grant-from` permission set. |
| `grant_from_permissions` | `--grant-from-permissions` | Optional. | Never. | None. | Custom permission set for the `--grant-from` grant. |
| `agent_tool` | `--agent-tool` | Optional. | Never. | None. | Agent tool to install on this node. Multiple values allowed. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

Canonical stored role values accepted through `--roles` are `app-dev`,
`app-prod`, `database`, `agent`, `ingress`, `websocket`, and `s3`. Role
aliases are not accepted through `--roles`; `app-development` and
`app-production` are template names only. `gateway`, `vpn`, and `router` are internal assignments coupled to the gateway,
expanded only by `--template=gateway`, not public role values.

## Template Expansion

When `--template` is supplied, the gateway expands the template to a role set
before role validation:

| Template | Expanded roles |
| --- | --- |
| `operator` | (none) |
| `app-development` | `app-dev`, `database` |
| `app-production` | `app-prod` plus colocated `ingress` or private `app-prod` with `--ingress` |
| `gateway` | `gateway`, `vpn`, `router` |
| `ingress` | `ingress` |
| `database` | `database` |
| `s3` | `s3` (implementation pending) |
| `websocket` | `websocket` (implementation pending) |
| `agent` | `agent` |

Templates `s3` and `websocket` fail with `template_not_implemented` until
their implementations land.

## Input Resolution

1. Resolve `node_new.name` from `[name]`. Validate it immediately.
2. Resolve `node_new.template` from `--template` when present.
3. Resolve `node_new.operator` from `--operator` when present.
4. Resolve all `node_new.roles` from comma-separated `--roles` when present.
5. Expand templates to role sets before normalization side effects.
6. Normalize the requested role set before side effects.
   - `--operator` or `--template=operator` means a client identity with the
     operator preset and no workload roles.
   - No `--roles` values and no workload-bearing template means a client identity
     with no assigned roles.
   - `--roles` values must already be canonical.
   - Gateway bootstrap/convergence is selected only by `--template=gateway`.
7. Resolve role-specific inputs.
   - For `app-dev`, resolve `node_new.host`, `node_new.tld`, and
     `node_new.user`.
   - For `app-prod`, resolve `node_new.host`, `node_new.user`, and the
     production placement choice.
   - For `ingress`, resolve `node_new.host` and `node_new.user`.
   - For `websocket`, resolve `node_new.host`, `node_new.user`, and
     `node_new.redis_node`.
   - For `s3`, resolve `node_new.host`, `node_new.user`, and
     `node_new.s3_data_path`.
   - For `database`, no extra input is required unless another requested role
     requires provisioning.
   - For `gateway`, resolve `node_new.host` always, plus
     `node_new.operator_name` for first-gateway bootstrap.
8. Validate required, forbidden, and path-eligibility rules as soon as the
   fields needed for each rule are known.
   - Field-local validation runs when the field is supplied or submitted.
   - Path eligibility runs immediately when the requested path can be
     determined, not after unrelated prompts have completed.
   - In interactive input mode, path blockers show a validation message at the
     current corrective prompt when the user can safely choose a different path.
     Otherwise they stop the command before asking for later inputs that cannot
     affect the blocker.
6. Send the typed request to the gateway. The gateway authenticates the
   presented WireGuard identity and applies the grant authorization rules
   described in [`2_node-new_on-client.md`](2_node-new_on-client.md) or
   [`3_node-new_on-gateway-node.md`](3_node-new_on-gateway-node.md) before any
   gateway-owned side effects. First-gateway bootstrap is the exception
   described in [`2_node-new_on-client.md`](2_node-new_on-client.md).
7. Select the output renderer and begin the side-effect flow. Renderer-specific
   progress and payload details live in the renderer contracts.

Field-local validation and path eligibility must run at the earliest point where
the needed inputs are known. Interactive input mode should keep the user at the
current prompt when changing that answer can resolve the blocker.

## Input Mode Contracts

Input mode behavior is split out of the canonical command contract:

- [`5.1_node-new_input-mode_interactive.md`](5.1_node-new_input-mode_interactive.md)
- [`5.2_node-new_input-mode_non-interactive.md`](5.2_node-new_input-mode_non-interactive.md)

Caller-path behavior is split out into:

- [`2_node-new_on-client.md`](2_node-new_on-client.md)
- [`3_node-new_on-gateway-node.md`](3_node-new_on-gateway-node.md)

## Behavior Contract

### Shared Registry Rules

- Check the gateway registry before creating node configuration.
- If the requested node already exists with compatible role assignments, host,
  role-assignment settings, and node identity, converge or confirm it.
- If the requested node exists with incompatible role assignments, host,
  role-assignment settings, or identity, fail before destructive changes.

### Client Identity

- `node:new <name>` with no roles creates the base node identity only.

### Gateway bootstrap and convergence

- Provision the target host over SSH only when the host has not already been
  provisioned for the requested identity.
- First-gateway bootstrap stores the resolved `node_new.host` as the initial
  gateway endpoint used in generated WireGuard peer configs. The endpoint is a
  connectivity fact and must be an IP address or dotted DNS name reachable by
  nodes joining the fleet.
- First-gateway bootstrap mints and installs the initiating client
  identity using `node_new.operator_name`. This is a separate node identity from
  the gateway node named by `node_new.name`.
- First-gateway bootstrap also creates or materializes exactly one internal
  `gateway` assignment, one internal `vpn` assignment, and one internal
  `router` assignment on the same node. It must not duplicate any of those
  assignments during later convergence.
- Gateway `--host` is required during first bootstrap and later convergence
  checks. If the requested gateway is already provisioned and active, and the
  supplied host is compatible with that gateway identity, converge idempotently
  without reprovisioning. The selected output renderer owns how
  already-provisioned convergence is reported.
- If a compatible existing gateway is drifted or incomplete, do not reprovision
  it from `node:new`; report the drift or incomplete provisioning and point to
  `doctor --family=node --restore`.
- First-gateway bootstrap uses the dedicated bootstrap installer before a
  gateway API exists. It must not use the shared managed-node setup path that
  applies gateway intent to already registered nodes.

### Workload Role Provisioning

- Provision host-capable identities over SSH before initial role
  assignments are created.
- Validate conflicts before side effects where possible. For example,
  `app-dev` plus `app-prod` must fail before node creation or
  provisioning.
- Create the node identity first, then add each requested role. Role settings
  stay minimal: `app-prod` assignments store `settings.ingress_node_id`,
  `websocket` assignments store `settings.redis_node_id`, and `s3` assignments
  store `settings.data_path`. `database` assignments use empty settings. The
  `app-dev` role requires the node-level `tld` field (shared with `agent`), not
  a role-assignment setting.
- `app-prod` placement must be explicit. The command's public and
  companion contracts own the exact prompt, placement choices, and failure
  shape for missing ingress.
- `database` may be combined only with `app-dev`, `websocket`, and `s3`
  on the same provisioned host; `websocket` may be combined with `app-dev`,
  `database`, and `s3`; `s3` may be combined with `app-dev`,
  `database`, and `websocket`. WebSocket assignments require
  `settings.redis_node_id` to reference an active database role node with Redis
  expected or installed. Reverb runs in a Docker runtime container managed by
  Orbit and binds only to the node's WireGuard address.

S3 assignment convergence stores `settings.data_path`, defaulting to
`/srv/orbit/s3/data`. SeaweedFS runs in a Docker runtime container rendered by
Orbit, mounts that path as `/data`, binds only to the node's WireGuard address,
and receives traffic only through router-owned S3 service routes.
- If an initial role is persisted with `status=error` because its first
  convergence failed, `node:new` fails and returns the role status and
  `last_error` in failure metadata. The persisted assignment remains available
  for later doctor recovery.

### Managed Node Setup and Activation

- For a fresh managed workload node, `node:new` writes node identity and role
  intent first, then applies the setup slice of that gateway intent to the real
  node before marking the node `active`.
- The initial setup slice for `app-dev` applies the role baseline tools
  `caddy`, `php-cli`, `composer`, and `laravel-installer` to node reality
  through the same internal convergence path that tool doctor restore uses for
  overlapping safe repairs.
- `node:new` must not report a successful active managed workload node while
  the setup slice is still missing or unverifiable. Setup failure returns
  `node.provisioning_incomplete`, includes the failed setup step in metadata,
  and rolls back fresh provisioning when rollback is available for that path.
- A freshly provisioned `app-dev` node should pass a plain
  `doctor --family=tool` check for the baseline tool slice without requiring
  an immediate explicit `doctor --family=tool --restore` repair.

### Shared Provisioning Details

- The `node_new.user` value is the bootstrap SSH credential. Successful
  gateway and app-role provisioning creates or verifies the Orbit-managed SSH
  user, normally `orbit`, stores that steady-state user in gateway node
  configuration as `nodes.user`, and `RemoteShell` uses that stored user for
  later gateway-to-node applying.
- Successful SSH provisioning copies the bootstrap user's authorized SSH keys
  to the Orbit-managed runtime user before lock-down, installs an Orbit-owned
  sshd drop-in that disables password and root SSH login, restricts SSH login
  to the runtime user, validates the sshd configuration, reloads sshd, locks
  the root password, and removes `/root/.ssh/authorized_keys`.

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
  artifacts, except node-owned bootstrap artifacts such as minimum app-role
  runtime readiness, node identity readiness, and development TLD mapping.

### Out of scope

- `node:new` does not detect, infer, or store public IPv4/IPv6 metadata.
  `node_new.host` is the SSH/bootstrap endpoint the operator supplies, and for
  first-gateway bootstrap it also seeds the initial gateway endpoint.
  Public IP metadata may be recorded later through
  `node:update`, but node doctor does not probe or drift-check it.
- `node:new` does not set the local default development node. Operators must
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
| Properties | `name` (string\|null), `roles` (list<string>), `tld` (string\|null), `template` (string\|null). No secrets, no raw argv, no SSH bootstrap user. |
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
- App-role requests fail before side effects when no gateway is available.
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
configuration before host applying completes, installing gateway/operator/app
bootstrap artifacts, and creating development TLD readiness artifacts. Drift in
tools, firewall rules, apps, workspaces, processes, schedules, and proxy
routes is verified by those family contracts after the node exists.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Commands/Nodes/NodeNewInputContractTest.php` | Owns the canonical input contract: fields, sources, required/forbidden conditions, defaults, value validation, `operator_name` required only for first-gateway bootstrap, and post-input path eligibility timing. |

The contract owner asserts resolved input and validation outcomes — not
resolver internals. Input-mode prompting and gateway-side authorization
outcomes belong to the split contracts.

Input-mode-specific test mapping lives in:

- [`5.1_node-new_input-mode_interactive.md`](5.1_node-new_input-mode_interactive.md#test-mapping)
- [`5.2_node-new_input-mode_non-interactive.md`](5.2_node-new_input-mode_non-interactive.md#test-mapping)

Renderer-specific test mapping lives in:

- [`6.1_node-new_output-render_human.md`](6.1_node-new_output-render_human.md#test-mapping)
- [`6.2_node-new_output-render_json.md`](6.2_node-new_output-render_json.md#test-mapping)

Role-specific and E2E test mapping lives in:

- [`2_node-new_on-client.md`](2_node-new_on-client.md#test-mapping)
- [`3_node-new_on-gateway-node.md`](3_node-new_on-gateway-node.md#test-mapping)
