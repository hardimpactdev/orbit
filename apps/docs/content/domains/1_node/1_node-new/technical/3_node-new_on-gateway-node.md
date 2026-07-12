# Technical Contract: `node:new` Authorized For Gateway Callers

[Back to `node:new` technical contract.](1_node-new.md)

This page describes what the gateway authorizes for callers whose
authenticated node record has role `gateway`, including gateway-local CLI
execution.

**Prerequisites:**
- The gateway can read and write gateway-owned node configuration.
- The gateway can issue or verify WireGuard node identity material.

**Post-input path eligibility:**
- For omitted `--template`, `--operator`, and `--roles`, the gateway identity
  service can mint a WireGuard peer.
- For requested `app-dev`, `node_new.name`, `node_new.roles`,
  `node_new.host`, and `node_new.user` can be resolved, the target host is
  reachable over SSH as `node_new.user`, and `node_new.tld` can be resolved,
  is unique in gateway node configuration, and is not already mapped to another
  gateway development DNS target.
- For requested `app-dev`, the gateway can use the internal DNS applier
  for the node family to converge `*.{node_new.tld}` to the node's WireGuard
  address without exposing a public resolver.
- For requested `app-prod`, `node_new.name`, `node_new.roles`,
  `node_new.host`, and `node_new.user` can be resolved, and the target host is
  reachable over SSH as `node_new.user`.
- For role requests that include `database` alongside an app role, the shared
  host-provisioning preconditions for that app role apply.
- For role requests that include `database` alone, no SSH/bootstrap-only inputs
  are required.
- `s3` and `websocket` role requests are reserved but fail before side effects
  until their implementation todos land.
- `metrics` role requests provision or adopt the target host, then create the
  role assignment and converge metrics process/proxy/tool runtime.
- For host-provisioned workload requests, the target host platform is supported
  for the requested role set.
- For `--template=gateway`, `node_new.host` can be resolved and is compatible
  with the requested gateway identity before convergence or adoption begins.
- For `--template=gateway` when adoption is used, `node_new.user` can be
  resolved, the target host is reachable over SSH as `node_new.user`, and
  the target host platform is supported for the gateway role.

Evaluate each path eligibility rule as soon as the fields needed for that rule
are known. For example, inputs that are forbidden for client provisioning fail
as soon as the no-role identity and the forbidden supplied input are known,
before prompting for unrelated later input. In interactive input mode, a
correctable path blocker shows a validation message at the current corrective
prompt so the operator can change course or cancel. All path eligibility must
complete before side effects that the gateway owns begin.

## Allowed Paths

| Requested path | Behavior |
| --- | --- |
| `--template=gateway` | Converge the existing gateway node record. Missing gateway-row materialization is outside this command path. |
| omitted `--template`, `--operator`, and `--roles` | Enroll a client identity with no roles by minting a WireGuard peer and active node record. |
| `app-dev` role set | Provision or adopt an app-dev node over SSH, then create the role assignment. |
| `app-prod` role set | Provision or adopt an app-prod node over SSH, then create the role assignment. |
| `database` role set | Provision a private Ubuntu database node over SSH, configure WireGuard, then create the active database role assignment and Docker tool baseline. |
| `agent` role set | Provision or adopt an agent node over SSH, then create the role assignment with `tld`. |
| `websocket` role set | Reserved stable input surface; returns `role_not_implemented` before side effects until the WebSocket todo lands. |
| `s3` role set | Reserved stable input surface; returns `role_not_implemented` before side effects until the S3 todo lands. |
| `metrics` role set | Provision or adopt a metrics node over SSH, then create the role assignment and converge metrics runtime intent. |
| explicit multi-role set | Provision or adopt one compatible host, then create each role assignment. Live combinations include `app-dev` + `database`, `app-prod` + `ingress`, and any non-agent live role set that also includes `metrics`. `websocket` and `s3` combinations are reserved. |

## Gateway Authority Rules

- The gateway is the source of truth for node records, node roles, WireGuard
  identity, and node access policy.
- The active WireGuard runtime for the gateway-coupled `vpn` role is `wg-easy`;
  the gateway host `wg-orbit` interface is a peer/client of that runtime and
  must not bind UDP `51820`.
- WireGuard address allocation must reserve every address already visible in
  gateway node records, gateway WireGuard peer records, wg-easy client state,
  saved wg-easy config files, and the live wg-easy `wg0` runtime. A missing or
  stale gateway row must not make a live VPN client address reusable.
- Gateway execution may write durable node state directly.
- Gateway execution may use SSH to provision app-hosting nodes.
- Gateway execution must not SSH to client identities created with no
  `--roles` values.

## Gateway convergence and adoption

For `--template=gateway`:

1. Resolve `node_new.name` and expand `--template=gateway`.
2. Resolve `node_new.host`. Gateway requests always require a host.
3. If the requested gateway is already provisioned, active, and compatible, do
   not reprovision. Report already-provisioned convergence through the selected
   output renderer.
4. If the gateway is compatible but drifted or incomplete after it is known to
   gateway configuration, report node-family drift and point to
   `doctor --family=node --restore`.
5. Do not reset or destructively reprovision an existing gateway from
   `node:new`.

Gateway-role missing-row materialization is intentionally not supported by the
clean rebuild. A gateway caller is recognized only from an active local gateway
node row, so a gateway without that identity cannot safely prove authority to
create itself through this command. First-gateway bootstrap is the supported
path for creating gateway configuration. Multi-gateway replacement, disaster
recovery, and reset flows require a separate explicit contract.

## Gateway Path Matrix

| Gateway state | `node_new.host` | SSH used? | Behavior |
| --- | --- | --- | --- |
| Existing active compatible gateway | Required | No by default | Converge idempotently and report already-provisioned convergence. The supplied host must be compatible with the existing gateway identity. |
| Compatible provisioned host not yet in gateway configuration | Required | No | Fail before side effects. First-gateway bootstrap or a future explicit recovery command must create missing gateway configuration. |
| Existing compatible but incomplete gateway | Required | No by default | Report node-family drift or incomplete provisioning and point to `doctor --family=node --restore`. |
| Existing incompatible gateway | Required | No | Fail before destructive changes. |

`--json` only selects the JSON renderer and non-interactive input mode. It never
changes the matrix and never authorizes reset or destructive reprovisioning.

## Client Enrollment

For omitted `--template`, `--operator`, and `--roles`:

1. Resolve `node_new.name` with an empty role set.
2. Apply the forbidden-input rules for a no-role identity, including
   SSH/bootstrap-only inputs.
3. Mint a WireGuard peer.
4. Create or converge the active client row with matching `wg_ip`.
5. Return the WireGuard configuration and next-step instructions.

## App-Role Provisioning

Nodes must be gateway-provisioned or gateway-adopted over SSH. The gateway
does not return a detached app-role WireGuard configuration for manual app-role
installation, because nodes require gateway-applied role bootstrap:
minimum runtime readiness, node identity readiness, narrow event-hook readiness,
and development TLD mapping when applicable. Managed firewall configuration and
drift belong to the `firewall_rule` family after the node exists.

For live role sets that include `app-dev`, `app-prod`, and compatible explicit
role sets that include those host-provisioned roles:

1. Resolve `node_new.name`, `node_new.host`, and `node_new.user`.
2. Derive the internal app lane from the requested role set:
   - `app-dev` maps to the development lane;
   - `app-prod` maps to the production lane.
3. When the derived lane is development, resolve `node_new.tld`.
4. Verify the target host is reachable over SSH.
5. Verify the target host platform is supported for every requested role.
6. Install or converge the Orbit runtime.
7. Mint or verify WireGuard identity.
8. Register node configuration, including `nodes.tld` for development nodes.
9. Configure the node's local TLD default for development nodes.
12. Use the internal DNS applier for the node family to create or converge
    the development DNS mapping that the gateway owns for `*.{node_new.tld}` to
    the node's WireGuard address.
13. Verify node readiness.

The development DNS configuration model that the gateway owns is derived from the
active development app-role row, not from a public DNS command record. The
applier must write only resolver artifacts that Orbit manages on the active
`vpn` role runtime, bind them to the Orbit/WireGuard-reachable resolver
surface, and avoid public open-resolver exposure. In v1 that runtime is
gateway-coupled. If the node row is written but development DNS
convergence fails, `node:new` reports partial provisioning with a node-family
drift handoff to `doctor --family=node --restore`.

Compatible app-role adoption may use existing gateway configuration that is
not active only when the adoption flow can prove the registry peer material
against live WireGuard reality. Adopting a missing peer on an active node may attach
unowned live WireGuard reality only when the adoption flow proves the
selected node name, role, supported platform, live interface public key, and
WireGuard address through a bounded read of non-secret identity artifacts.
Adoption of an unknown host still requires a separate materialization path before
`node:new` can safely attach unowned live reality to gateway configuration.
Without that proof, `node:new` reports incomplete provisioning or node drift
and points to `doctor --family=node --restore` or
`doctor --family=node --adopt`.

### App Unknown-Host Adoption Materialization

When a gateway app-hosting path is invoked and no gateway node record exists
for `node_new.name`, the command may adopt an already-provisioned app host
instead of provisioning it from scratch only when all of these rules pass
before any durable write:

1. Build an in-memory candidate from the explicit request:
   `node_new.name`, the requested `app-dev` or `app-prod` role assignment,
   node identity such as `node_new.tld`, plus `node_new.host`, `node_new.user`,
   default runtime user `orbit`, and default Orbit path `/home/orbit/orbit`.
2. Read the bounded node identity artifact from the candidate host. This read
   may use `node_new.user` as the transport credential but must not persist
   a node row before proof succeeds.
3. Require the artifact to report the requested node name, role `app`,
   active status, a supported platform, a non-empty WireGuard address, and a
   non-empty live interface public key.
4. Require live gateway WireGuard reality to contain that interface public key
   with exactly one allowed address, and that address must equal the artifact
   WireGuard address.
5. Validate app-specific gateway configuration before materialization:
   node TLDs must be valid and unassigned for every path, and the requested
   host, role assignment, and role settings must
   not collide with an incompatible active node record.
6. Materialize the gateway row and peer together: create the active app-role row
   from the request plus artifact platform and WireGuard address, then attach a
   gateway `wireguard_peers` row using the proven public key and allowed IPs.
   The private key remains empty because adoption never reads private key
   material from nodes.
7. For development nodes, converge the derived gateway-owned development
   DNS mapping through the same node-family applier used by provisioning.
8. Run the same node-family adoption/readiness checks used for selected
   app-role adoption. If runtime readiness or other node-owned bootstrap facts
   cannot be verified, fail with `node.provisioning_incomplete` and include the
   adoption results.

If any proof is absent, ambiguous, or incompatible, `node:new` must fall back to
normal provisioning only when it has not observed a compatible existing Orbit
identity. It must not overwrite a proven but incompatible host.

## Failure Semantics

- Incompatible existing node records fail before destructive changes.
- Missing gateway `node_new.host` is handled by the selected input mode before
  side effects.
- Gateway reset and destructive reprovisioning are outside `node:new`.
- Compatible but drifted or incomplete gateways are reported as node-family
  drift for `doctor --family=node --restore`.
- Client enrollment fails if WireGuard peer minting cannot return a peer
  address that matches the node record.
- Provisioning on a node with an app role reports partial provisioning when gateway
  configuration is written but node readiness verification fails.
- Missing host or mandatory node TLD is handled by the selected input
  mode before side effects, as defined in the canonical contract.
- Resolved node TLDs fail before side effects when they are invalid,
  already assigned to another active node, or already mapped to another
  WireGuard target.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Node/NodeWriteCommandTest.php` | CLI gateway-context `node:new` payload forwarding plus first-gateway bootstrap and gateway-bootstrap-unavailable handling. |
| `apps/gateway/tests/Feature/Http/Api/NodeStoreControllerTest.php` | Gateway-caller node creation authority, app-node provisioning/adoption, gateway-context execution, and validation envelopes. |
| `apps/gateway/tests/Feature/Http/Api/NodeStoreStreamControllerTest.php` | Gateway streamed node creation and SSE creation frames. |
