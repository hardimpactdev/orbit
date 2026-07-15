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
  - the gateway authorizes the caller for `node:new` on the active gateway node;
  - for a host-provisioned workload, the target is reachable from the initiating
    client over SSH as `node_new.user`;
  - that SSH preflight observes Ubuntu 24.04 or 26.04 and an `amd64` or `arm64`
    architecture before the gateway reserves identity.

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
| `--template=app-production` or `--roles=app-prod[...]` | Resolve canonical role inputs and production placement, then forward to the gateway over HTTPS as either colocated `roles: ['app-prod', 'ingress']` or private `roles: ['app-prod']` plus `ingress_node=<node>`. Requires `node_new.host`, `node_new.user`, and `node_new.tld`; private placement also requires selecting an active `ingress` node. |
| `--template=database` or `--roles=database` | Resolve canonical workload-node inputs, then forward to the gateway over HTTPS as `roles: ['database']`. Requires `node_new.host`, `node_new.user`, and `node_new.tld`; forwards `node_new.gateway_endpoint` when supplied. |
| `--template=agent` or `--roles=agent` | Resolve canonical role inputs, then forward to the gateway over HTTPS as `roles: ['agent']`. Requires `node_new.host`, `node_new.user`, and `node_new.tld`; forwards any selected agent tools. |
| `--template=websocket` or `--roles=websocket` | Reserved stable input surface. Current behavior fails before forwarding with `validation_failed` and `error.meta.reason=not_implemented`. The template path uses `error.meta.field=template`; explicit `--roles` uses `error.meta.field=roles`. |
| `--template=s3` or `--roles=s3` | Reserved stable input surface. Current behavior fails before forwarding with `validation_failed` and `error.meta.reason=not_implemented`. The template path uses `error.meta.field=template`; explicit `--roles` uses `error.meta.field=roles`. |
| `--template=metrics` or `--roles=metrics` | Resolve canonical role inputs, then forward to the gateway over HTTPS as `roles: ['metrics']`. Requires `node_new.host` and `node_new.user`. |
| `--roles=<csv>` | Forward compatible canonical role arrays with shared host/user fields and any role-specific fields already resolved. |

Explicit live-role examples include `roles: ['app-prod', 'ingress']` and
`roles: ['app-dev', 'database']`. Development app roles also forward
the mandatory `node_new.tld`. Metrics forwards host/user inputs like other live workload-role
paths. WebSocket and S3 role inputs are reserved but fail before forwarding
until their implementation todos land.

## First-Gateway Bootstrap

When no gateway is configured and `--template=gateway` is requested:

1. Resolve `node_new.name`, `node_new.template`, `node_new.host`,
   `node_new.tld`, `node_new.operator_name`, and `node_new.operator_tld`.
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

- Connect to a host-provisioned target through client-local SSH before prepare,
  read `/etc/os-release` and `uname -m`, normalize the result to
  `platform=ubuntu_24-04|ubuntu_26-04` and
  `architecture=amd64|arm64`, and reject unsupported targets before any gateway
  reservation.
- Send the resolved request to the authenticated gateway bootstrap-prepare
  endpoint, including the observed platform and architecture.
- Preserve all resolved role-specific inputs in the forwarded request,
  including:
  - `node_new.host` and `node_new.user` for gateway convergence or adoption;
  - canonical `roles[]` arrays for role requests;
  - `node_new.tld` for development app-role and agent provisioning;
  - `node_new.redis_node` for future websocket role provisioning;
  - `node_new.s3_data_path` for future S3 role provisioning;
  - host and user fields for metrics role provisioning.
- Use the CLI's WireGuard identity for gateway API authorization.
- Do not write durable node records locally.
- Before target inspection or SSH, ask the gateway to resume the resolved
  request. If the same initiating client owns an Agent-ready pending bootstrap
  or a completed bootstrap, skip target SSH and call completion with the
  existing bootstrap identifier. Otherwise continue with preflight and prepare.
- For a host-provisioned workload, receive the node-specific bootstrap bundle
  over that authenticated connection and stream it to `node_new.user` at
  `node_new.host` through a client-local SSH process.
- After the target starts WireGuard and its WireGuard-bound Agent, call the
  gateway bootstrap-complete endpoint and follow gateway-authored provisioning
  progress. The gateway finishes role, tool, runtime, and security convergence
  through Agent push.
- During that completion window, operation-token verification may resolve the
  pending `provisioning` node only when the callback arrives from its reserved
  WireGuard address and the scoped token context matches. Other API routes
  still reject provisioning-node identity. Agent access outside bootstrap
  still requires an active node.
- Never send an SSH private key or SSH agent socket to the gateway. The target
  SSH connection originates from the initiating client.

The prepare response is the only place the secret bootstrap bundle appears. It
must not be written to command output, operation results, or logs. The bundle
is idempotent and installs only the substrate required to establish the normal
managed transport: the Orbit runtime user, WireGuard, the Orbit CLI, and Orbit
Agent. The target may download release artifacts from their manifest URLs; the
gateway itself is not a pre-WireGuard artifact or enrollment endpoint.

There is no manual no-SSH fallback. If the initiating client cannot SSH to the
target, `node:new` fails and leaves the compatible pending gateway reservation
available for an idempotent retry. Once the Agent is ready, or after completion
has committed and public SSH is closed, that retry does not require SSH.

## Failure Semantics

- If no gateway is configured and the request is not first-gateway bootstrap,
  fail before side effects.
- If the gateway rejects the caller's identity or node access policy, fail
  before provisioning.
- If client-local host-key verification, SSH authentication, or bootstrap
  execution fails, report the local bootstrap step and do not call completion.
- If the target OS or architecture is unsupported, fail before prepare so no
  gateway identity is reserved.
- If the target starts WireGuard but Agent does not become reachable, retain
  the pending node/bootstrap identity and report Agent readiness as the failed
  step so the same request can retry safely.
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

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Node/NodeWriteCommandTest.php` | Client-context node:new input and prepare payload validation before gateway contact. |
| `apps/cli/tests/Feature/Commands/Node/NodeNewBootstrapCommandTest.php` | Client-local platform and architecture preflight, authenticated prepare, template routing, SSH bundle streaming, failure behavior, and completion ordering. |
| `apps/gateway/tests/Feature/Http/Api/InternalExecutorTokenControllerTest.php` | Provisioning Agent callbacks are accepted only from the node's reserved WireGuard address with a matching scoped token. |

Input handling and renderer behavior live in `apps/cli`; the gateway-side row
covers the narrow provisioning identity exception used during completion.
