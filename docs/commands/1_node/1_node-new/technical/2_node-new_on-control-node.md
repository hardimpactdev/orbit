# Technical Contract: `node:new` Authorized For Control Callers

[Back to `node:new` technical contract.](1_node-new.md)

This page describes what the gateway authorizes for callers whose
authenticated node record has role `control`, plus the special first-gateway
bootstrap path where the gateway does not exist yet.

**Post-input path eligibility:**
- For first-gateway bootstrap:
  - no gateway is configured locally;
  - the requested role is `gateway`;
  - `node_new.name`, `node_new.role`, `node_new.host`, and
    `node_new.control_name` can be resolved;
  - `node_new.user` can be resolved when SSH bootstrap is used;
  - the target host is reachable over SSH as `node_new.user`;
  - the target host platform is supported for the requested role;
  - the local CLI can install its own gateway-issued WireGuard identity,
    trust the gateway CA, and store local gateway endpoint configuration.
- For gateway-connected operation:
  - a gateway is configured locally;
  - the CLI has an active gateway-issued WireGuard identity;
  - the CLI can reach the gateway API over HTTPS through WireGuard;
  - the gateway authorizes the control-role caller to request the selected
    node creation or enrollment operation.

Evaluate each path eligibility rule as soon as the fields needed for that rule
are known. For example, a control caller with no configured gateway and a
resolved explicit requested role other than `gateway` fails before side effects,
before prompting for app-node host, environment, TLD, or any later input.
Omitted `--role` does not show a role prompt; it follows the joined/client
no-hosted-role path and only succeeds when a gateway is already configured.
Non-interactive input mode fails at the same early eligibility point for the
same blocker. All path eligibility must complete before side effects begin.

## Allowed Paths

| Requested role | Behavior |
| --- | --- |
| `gateway` | Bootstrap the first gateway and complete local control-node onboarding when no gateway is configured yet. When a gateway is configured, forward to the gateway for convergence or adoption. |
| omitted `--role` | Forward a joined/client identity request with no hosted roles to the configured gateway over HTTPS. |
| `control` | Legacy compatibility alias for the no-role joined/client forwarding path. Human mode warns that `control` now maps to a client identity with no hosted roles. |
| `app-development` | Resolve canonical hosted-role inputs, then forward to the gateway over HTTPS as `roles: ['app-development']`. Requires `node_new.host`, `node_new.user`, and `node_new.tld`. |
| `app-production` | Resolve canonical hosted-role inputs, then forward to the gateway over HTTPS as `roles: ['app-production']`. Requires `node_new.host` and `node_new.user`. |
| `database` | Forward a canonical hosted-role request as `roles: ['database']`. No SSH/bootstrap inputs are required when requested alone. |
| repeated hosted roles | Forward compatible canonical hosted-role arrays, such as `roles: ['app-production', 'database']` or `roles: ['app-development', 'database']`. When any requested role needs SSH provisioning, resolve and forward the shared `node_new.host` and `node_new.user`; development app roles also forward `node_new.tld`. |
| `app` | Legacy compatibility path. Resolve app-node inputs, then forward to the gateway over HTTPS using the legacy app contract with `node_new.environment`. Human mode warns that `app` now maps to hosted app roles after the environment is resolved interactively or from flags. |

## First-Gateway Bootstrap

When no gateway is configured and `--role=gateway` is requested:

1. Resolve `node_new.name`, `node_new.role`, `node_new.host`, and
   `node_new.control_name`.
2. Resolve `node_new.user` with the documented default or supplied value.
3. Connect to the target host over SSH.
4. Install the gateway runtime.
5. Initialize gateway state.
6. Register the gateway node as `node_new.name`.
7. Mint an active control-node identity named `node_new.control_name` for the
   initiating control machine.
8. Install the initiating control node's WireGuard configuration locally.
9. Fetch and trust the gateway CA.
10. Store `node_new.host` as the local gateway endpoint with the gateway trust
   material.
11. Verify gateway HTTPS reachability and `/api/me` with the new local
    WireGuard identity.

No HTTPS gateway API call is required before the gateway exists. After this
flow succeeds, the initiating control node is already onboarded and must not run
`gateway:add` for the newly created gateway.

## Gateway-Connected Operation

When a gateway is configured:

- Forward `node:new` to the gateway.
- Preserve all resolved role-specific inputs in the forwarded request,
  including:
  - `node_new.host` and `node_new.user` for gateway convergence or adoption;
  - canonical `roles[]` arrays for hosted-role requests;
  - `node_new.tld` for development hosted app-node provisioning;
  - legacy `node_new.environment` only for legacy `--role=app` forwarding.
- Use the CLI's WireGuard identity for gateway API authorization.
- Do not write durable node records locally.
- Do not SSH directly to app nodes from the CLI.

## Failure Semantics

- If no gateway is configured and the request is not first-gateway bootstrap,
  fail before side effects.
- If the gateway rejects the caller's identity or node access policy, fail
  before provisioning.
- If first-gateway SSH bootstrap fails before gateway configuration and gateway API
  access exist, report the failed step and the manual retry or cleanup path.
  Doctor cannot own that failure yet because there is no usable gateway view to
  run the node-family probe from.
- If first-gateway bootstrap has created gateway configuration and the gateway
  API is usable, but a later gateway readiness or local onboarding step fails,
  report the remaining mismatch as node-family drift for
  `doctor --family=node --restore`.
- If gateway bootstrap succeeds but the initiating CLI's WireGuard identity
  installation, trust storage, or gateway config storage fails, report partial
  local onboarding as node-family drift for `doctor --family=node --restore`.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Nodes/NodeNewOnControlNodeContractTest.php` | Control-caller behavior: post-input path eligibility, first-gateway bootstrap eligibility, complete local onboarding for the initiating CLI named by `node_new.control_name`, initial gateway endpoint seeded from `node_new.host`, gateway-connected forwarding for convergence/adoption/app-node creation/control-node enrollment, forwarded host and TLD input, missing-gateway failure for app/control requests, and no durable node state written locally outside first-gateway onboarding. |
| `tests/E2E/Ephemeral/NodeNewGatewayBootstrapTest.php` | Real-node smoke coverage for first-gateway bootstrap from a control node with no gateway configured, including SSH bootstrap, explicit initiating control-node name, initiating control-node identity installation, gateway endpoint/trust storage from the bootstrap host, `/api/me` verification, and no follow-up `gateway:add` requirement. |
| `tests/E2E/Ephemeral/NodeNewControlForwardingTest.php` | Real-node smoke coverage for control-node execution after `gateway:add`, proving gateway convergence or adoption, app-node creation, and control-node enrollment are forwarded to the gateway over WireGuard instead of applied locally. |
