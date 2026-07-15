# Technical Contract: `node:new` On The Gateway

[Back to `node:new` technical contract.](1_node-new.md)

This page describes the gateway-owned authority and orchestration for
`node:new`. Configured clients call this surface over HTTPS through WireGuard.
The gateway owns fleet identity and convergence, but it never uses SSH to reach
a workload target.

**Prerequisites:**

- The gateway can read and write gateway-owned node configuration.
- The gateway can issue WireGuard node identity material and configure the
  active gateway-coupled VPN runtime.
- The authenticated caller holds `node:new` on the active gateway.
- A host-provisioned request comes from a configured client that can perform
  the client-to-target SSH bootstrap.

## Allowed Paths

| Requested path | Behavior |
| --- | --- |
| `--template=gateway` | Converge the existing gateway node record. Missing gateway-row materialization is outside this command path. First-gateway creation uses the separate client bootstrap flow. |
| omitted `--template`, `--operator`, and `--roles` | Enroll a client identity by minting a WireGuard peer and active node record. No target SSH is involved. |
| `app-dev`, `app-prod`, `database`, `agent`, `ingress`, `metrics`, `analytics`, or compatible multi-role set | Prepare a pending managed workload bootstrap, let the initiating client install the bootstrap bundle over its local SSH connection, then complete role and runtime convergence through Agent push. |
| `websocket` or `s3` role set | Reserved stable input surface; returns the documented not-implemented error before side effects until its implementation lands. |

## Gateway Authority Rules

- The gateway is the source of truth for node records, node roles, WireGuard
  identity, node access policy, bootstrap state, and final operation history.
- The active WireGuard runtime for the gateway-coupled `vpn` role is `wg-easy`;
  the gateway host `wg-orbit` interface is a peer/client of that runtime and
  must not bind UDP `51820`.
- WireGuard address allocation reserves every address already visible in
  gateway node records, gateway WireGuard peer records, wg-easy client state,
  saved wg-easy config files, and the live wg-easy `wg0` runtime. A missing or
  stale gateway row must not make a live VPN client address reusable.
- The gateway may write durable fleet state directly and may call a prepared
  target only through its WireGuard-bound Agent listener.
- The gateway must not construct a target SSH command, inspect a target SSH
  agent, receive a caller private key, or expose a public pre-WireGuard
  enrollment endpoint.
- The secret bootstrap bundle appears only in the authenticated prepare
  response. Durable bootstrap state and operation results must not contain the
  rendered script, WireGuard private key, Agent config, or Agent trust material.

## Gateway Convergence

For `--template=gateway`:

1. Resolve `node_new.name`, `node_new.host`, and the gateway template.
2. If the requested gateway is already provisioned, active, and compatible,
   report idempotent convergence without reprovisioning.
3. If it is compatible but drifted or incomplete, report node-family drift and
   point to `doctor --family=node --restore`.
4. Do not reset or destructively reprovision an existing gateway from
   `node:new`.

First-gateway bootstrap is the supported path for creating gateway
configuration before a gateway API exists. Multi-gateway replacement,
disaster recovery, and reset require a separate explicit contract.

## Client Enrollment

For omitted `--template`, `--operator`, and `--roles`:

1. Resolve `node_new.name` with an empty role set.
2. Reject workload and SSH/bootstrap-only inputs.
3. Mint a WireGuard peer.
4. Create or converge the active client row with the matching WireGuard
   address.
5. Return the WireGuard configuration and next-step instructions.

## Managed Workload Bootstrap

Host-provisioned workload creation is a two-phase gateway operation around one
client-local SSH action.

### Prepare

1. Validate the node name, explicit TLD, host metadata, client-observed Ubuntu
   platform and CPU architecture, requested role set, role-specific settings,
   and caller authorization before side effects.
2. Resolve a resume lookup before SSH. A matching completed bootstrap or a
   pending bootstrap whose Agent is already reachable returns its existing
   identifier without a bundle; a pending bootstrap whose Agent is not ready
   requires client preflight and prepare. The lookup is bound to both the
   initiating node and the normalized request.
3. If a compatible pending prepare already exists for the same request and
   initiating node, reuse it. An active or incompatible identity fails without
   destructive changes.
4. Under a gateway-wide reservation lock, reserve the WireGuard address against
   all gateway, peer, saved, and live VPN reservations. The node table also
   enforces a unique WireGuard address; a lost unique-address race retries the
   locked allocation with current state.
5. Atomically write the node with status `provisioning`, persist non-secret
   resumable request state tied to the initiating node, and create the
   WireGuard peer. If any database write or key-generation step fails, none of
   those three records remains. Then configure the gateway VPN runtime.
6. Resolve digest-verified CLI and Agent artifacts from the current release
   manifest and render a node-specific bootstrap bundle containing:
   - creation of the managed Orbit runtime user and sudo contract;
   - the target WireGuard configuration;
   - the Orbit CLI artifact URL and checksum;
   - the Orbit Agent artifact URL and checksum;
   - the Agent configuration and gateway CA;
   - systemd units that start WireGuard before the Agent and bind Agent only to
     the reserved WireGuard address.
7. Return the bootstrap identifier, target host metadata, reserved WireGuard
   address, and secret bundle to the initiating client. Do not store the bundle
   in the operation journal or activity log.

The target may reach its configured operating-system package repositories and
release artifact storage before WireGuard is available. It does not need to
reach the gateway API or initiating client. The only pre-WireGuard target
requirements are inbound SSH from the initiating client, outbound access to
those package and artifact sources, and outbound WireGuard UDP to the gateway
endpoint.

### Complete

1. Authenticate the caller again and require it to match the bootstrap's
   initiating node before queuing an operation run, writing activity, or
   emitting progress.
2. Serialize completion by bootstrap identifier, then refresh and require the
   pending node and request state to remain compatible. An overlapping caller
   waits for the current completion and returns the resulting active state.
3. Wait for WireGuard reachability and for the Agent command endpoint at the
   reserved WireGuard address to return its readiness status.
4. Create and converge initial role assignments through typed Agent-push
   internal commands.
5. Apply the normal tool, runtime, development DNS, grant, and full node
   security baselines through gateway-local work or Agent push as owned by each
   family. The security baseline locks down the Orbit home, binds hardened SSH
   to WireGuard and loopback, locks root and removes root authorized keys,
   installs sysctl and unattended-upgrade policy, and denies public SSH.
6. Atomically mark the node active and bootstrap complete only after required
   readiness and convergence succeed. An interruption before that commit
   leaves both pending for idempotent completion retry.
7. Emit one `node.created` activity only for the request that durably wins the
   pending-to-completed transition. Denied, failed, overlapping, and
   already-completed retries do not emit or duplicate that activity.

The completion phase never falls back to SSH. An Agent readiness or convergence
failure leaves inspectable pending/error state for a safe retry.

## Retry and failure semantics

- A compatible repeated prepare request from the same initiating node reuses
  the pending node, peer, address, and bootstrap identifier and renders a fresh
  equivalent bundle from the persisted identity state.
- Repeated or overlapping completion calls return the same active result.
  Completion is locked by bootstrap identifier, which prevents destructive
  setup from running twice. The durable pending-to-completed transition
  identifies the sole activity winner.
- A repeated command performs resume lookup before SSH, so Agent-ready pending
  and completed bootstraps remain recoverable after public SSH is denied.
- Interrupted reservation cannot leave a node without its peer/bootstrap
  record, and interrupted terminal commit cannot leave an active node with a
  pending bootstrap.
- Incompatible node identity, host, TLD, roles, settings, or initiating node
  fails before mutation.
- Client-local SSH, host-key, and installer failures are reported by the CLI;
  completion is not called.
- WireGuard or Agent readiness failures return
  `node.provisioning_incomplete` with the exact failed step and do not select
  SSH.
- There is no manual no-SSH or public enrollment fallback.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Node/NodeWriteCommandTest.php` | CLI input and authenticated prepare payload mapping plus first-gateway handling. |
| `apps/cli/tests/Feature/Commands/Node/NodeNewBootstrapCommandTest.php` | Client-local platform/architecture preflight, template routing, SSH bundle streaming, failure behavior, and completion ordering. |
| `apps/gateway/tests/Feature/Http/Api/NodeBootstrapControllerTest.php` | Resume, prepare, and completion authorization; unique-address collision retry; serialized overlapping completion; atomic terminal retry; success-only activity; readiness; and no gateway target SSH. |
| `apps/gateway/tests/Feature/Services/Nodes/NodeBootstrapBundleBuilderTest.php` | Minimal idempotent WireGuard/CLI/Agent bootstrap bundle and secret handling. |
