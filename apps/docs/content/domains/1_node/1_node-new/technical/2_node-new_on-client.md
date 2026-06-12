# Technical Contract: `node:new` From Operator-Node Callers

[Back to `node:new` technical contract.](1_node-new.md)

This page describes how configured operator-node CLI callers that are not
gateways forward `node:new` to the gateway, plus the special first-gateway
bootstrap path where the gateway does not exist yet.

**Post-input path eligibility:**
- For first-gateway bootstrap:
  - no gateway is configured locally;
  - the requested path is `--template=gateway`;
  - `node_new.name`, `node_new.template` or `node_new.roles`, `node_new.host`, and
    `node_new.operator_name` can be resolved;
  - `node_new.user` can be resolved when SSH bootstrap is used;
  - the target host is reachable over SSH as `node_new.user`;
  - the target host platform is supported for the requested role;
  - the local CLI can install its own gateway-issued WireGuard identity,
    trust the gateway CA, and store local gateway endpoint configuration.
- For gateway-connected operation:
  - a gateway is configured locally;
  - the CLI has an active gateway-issued WireGuard identity;
  - the CLI can reach the gateway API over HTTPS through WireGuard;
  - the gateway authorizes the caller for `node:new` on the active gateway node.

Evaluate each path eligibility rule as soon as the fields needed for that rule
are known. For example, a client with an operator identity and no configured
gateway and a resolved path other than first-gateway bootstrap fails before side effects,
before prompting for workload host, TLD, or any later input. Omitted
`--template`, `--operator`, and `--roles` values follow the bare client identity
path and only succeed when a gateway is already configured.
Non-interactive input mode fails at the same early eligibility point for the
same blocker. All path eligibility must complete before side effects begin.

## Allowed Paths

| Requested path | Behavior |
| --- | --- |
| `--template=gateway` | Bootstrap the first gateway and complete local operator onboarding when no gateway is configured yet. When a gateway is configured, forward to the gateway for convergence or adoption. |
| omitted `--template`, `--operator`, and `--roles` | Forward a client identity request with no roles to the configured gateway over HTTPS. |
| `--operator` or `--template=operator` | Forward a client identity request with the operator permission preset and no workload roles. |
| `--template=app-development` or `--roles=app-dev` | Resolve canonical role inputs, then forward to the gateway over HTTPS as `roles: ['app-dev', 'database']` for the template or `roles: ['app-dev']` for the explicit `--roles` path. Requires `node_new.host`, `node_new.user`, and `node_new.tld`. |
| `--template=app-production` or `--roles=app-prod[...]` | Resolve canonical role inputs and production placement, then forward to the gateway over HTTPS as either colocated `roles: ['app-prod', 'ingress']` or private `roles: ['app-prod']` plus `ingress_node=<node>`. Requires `node_new.host` and `node_new.user`; private placement also requires selecting an active `ingress` node. |
| `--template=database` or `--roles=database` | Forward a canonical role request as `roles: ['database']`. No SSH/bootstrap inputs are required when requested alone. |
| `--template=agent` or `--roles=agent` | Resolve canonical role inputs, then forward to the gateway over HTTPS as `roles: ['agent']`. Requires `node_new.host`, `node_new.user`, and optional `node_new.tld`; forwards any selected agent tools. |
| `--template=websocket` or `--roles=websocket` | Reserved stable input surface. Current behavior fails before forwarding with `template_not_implemented` for the template path or `role_not_implemented` for explicit `--roles` until the WebSocket todo lands. |
| `--template=s3` or `--roles=s3` | Reserved stable input surface. Current behavior fails before forwarding with `template_not_implemented` for the template path or `role_not_implemented` for explicit `--roles` until the S3 todo lands. |
| `--roles=<csv>` | Forward compatible canonical role arrays with shared host/user fields and any role-specific fields already resolved. |

Explicit live-role examples include `roles: ['app-prod', 'ingress']` and
`roles: ['app-dev', 'database']`. Development app roles also forward
`node_new.tld`. WebSocket and S3 role inputs are reserved but fail before
forwarding until their implementation todos land.

## First-Gateway Bootstrap

When no gateway is configured and `--template=gateway` is requested:

1. Resolve `node_new.name`, `node_new.template` or `node_new.roles`, `node_new.host`, and
   `node_new.operator_name`.
2. Resolve `node_new.user` with the documented default or supplied value.
3. Connect to the target host over SSH.
4. Install the gateway service.
5. Initialize gateway state.
6. Register the gateway node as `node_new.name`.
7. Mint an active client identity named `node_new.operator_name` for the
   initiating operator machine.
8. Install the initiating client's WireGuard configuration locally.
9. Fetch and trust the gateway CA.
10. Store `node_new.host` as the local gateway endpoint with the gateway trust
   material.
11. Verify gateway HTTPS reachability and `/api/me` with the new local
    WireGuard identity.

No HTTPS gateway API call is required before the gateway exists. After this
flow succeeds, the initiating client is already onboarded and must not run
`gateway:add` for the newly created gateway.

## Gateway-Connected Operation

When a gateway is configured:

- Forward `node:new` to the gateway.
- Preserve all resolved role-specific inputs in the forwarded request,
  including:
  - `node_new.host` and `node_new.user` for gateway convergence or adoption;
  - canonical `roles[]` arrays for role requests;
  - `node_new.tld` for development app-role and agent provisioning;
  - `node_new.redis_node` for future websocket role provisioning;
  - `node_new.s3_data_path` for future S3 role provisioning.
- Use the CLI's WireGuard identity for gateway API authorization.
- Do not write durable node records locally.
- Do not SSH directly to nodes from the CLI.

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
| `apps/gateway/tests/Feature/Commands/Nodes/NodeNewOnOperatorNodeContractTest.php` | Operator-node caller behavior across input, bootstrap, and forwarding paths; see detail below. |
| `apps/gateway/tests/E2E/Ephemeral/NodeNewGatewayBootstrapTest.php` | Real-node smoke coverage for first-gateway bootstrap from an operator node; see detail below. |
| `apps/gateway/tests/E2E/Ephemeral/NodeNewOperatorForwardingTest.php` | Real-node smoke coverage for operator-node execution after `gateway:add`, proving gateway convergence or adoption, workload-role creation, and client enrollment are forwarded to the gateway over WireGuard instead of applied locally. |

`NodeNewOnOperatorNodeContractTest.php` covers post-input path eligibility,
first-gateway bootstrap eligibility, complete local onboarding for the
initiating CLI named by `node_new.operator_name`, initial gateway endpoint seeded
from `node_new.host`, gateway-connected forwarding for
convergence/adoption/workload-role creation/client enrollment, forwarded host
and TLD input, missing-gateway failure for non-bootstrap requests, and no
durable node state written locally outside first-gateway onboarding.

`NodeNewGatewayBootstrapTest.php` exercises the flow from a client with
no gateway configured, including SSH bootstrap, explicit initiating
client name, initiating client identity installation, gateway
endpoint/trust storage from the bootstrap host, `/api/me` verification, and no
follow-up `gateway:add` requirement.
