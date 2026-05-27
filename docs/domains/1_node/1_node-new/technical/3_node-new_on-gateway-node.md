# Technical Contract: `node:new` Authorized For Gateway Callers

[Back to `node:new` technical contract.](1_node-new.md)

This page describes what the gateway authorizes for callers whose
authenticated node record has role `gateway`, including gateway-local CLI
execution.

**Prerequisites:**
- The gateway can read and write gateway-owned node configuration.
- The gateway can issue or verify WireGuard node identity material.

**Post-input path eligibility:**
- For omitted `--role` or compatibility `--role=control`, the gateway identity
  service can mint a WireGuard peer.
- For canonical `--role=app-development`, `node_new.name`, `node_new.role`,
  `node_new.host`, and `node_new.user` can be resolved, the target host is
  reachable over SSH as `node_new.user`, and `node_new.tld` can be resolved,
  is unique in gateway node configuration, and is not already mapped to another
  gateway development DNS target.
- For canonical `--role=app-development`, the gateway can use the internal DNS
  applier for the node family to converge `*.{node_new.tld}` to the node's
  WireGuard address without exposing a public resolver.
- For canonical `--role=app-production`, `node_new.name`, `node_new.role`,
  `node_new.host`, and `node_new.user` can be resolved, and the target host is
  reachable over SSH as `node_new.user`.
- For canonical role requests that include `database` alongside an app
  role, the shared host-provisioning preconditions for that app role apply.
- For canonical role requests that include `database` alone, no
  SSH/bootstrap-only inputs are required.
- For canonical `--role=s3`, `node_new.name`, `node_new.role`,
  `node_new.host`, `node_new.user`, and `node_new.s3_data_path` can be
  resolved, the target host is reachable over SSH as `node_new.user`, and the
  data path is absolute.
- For compatibility `--role=app`, `node_new.name`, `node_new.role`,
  `node_new.environment`, `node_new.host`, and `node_new.user` can be
  resolved, and the target host is reachable over SSH as `node_new.user`.
- For compatibility `--role=app --environment=development`, `node_new.tld` can
  be resolved, is unique in gateway node configuration, and is not already
  mapped to another gateway development DNS target.
- For compatibility `--role=app --environment=development`, the gateway can use
  the internal DNS applier for the node family to converge `*.{node_new.tld}` to
  the node's WireGuard address without exposing a public resolver.
- For canonical or compatibility host-provisioned workload requests, the target
  host platform is supported for the requested role set.
- For `--role=gateway`, `node_new.host` can be resolved and is compatible with
  the requested gateway identity before convergence or adoption begins.
- For `--role=gateway` when adoption is used, `node_new.user` can be
  resolved, the target host is reachable over SSH as `node_new.user`, and
  the target host platform is supported for the gateway role.

Evaluate each path eligibility rule as soon as the fields needed for that rule
are known. For example, inputs that are forbidden for client provisioning fail as soon
as `node_new.role=control` and the forbidden supplied input are known, before
prompting for unrelated later input. In interactive input mode, a correctable
path blocker shows a validation message at the current corrective prompt so the
operator can change course or cancel. All path eligibility must complete before
side effects that the gateway owns begin.

## Allowed Paths

| Requested role | Behavior |
| --- | --- |
| `gateway` | Converge the existing gateway node record. Missing gateway-row materialization is outside this command path. |
| omitted `--role` | Enroll a client identity with no roles by minting a WireGuard peer and active node record. |
| `control` | Compatibility alias for the no-role client enrollment path. |
| `app-development` | Provision or adopt an app-development node over SSH, then create the role assignment. |
| `app-production` | Provision or adopt an app-production node over SSH, then create the role assignment. |
| `database` | Create the base node identity plus an active database role assignment. When requested alone, no SSH provisioning path runs. |
| `websocket` | Provision or adopt a private websocket node over SSH, then create the role assignment with `redis_node_id`. |
| `s3` | Provision or adopt a private S3 node over SSH, then create the role assignment with `data_path`. |
| repeated roles | Provision or adopt one compatible host for the requested role set, then create each role assignment. Supported initial combinations are `app-development` + `database`, `app-development` + `websocket`, `app-development` + `s3`, `database` + `websocket`, `database` + `s3`, `websocket` + `s3`, `app-development` + `database` + `websocket`, `app-development` + `database` + `s3`, `app-development` + `websocket` + `s3`, `database` + `websocket` + `s3`, `app-development` + `database` + `websocket` + `s3`, and `app-production` + `ingress`. |
| `app` | Compatibility path. Provision or adopt a node with an app role over SSH using `node_new.environment`, then create the mapped role assignment. |

## Gateway Authority Rules

- The gateway is the source of truth for node records, node roles, WireGuard
  identity, and node access policy.
- The active WireGuard runtime for the gateway-coupled `vpn` role is `wg-easy`;
  the gateway host `wg-orbit` interface is a peer/client of that runtime and
  must not bind UDP `51820`.
- Gateway execution may write durable node state directly.
- Gateway execution may use SSH to provision app-hosting nodes.
- Gateway execution must not SSH to client identities for omitted
  `--role` or compatibility `--role=control`.

## Gateway convergence and adoption

For `--role=gateway`:

1. Resolve `node_new.name` and `node_new.role`.
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

## Joined/Client Enrollment

For omitted `--role` or compatibility `--role=control`:

1. Resolve `node_new.name` and `node_new.role`.
2. Apply the canonical forbidden-input rules for a no-role identity,
   including SSH/bootstrap-only inputs.
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

For canonical `--role=app-development`, `--role=app-production`,
`--role=websocket`, `--role=s3`, compatible repeated role sets that include those
host-provisioned roles, and compatibility `--role=app`:

1. Resolve `node_new.name`, `node_new.host`, and `node_new.user`.
2. For canonical app-hosting roles, derive the internal app environment from
   the requested role set:
   - `app-development` maps to internal environment `development`;
   - `app-production` maps to internal environment `production`.
3. For compatibility `--role=app`, resolve `node_new.environment` from input.
4. When the derived or compatibility environment is `development`, resolve
   `node_new.tld`.
5. When the role set includes `websocket`, resolve `node_new.redis_node` and
   verify that it references an active `database` role node with Redis expected
   or installed.
6. When the role set includes `s3`, resolve `node_new.s3_data_path` with the
   default `/srv/orbit/s3/data` when omitted and verify that it is absolute.
7. Verify the target host is reachable over SSH.
8. Verify the target host platform is supported for every requested role.
9. Install or converge the Orbit runtime.
10. Mint or verify WireGuard identity.
11. Register node configuration, including `nodes.tld` for development nodes.
12. Configure the node's local TLD default for development nodes.
13. Use the internal DNS applier for the node family to create or converge
   the development DNS mapping that the gateway owns for `*.{node_new.tld}` to the node's WireGuard
   address.
14. Verify node readiness.

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
Adoption of an unknown host requires a separate materialization path before
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
   `node_new.name`, role `app`, `node_new.environment`, `node_new.tld`,
   `node_new.host`, `node_new.user`, default runtime user `orbit`, and
   default Orbit path `/home/orbit/orbit`.
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
   development TLDs must be valid and unassigned, production nodes must not
   carry a TLD, and the requested host/environment/TLD must not collide with an
   incompatible active node record.
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
- Missing app-role host or development TLD is handled by the selected input
  mode before side effects, as defined in the canonical contract.
- Resolved development TLDs fail before side effects when they are invalid,
  already assigned to another active node, or already mapped to another
  WireGuard target.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Commands/NodeNewCommandTest.php` | Gateway-caller behavior (see breakdown below). |
| `apps/gateway/tests/E2E/IncusTopologyBuilderTest.php` | Superset provisioning gate for real gateway, app-dev, app-prod, and agent `node:new` convergence. |

`NodeNewCommandTest.php` covers:

- active local gateway identity requirement
- post-input path eligibility and path matrix behavior
- `node_new.host` required for every gateway request
- already-provisioned convergence without reprovisioning
- missing gateway-row materialization outside `node:new`
- compatible drift and incomplete-gateway handoff to `doctor --family=node --restore`
- reset outside `node:new`
- client enrollment without SSH, including forbidden-input behavior
- app-role provisioning over SSH
- development TLD persistence and TLD mapping creation
- compatible adoption
- incompatible record failures before side effects

Renderer tests own exact output shape.
