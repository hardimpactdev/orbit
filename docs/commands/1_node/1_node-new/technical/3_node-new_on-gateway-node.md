# Technical Contract: `node:new` On A Gateway Node

[Back to `node:new` technical contract.](1_node-new.md)

This page describes caller-role behavior when `orbit node:new` is invoked on the
gateway node.

**Prerequisites:**
- `general.local_node_role` is explicitly set to `gateway`.
- The caller role has been resolved before command inputs are read or
  interactive prompts are rendered.
- The gateway can read and write gateway-owned node intent.
- The gateway can issue or verify WireGuard node identity material.

**Post-input path eligibility:**
- For `--role=control`, the gateway identity service can mint a WireGuard peer.
- For `--role=app`, `node_new.name`, `node_new.role`,
  `node_new.environment`, `node_new.host`, and `node_new.ssh_user` can be
  resolved, and the target host is reachable over SSH as `node_new.ssh_user`.
- For `--role=app --environment=development`, `node_new.tld` can be resolved,
  is unique in gateway node intent, and is not already mapped to another gateway
  development DNS target.
- For `--role=app`, the target host platform is supported for the app role.
- For `--role=gateway`, `node_new.host` can be resolved and is compatible with
  the requested gateway identity before convergence or adoption begins.
- For `--role=gateway` when adoption is used, `node_new.ssh_user` can be
  resolved, the target host is reachable over SSH as `node_new.ssh_user`, and
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
| `gateway` | Converge the existing gateway node record or adopt compatible gateway intent. |
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
3. Resolve `node_new.ssh_user` when the target host must be reached over SSH
   for adoption.
4. If the requested gateway is already provisioned, active, and compatible, do
   not reprovision. Report already-provisioned convergence through the selected
   output renderer.
5. If the target host is already provisioned and compatible but not yet
   registered in gateway intent, adopt it.
6. If the gateway is compatible but drifted or incomplete after it is known to
   gateway intent, report node-family drift and point to
   `doctor --family=node --fix`.
7. Do not reset or destructively reprovision an existing gateway from
   `node:new`.

## Gateway Path Matrix

| Gateway state | `node_new.host` | SSH used? | Behavior |
| --- | --- | --- | --- |
| Existing active compatible gateway | Required | No by default | Converge idempotently and report already-provisioned convergence. The supplied host must be compatible with the existing gateway identity. |
| Compatible provisioned host not yet in gateway intent | Required | Yes | Adopt the target host into gateway intent. |
| Existing compatible but incomplete gateway | Required | No by default | Report node-family drift or incomplete provisioning and point to `doctor --family=node --fix`. |
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
installation, because app nodes require gateway-enacted role bootstrap:
minimum runtime readiness, node identity readiness, narrow event-hook readiness,
and development TLD mapping when applicable. Managed firewall intent and drift
belong to the `firewall_rule` family after the node exists.

For `--role=app`:

1. Resolve `node_new.name`, `node_new.environment`, `node_new.host`, and
   `node_new.ssh_user`.
2. When `node_new.environment` is `development`, resolve `node_new.tld`.
3. Verify the target host is reachable over SSH.
4. Verify the target host platform is supported for the app role.
5. Install or converge the Orbit runtime.
6. Mint or verify WireGuard identity.
7. Register node intent, including `nodes.tld` for development app nodes.
8. Configure the app node's local TLD default for development app nodes.
9. Create or converge the gateway-owned development DNS mapping
   `*.{node_new.tld} -> nodes.wg_ip`.
10. Verify node readiness.
11. Set `general.local_node_role=app` on the app host as the app-node
    bootstrap commit point.

Compatible app-node adoption may use existing non-active gateway intent only
when node-family adoption can prove the registry peer material against live
WireGuard reality. Active app-node missing-peer adoption may attach unowned live
WireGuard reality only when node-family adoption proves the selected node name,
role, local role setting, supported platform, live interface public key, and
WireGuard address through a bounded non-secret identity artifact read.
Unknown-host adoption still requires a separate materialization path before
`node:new` can safely attach unowned live reality to gateway intent. Without
that proof, `node:new` reports incomplete provisioning or node drift and points
to `doctor --family=node --fix` or `doctor --family=node --adopt`.

## Failure Semantics

- Incompatible existing node records fail before destructive changes.
- Missing gateway `node_new.host` is handled by the selected input mode before
  side effects.
- Gateway reset and destructive reprovisioning are outside `node:new`.
- Compatible but drifted or incomplete gateways are reported as node-family
  drift for `doctor --family=node --fix`.
- Control-node enrollment fails if WireGuard peer minting cannot return a peer
  address that matches the node record.
- App-node provisioning reports partial provisioning if gateway intent is
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
| `tests/Feature/Commands/Nodes/NodeNewOnGatewayNodeContractTest.php` | Primary owner for gateway-caller behavior: explicit `general.local_node_role=gateway` requirement, gateway post-input path eligibility, gateway path matrix behavior, gateway `node_new.host` required for every gateway request, already-provisioned gateway convergence without reprovisioning, compatible gateway adoption, compatible drift/incomplete-gateway handoff to `doctor --family=node --fix`, reset/destructive reprovisioning being outside `node:new`, control-node enrollment without SSH prompts or SSH side effects, canonical forbidden-input behavior for control-node enrollment, app-node provisioning over SSH, development app-node TLD persistence, app host local role setting written as `app` only after node identity and readiness are established, gateway TLD mapping creation, compatible app-node adoption, and incompatible node-record failures before side effects. Renderer tests own exact human prose and JSON envelope shape for these outcomes. |
| `tests/E2E/Ephemeral/NodeNewAppProvisioningTest.php` | Real-node smoke coverage for gateway-owned app-node provisioning, development TLD mapping, and compatible adoption over SSH. |
| `tests/E2E/Ephemeral/NodeNewControlEnrollmentTest.php` | Real-node smoke coverage for gateway-owned control-node enrollment, returned WireGuard config installation, and follow-up `gateway:add`. |
