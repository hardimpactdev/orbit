# Technical Contract: `node:new` On A Control Node

[Back to `node:new` technical contract.](1_node-new.md)

This page describes caller-role behavior when `orbit node:new` is invoked from a
control node.

**Prerequisites:**
- The caller role has resolved to `control` per the node-family
  [Local Caller Role](../../README.md#local-caller-role) contract.

**Post-input path eligibility:**
- For first-gateway bootstrap:
  - no gateway is configured locally;
  - the requested role is `gateway`;
  - `node_new.name`, `node_new.role`, `node_new.host`, and
    `node_new.control_name` can be resolved;
  - `node_new.ssh_user` can be resolved when SSH bootstrap is used;
  - the target host is reachable over SSH as `node_new.ssh_user`;
  - the target host platform is supported for the requested role;
  - the control node can install its own gateway-issued WireGuard identity,
    trust the gateway CA, and store local gateway endpoint configuration.
- For gateway-connected operation:
  - a gateway is configured locally;
  - the control node has an active gateway-issued WireGuard identity;
  - the control node can reach the gateway API over HTTPS through WireGuard;
  - the gateway authorizes the control node to request the selected node
    creation or enrollment operation.

Evaluate each path eligibility rule as soon as the fields needed for that rule
are known. For example, a control caller with no configured gateway and a
resolved requested role other than `gateway` shows a validation message at the
role prompt so the operator can choose `gateway` or cancel, before prompting for
app-node host, environment, TLD, or any later input. Non-interactive input mode
fails before side effects for the same blocker. All path eligibility must
complete before side effects begin.

## Allowed Paths

| Requested role | Behavior |
| --- | --- |
| `gateway` | Bootstrap the first gateway and complete local control-node onboarding when no gateway is configured yet. When a gateway is configured, forward to the gateway for convergence or adoption. |
| `app` | Resolve required app-node inputs, then forward to the configured gateway over HTTPS. Interactive input mode prompts for missing values; non-interactive input mode and `--json` fail before forwarding when required values are absent. |
| `control` | Forward to the configured gateway over HTTPS. |

## First-Gateway Bootstrap

When no gateway is configured and `--role=gateway` is requested:

1. Resolve `node_new.name`, `node_new.role`, `node_new.host`, and
   `node_new.control_name`.
2. Resolve `node_new.ssh_user` with the documented default or supplied value.
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
12. Set `general.local_node_role=gateway` on the gateway host as the remote
    gateway bootstrap commit point.

No HTTPS gateway API call is required before the gateway exists. After this
flow succeeds, the initiating control node is already onboarded and must not run
`gateway:add` for the newly created gateway.

## Gateway-Connected Operation

When a gateway is configured:

- Forward `node:new` to the gateway.
- Preserve all resolved role-specific inputs in the forwarded request,
  including `node_new.host` and `node_new.ssh_user` for gateway convergence or
  adoption, and `node_new.tld` for development app-node provisioning.
- Use the control node's WireGuard identity for gateway API authorization.
- Do not write durable node records locally.
- Do not SSH directly to app nodes from the control node.

## Failure Semantics

- If no gateway is configured and the request is not first-gateway bootstrap,
  fail before side effects.
- If the gateway rejects the control node identity or node access policy, fail
  before provisioning.
- If first-gateway SSH bootstrap fails before gateway intent and gateway API
  access exist, report the failed step and the manual retry or cleanup path.
  Doctor cannot own that failure yet because there is no usable gateway view to
  run the node-family probe from.
- If first-gateway bootstrap has created gateway intent and the gateway API is
  usable, but a later gateway readiness or local onboarding step fails, report
  the remaining mismatch as node-family drift for
  `doctor --fix --family=node --restore`.
- If gateway bootstrap succeeds but local control-node identity installation,
  trust storage, or gateway config storage fails, report partial local
  onboarding as node-family drift for `doctor --fix --family=node --restore`.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Nodes/NodeNewOnControlNodeContractTest.php` | Primary owner for control-caller behavior: unset/null `general.local_node_role` resolving to `control`, post-input path eligibility, first-gateway bootstrap eligibility, remote gateway role setting written after gateway state and reachability are established, complete local onboarding for the initiating control node named by `node_new.control_name`, initial gateway endpoint seeded from `node_new.host`, gateway-connected forwarding for gateway convergence or adoption, app-node creation, and control-node enrollment, forwarded gateway host input, forwarded development app-node host and TLD input, missing-gateway failure for app/control requests, and no durable gateway-owned node state written locally outside first-gateway local onboarding artifacts. |
| `tests/E2E/Ephemeral/NodeNewGatewayBootstrapTest.php` | Real-node smoke coverage for first-gateway bootstrap from a control node with no gateway configured, including SSH bootstrap, explicit initiating control-node name, initiating control-node identity installation, gateway endpoint/trust storage from the bootstrap host, `/api/me` verification, and no follow-up `gateway:add` requirement. |
| `tests/E2E/Ephemeral/NodeNewControlForwardingTest.php` | Real-node smoke coverage for control-node execution after `gateway:add`, proving gateway convergence or adoption, app-node creation, and control-node enrollment are forwarded to the gateway over WireGuard instead of enacted locally. |
