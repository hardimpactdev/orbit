# Technical Contract: `orbit gateway:add [gateway_ip]`

[Back to public `gateway:add` documentation.](../gateway-add.md)

**Owner:** `node`.

**Effects:** `read`, `write`.

**Prerequisites:**
- The gateway has already issued a WireGuard identity and active node record for
  this machine. See [Node identity
  issuance](../../README.md#node-identity-issuance).
- The local machine has imported that WireGuard configuration and joined the
  active Orbit WireGuard network.
- The gateway can expose its root CA or trust bundle through the Orbit network
  before this machine has local OS-level trust installed.

## Signature

```bash
orbit gateway:add [gateway_ip] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `gateway_ip` | `[gateway_ip]` | Never; derived from network or fails. | Never. | Derived from active WireGuard network when unambiguous. | Valid IPv4 or IPv6 gateway WireGuard API address in the Orbit WireGuard range. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Caller Role Behavior

`gateway:add` resolves the caller role from the local node role setting before
it reads command inputs, renders prompts, or starts side effects. See the
node-family [Local Caller Role](../../README.md#local-caller-role) contract.

If `general.local_node_role` is unset or `null`, the caller role is `control`.
Gateway and app callers must be explicit through `general.local_node_role`.

| Caller role | Behavior |
| --- | --- |
| `control` | Normal operation. Derive or verify gateway address, fetch and trust CA, verify identity, store local config. See [`2_gateway-add_on-control-node.md`](2_gateway-add_on-control-node.md). |
| `gateway` | Invalid. The gateway does not need to add itself. Fail before prompts or side effects with `This command may only be run from a control node.` See [`3_gateway-add_on-gateway-node.md`](3_gateway-add_on-gateway-node.md). |
| `app` | Invalid. App-node gateway-client endpoint and trust artifacts are gateway-managed node bootstrap artifacts, not `gateway:add` local onboarding state. Fail before prompts or side effects with `This command may only be run from a control node.` See [`4_gateway-add_on-app-node.md`](4_gateway-add_on-app-node.md). |
| `unknown` | Invalid local context. Used only when `general.local_node_role` contains an unsupported value or cannot be read. Fail before prompts or side effects with a local context error. Missing `general.local_node_role` does not produce `unknown`; it defaults to `control`. |

Role-specific behavior is defined in these companion contracts:

- [`2_gateway-add_on-control-node.md`](2_gateway-add_on-control-node.md):
  control caller local onboarding behavior.
- [`3_gateway-add_on-gateway-node.md`](3_gateway-add_on-gateway-node.md):
  gateway caller denial.
- [`4_gateway-add_on-app-node.md`](4_gateway-add_on-app-node.md): app caller
  denial.

The role-specific companion pages are authoritative for caller-role behavior.
This canonical page owns shared inputs, shared behavior, output links, failure
categories, doctor relationship, and test mapping.

## Input Resolution

1. Resolve caller role.
   - Read `general.local_node_role` before reading command arguments,
     rendering prompts, or starting side effects.
   - If `general.local_node_role` is unset or `null`, resolve caller role as
     `control`.
   - If `general.local_node_role` is `control`, `gateway`, or `app`, use that
     value for local path selection.
   - If the local role setting contains an unsupported value or cannot be read,
     resolve caller role as `unknown` and fail before prompts or side effects.
   - If the caller is a gateway node, apply
     [`3_gateway-add_on-gateway-node.md`](3_gateway-add_on-gateway-node.md) and
     fail before resolving `gateway_add.gateway_ip`.
   - If the caller is an app node, apply
     [`4_gateway-add_on-app-node.md`](4_gateway-add_on-app-node.md) and fail
     before resolving `gateway_add.gateway_ip`.
2. Resolve `gateway_add.gateway_ip` from `[gateway_ip]`. Derive from the active
   WireGuard network when omitted.
3. Validate `gateway_add.gateway_ip` immediately.
   - Must be a valid Orbit WireGuard address.
   - Must be reachable as a gateway API endpoint.

## Input Mode Contracts

- [Interactive input mode](5.1_gateway-add_input-mode_interactive.md)
- [Non-interactive input mode](5.2_gateway-add_input-mode_non-interactive.md)

## Behavior Contract

### Local Onboarding Rules

- Check whether local gateway onboarding is already complete for the target
  gateway:
   - local gateway settings point to the same gateway WireGuard API address;
   - gateway CA trust exists locally and matches the fetched or verified trust
     material;
   - the gateway API verifies the local WireGuard identity through `/api/me`.
- When all conditions match, return success with `result.action:
  "converged"`.
- When local trust or settings are missing or stale but the gateway remains
  valid, continue through the local onboarding flow and return `result.action:
  "added"`.

### Trust Material Rules

- Fetch the gateway root CA or trust bundle through the bootstrap-safe gateway
  trust path (`/api/ca/root`).
- Follow HTTPS redirects when the gateway serves the CA over the same-host
  redirect.
- Install the fetched CA into local trust storage when local gateway CA trust is
  missing or stale.
- Use the same local trust-store repair behavior documented by
  [`gateway:trust`](../../11_gateway-trust/gateway-trust.md), but with the
  gateway endpoint resolved by the onboarding flow.

### Identity Verification Rules

- Make a trusted HTTPS request to `/api/me` using the newly trusted CA.
- Verify the `/api/me` response carries a valid node identity.
- Confirm the local WireGuard identity is known to the gateway and accepted by
  gateway-owned access policy.
- A 403 response from `/api/me` means the peer is not registered; fail with a
  clear message pointing to `node:new --role=control`.

### Local Configuration Rules

- Persist the gateway WireGuard IP as the local default gateway endpoint.
- Store the gateway trust material locally.
- Invalidate any cached local node context so subsequent commands use the newly
  stored gateway configuration.

### Scope Boundaries

`gateway:add` must not:
- Create gateway node or control node rows in the gateway registry.
- Create or update a local Orbit database as a registry mirror of the gateway
  or self node.
- Mint WireGuard peer material, identity, or access policy.
- SSH to the gateway or any app node.
- Provision hosts.
- Act as a broad repair or reset command. After local onboarding exists,
  standalone CA trust repair belongs to `gateway:trust`, and broader node drift
  belongs to `doctor --family=node --fix`.

## Renderer Contracts

- [Human renderer](6.1_gateway-add_output-render_human.md)
- [JSON renderer](6.2_gateway-add_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Invalid gateway IP | Supplied or derived value is not a valid Orbit WireGuard address. | Failure |
| Gateway unavailable | Cannot fetch CA or cannot reach `/api/me` over HTTPS. | Failure |
| Identity unknown | `/api/me` returns 403; the local peer is not registered. | Failure |
| Gateway API error | `/api/me` returns a non-success status other than 403. | Failure |
| Local config write failure | Gateway is reachable but local settings or CA file cannot be written. | Failure |
| Caller role not allowed | Invoked from a gateway or app node. | Failure |
| Local context invalid | `general.local_node_role` contains an unsupported value or cannot be read. | Failure |

Already-configured convergence is success, not failure.

## Doctor Relationship

- `doctor --self` verifies local control-node identity, trusted gateway
  material, configured gateway API endpoint, and gateway reachability. See
  [`node-doctor.md`](../../node-doctor.md).
- `doctor --family=node` verifies the gateway-owned node identity and access
  policy.
- `gateway:add` owns only the explicit local onboarding flow for an already
  issued control-node identity. `doctor --family=node --fix` owns later safe
  repair of node drift when the caller has enough information and
  authorization.
- `gateway:trust` owns the standalone repair command for local gateway CA trust
  after gateway settings already exist.

## Test Mapping

Primary existing test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/GatewayAddCommandTest.php` | Legacy broad coverage. Must be updated to match this contract before implementation changes: no `--force`, no local node registry mirror creation, node-shaped JSON references, and idempotent convergence. |
| `tests/E2E/Ephemeral/ControlNodeGatewayAddTest.php` | Real-node end-to-end control-node join via `gateway:add`; must cover idempotent convergence without `--force`. |

Required split contract tests:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Nodes/GatewayAddInputContractTest.php` | Command contract: input resolution, side-effect boundaries, local settings persistence, CA trust installation, no local node registry mirror creation, idempotent convergence, and gateway API verification. |
| `tests/Feature/Commands/Nodes/GatewayAddInteractiveInputModeTest.php` | Interactive input mode: TTY selection, `--json` opt-out, gateway IP derivation, prompt when derivation is ambiguous, prompt validation and retry, and caller-role denial rules. |
| `tests/Feature/Commands/Nodes/GatewayAddNonInteractiveInputModeTest.php` | Non-interactive input mode: no-prompt selection, `--json` forcing non-interactive mode, missing `gateway_ip` failure when derivation is ambiguous, invalid value failures, and caller-role denial rules. |
| `tests/Feature/Commands/Nodes/GatewayAddJsonRendererTest.php` | JSON renderer: envelope shape, node-shaped verified references, `added` and `converged` success payloads, error codes, and enum values. |
| `tests/Feature/Commands/Nodes/GatewayAddHumanRendererTest.php` | Human renderer: progress tree shape, success and failure prose, converged message, and next-step guidance. |
| `tests/Feature/Commands/Nodes/GatewayAddCallerRoleContractTest.php` | Caller role behavior: control caller allowed, gateway caller denied, app caller denied, and local context error handling. |
