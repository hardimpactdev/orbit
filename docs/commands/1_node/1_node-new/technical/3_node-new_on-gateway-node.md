# Technical Contract: `node:new` Authorized For Gateway Callers

[Back to `node:new` technical contract.](1_node-new.md)

This page describes what the gateway authorizes for callers whose
authenticated node record has role `gateway`, including gateway-local CLI
execution.

**Prerequisites:**
- The gateway can read and write gateway-owned node configuration.
- The gateway can issue or verify WireGuard node identity material.

**Post-input path eligibility:**
- For `--role=control`, the gateway identity service can mint a WireGuard peer.
- For `--role=app`, `node_new.name`, `node_new.role`,
  `node_new.environment`, `node_new.host`, and `node_new.user` can be
  resolved, and the target host is reachable over SSH as `node_new.user`.
- For `--role=app --environment=development`, `node_new.tld` can be resolved,
  is unique in gateway node configuration, and is not already mapped to another
  gateway development DNS target.
- For `--role=app --environment=development`, the gateway can use the internal
  node-family development DNS applier to converge `*.{node_new.tld}` to the
  app node's WireGuard address without exposing a public resolver.
- For `--role=app`, the target host platform is supported for the app role.
- For `--role=gateway`, `node_new.host` can be resolved and is compatible with
  the requested gateway identity before convergence or adoption begins.
- For `--role=gateway` when adoption is used, `node_new.user` can be
  resolved, the target host is reachable over SSH as `node_new.user`, and
  the target host platform is supported for the gateway role.

Evaluate each path eligibility rule as soon as the fields needed for that rule
are known. For example, forbidden control-node provisioning inputs fail as soon
as `node_new.role=control` and the forbidden supplied input are known, before
prompting for unrelated later input. In interactive input mode, a correctable
path blocker shows a validation message at the current corrective prompt so the
operator can change course or cancel. All path eligibility must complete before
gateway-owned side effects begin.

## Allowed Paths

| Requested role | Behavior |
| --- | --- |
| `gateway` | Converge the existing gateway node record. Missing gateway-row materialization is outside this command path. |
| `app` | Provision or adopt an app node over SSH. No enrollment-only path. |
| `control` | Enroll a control node by minting a WireGuard peer and active node record. |

## Gateway Authority Rules

- The gateway is the source of truth for node records, node roles, WireGuard
  identity, and node access policy.
- Gateway execution may write durable node state directly.
- Gateway execution may use SSH to provision app nodes.
- Gateway execution must not SSH to control nodes for `--role=control`.

## Gateway Convergence And Adoption

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

## Control-Node Enrollment

For `--role=control`:

1. Resolve `node_new.name` and `node_new.role`.
2. Apply the canonical forbidden-input rules for requested role `control`,
   including SSH/bootstrap-only inputs.
3. Mint a WireGuard peer.
4. Create or converge the active control-node row with matching `wg_ip`.
5. Return the WireGuard configuration and next-step instructions.

## App-Node Provisioning

App nodes must be gateway-provisioned or gateway-adopted over SSH. The gateway
does not return a detached app-node WireGuard configuration for manual app-node
installation, because app nodes require gateway-applied role bootstrap:
minimum runtime readiness, node identity readiness, narrow event-hook readiness,
and development TLD mapping when applicable. Managed firewall configuration and
drift belong to the `firewall_rule` family after the node exists.

For `--role=app`:

1. Resolve `node_new.name`, `node_new.environment`, `node_new.host`, and
   `node_new.user`.
2. When `node_new.environment` is `development`, resolve `node_new.tld`.
3. Verify the target host is reachable over SSH.
4. Verify the target host platform is supported for the app role.
5. Install or converge the Orbit runtime.
6. Mint or verify WireGuard identity.
7. Register node configuration, including `nodes.tld` for development app nodes.
8. Configure the app node's local TLD default for development app nodes.
9. Use the internal node-family development DNS applier to create or converge
   the gateway-owned mapping `*.{node_new.tld}` to the app node's WireGuard
   address.
10. Verify node readiness.

The gateway-owned development DNS configuration model is derived from the
active development app-node row, not from a public DNS command record. The
applier must write only gateway-local Orbit-managed resolver artifacts, bind
them to the Orbit/WireGuard-reachable resolver surface, and avoid public
open-resolver exposure. If the node row is written but development DNS
convergence fails, `node:new` reports partial provisioning with a node-family
drift handoff to `doctor --family=node --restore`.

Compatible app-node adoption may use existing non-active gateway configuration
only when node-family adoption can prove the registry peer material against
live WireGuard reality. Active app-node missing-peer adoption may attach
unowned live WireGuard reality only when node-family adoption proves the
selected node name, role, supported platform, live interface public key, and
WireGuard address through a bounded non-secret identity artifact read.
Unknown-host adoption still requires a separate materialization path before
`node:new` can safely attach unowned live reality to gateway configuration.
Without that proof, `node:new` reports incomplete provisioning or node drift
and points to `doctor --family=node --restore` or
`doctor --family=node --adopt`.

### App Unknown-Host Adoption Materialization

When `node:new --role=app` is invoked on the gateway and no gateway node record
exists for `node_new.name`, the command may adopt an already-provisioned app
host instead of provisioning it from scratch only when all of these rules pass
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
   development TLDs must be valid and unassigned, production app nodes must not
   carry a TLD, and the requested host/environment/TLD must not collide with an
   incompatible active node record.
6. Materialize the gateway row and peer together: create the active app-node row
   from the request plus artifact platform and WireGuard address, then attach a
   gateway `wireguard_peers` row using the proven public key and allowed IPs.
   The private key remains empty because adoption never reads private key
   material from nodes.
7. For development app nodes, converge the derived gateway-owned development
   DNS mapping through the same node-family applier used by provisioning.
8. Run the same node-family adoption/readiness checks used for selected
   app-node adoption. If runtime readiness or other node-owned bootstrap facts
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
- Control-node enrollment fails if WireGuard peer minting cannot return a peer
  address that matches the node record.
- App-node provisioning reports partial provisioning if gateway configuration is
  written but node readiness verification fails.
- Missing app-node host or development TLD is handled by the selected input
  mode before side effects, as defined in the canonical contract.
- Resolved development TLDs fail before side effects when they are invalid,
  already assigned to another active node, or already mapped to another
  WireGuard target.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/NodeNewCommandTest.php` | Primary owner for gateway-caller behavior: active local gateway identity requirement, gateway post-input path eligibility, gateway path matrix behavior, gateway `node_new.host` required for every gateway request, already-provisioned gateway convergence without reprovisioning, missing gateway-row materialization being outside `node:new`, compatible drift/incomplete-gateway handoff to `doctor --family=node --restore`, reset/destructive reprovisioning being outside `node:new`, control-node enrollment without SSH prompts or SSH side effects, canonical forbidden-input behavior for control-node enrollment, app-node provisioning over SSH, development app-node TLD persistence, gateway TLD mapping creation, compatible app-node adoption, and incompatible node-record failures before side effects. Renderer tests own exact human prose and JSON envelope shape for these outcomes. |
| `tests/E2E/NodeNewDevelopmentAppTest.php` | Real-node smoke coverage for gateway-owned development app-node provisioning and development TLD mapping. |
| `tests/E2E/NodeNewProductionAppTest.php` | Real-node smoke coverage for gateway-owned production app-node provisioning without development TLD mapping. |
