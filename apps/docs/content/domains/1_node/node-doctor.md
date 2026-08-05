# Node Doctor

[Back to Node commands.](README.md)

`doctor --family=node` verifies whether gateway node records still match the
machine facts that make those records usable as Orbit nodes.

The node family owns these facts:

- gateway-owned node records, role assignments, and access policy: name,
  assignment status, platform-version identifier, host/endpoint metadata,
  WireGuard address, role-assignment settings, `node_access` grant integrity,
  and the scoped permission set stored on each grant;
- local caller identity: presented WireGuard identity, local gateway
  endpoint/trust config, and gateway-client endpoint and trust artifacts
  that the gateway manages on the node;
- node connectivity: CLI callers reach the gateway API, and the gateway
  reaches nodes through node command transport;
- node bootstrap artifacts: gateway service readiness, node minimum Orbit
  runtime, gateway-client endpoint and trust artifacts on the node, node identity
  artifacts, role bootstrap network policy, and WireGuard peers managed by the gateway;
- node-owned security baseline: canonical Orbit
  owner/runtime user, provisioning SSH exposure policy, sysctl baseline, and
  permissions for home directories set during bake;
- node update posture: managed Ubuntu server update readiness through a
  supported update driver, starting with Ubuntu `unattended-upgrades`;
- Orbit Agent readiness on Agent-eligible nodes: agent-push delivery
  posture, local process reachability, and privilege prompt capability when
  that lane exists;
- Agent intent integrity: `managed=true` is valid only as explicit intent for a
  roleless non-gateway operator; workload intent is derived from active roles,
  and installed-Agent expectation must not remain after the last workload role
  is removed unless the roleless node is explicitly managed;
- node identity and related defaults: every active node has a mandatory valid
  node-owned TLD; the node family projects a concrete DNS record for every
  active node and wildcard records for active development and agent roles,
  alongside `vpn` role settings and WireGuard runtime, local
  `node:default` preferences for `--self`, Orbit launcher/runtime readiness,
  at the node level.

Tools, firewall rules, projects, instances, workspaces, processes, proxy routes, schedules,
and deployments depend on node reachability, but their own artifacts are not
node probe facts.

Fresh managed workload provisioning has a setup phase that applies gateway
role and tool intent to the real node before the node becomes active. Node
doctor is the post-activation repair path: `doctor --restore` reuses the
same internal convergence service for overlapping safe setup repairs while
preserving the public family split between node-owned and tool-owned findings.

## Probe Layers

The node probe reads gateway node records and checks these layers:

1. **Registry configuration:** every selected active node record has a valid
   unique node-owned TLD, valid role assignments and assignment statuses, a
   platform-version identifier such as `ubuntu_24-04`, required host/endpoint
   metadata, and a WireGuard address.
   Eligibility checks use only active role assignments. Compatibility checks
   treat `active`, `pending`, and `error` assignments as unresolved conflicts
   and ignore `removing`.
2. **Access policy integrity:** every node access grant references active node
   records, and the stored permission set on each grant normalizes against the
   permission registry. Stale grant rows that point at missing or non-active
   nodes are node family drift, and grants that store unknown permission
   strings or redundant permissions are node family drift.
3. **Local caller identity:** for `--self`, a presented WireGuard identity
   resolves to exactly one active gateway-known node record. Pre-first-gateway-
   bootstrap callers without identity are accepted as the bootstrap path.
4. **Gateway API reachability:** client and node CLI callers reach the
   configured gateway API over HTTPS through WireGuard, trust the configured
   gateway certificate authority, and receive their node identity from
   `/api/me`.
5. **WireGuard identity:** each active node record has matching
   WireGuard peer material that the gateway manages, and the peer address equals the
   recorded WireGuard address.
6. **Platform reality:** the gateway reports its platform locally, Agent-eligible
   non-gateway nodes report it through Agent push, and the local client reports
   a supported macOS or Ubuntu identifier when `--self` can inspect it. Remote
   unmanaged client machines are verified through identity and gateway API
   reachability, not a node command transport.
7. **Node transport reachability:** gateway work executes gateway-locally and
   the gateway reaches Agent-eligible non-gateway nodes through Agent push.
   Unmanaged client identities are not node-transport targets for node doctor.
8. **Gateway service readiness:** the gateway node exposes the Orbit API and
   the gateway service required by CLI callers.
9. **Node-side bootstrap readiness:** nodes have the minimum Orbit runtime,
   Agent listener, and identity artifacts needed for the gateway to apply other
   state families through Agent push. Gateway-owned state is applied locally.
10. **Role bootstrap network policy:** gateway and nodes have the
    baseline network policy that the node owns for its role and environment. This
    verifies bootstrap reachability policy only, including that provisioning
    SSH is not publicly exposed after bootstrap and the Agent listener is
    reachable only over Orbit/WireGuard. Editable operator firewall rules belong to
    `firewall_rule`.
11. **Node security posture:** provisioned Linux nodes satisfy node-owned
   security checks. These issue keys use the `node.security.*` prefix and remain
   inside the `node` family; `security` is not a doctor family. The persisted
   `nodes.user` value is the Orbit owner/runtime user for node-local Agent work.
   Provisioned nodes default to `orbit`, but compatible existing nodes may
   intentionally use another owner user. Empty owner-user records are drift.
   The `node:new --user` option remains valid as a bootstrap-only SSH user and
   is not itself drift.
12. **Node update posture:** managed Ubuntu server nodes may expose
   `node.updates` posture when the operator selects the exact
   `--key=node.updates` filter. The update layer runs only when a registered
   update driver supports the selected target. Unsupported targets are silent:
   node doctor never creates `node.updates_not_applicable` or
   `node.updates_driver_unsupported` findings.
13. **Node DNS projection:** the shared `dnsmasq.d/10-node-records.conf`
    artifact is verified only on the DNS-serving host (gateway-coupled active
    `vpn` role), not on nodes that only contribute records. Targeted
    (`--node=gateway`) and broad (`--all`) scopes share this consumer gate:
    content is probed only when the live `orbit-dns` runtime mounts the
    managed projection directory, so unmounted host files cannot produce false
    positives. Concrete and wildcard mismatches still name their active source
    node in the issue. Orphan directives from deleted or renamed nodes are
    reported once on the active gateway projection anchor. Every active node
    with a valid TLD and WireGuard address has a concrete `orbit.{tld}` record;
    only active `app-dev` and `agent` nodes have wildcard and local-zone
    directives. Container, listener, forwarding, and client-DNS checks belong
    to the tool family. Local operator-machine resolver overrides remain the
    `dns:*` command surface, not node doctor.
14. **Role assignment readiness:** active role assignments have the settings
   their role requires, current assignment convergence state, and no baseline
   drift.

   For `app-dev`, the node has its mandatory valid node-owned `tld`, and the
   gateway maps `*.{tld}` to the node's WireGuard address in the node-owned DNS
   projection.

   For `agent`, the role consumes the node's mandatory valid node-owned `tld`.
   The gateway maps `*.{tld}` to the node's WireGuard address through the same
   DNS configuration model, and the node baseline includes `orbit-caddy`, the
   shared unprivileged `agent` runtime user, and any role-specific runtime
   containers the agent workload needs. The gateway runs `orbit-gateway` for
   the API and `orbit-scheduler` for schedule execution. Workload and agent
   nodes run the public Orbit CLI as gateway clients and run workloads in
   role-specific runtime containers.

   For `vpn`, assignments have valid `public_endpoint`, `wireguard_cidr`,
   `wireguard_port`, and `dns_ip` settings. The node baseline includes the
   WireGuard server runtime and requires the DNS tool capability; DNS base
   configuration and runtime diagnostics remain tool-family facts.

   For `app-prod`, the assignment has a valid `ingress_node_id`
   setting. The role baseline owns private backend readiness: `orbit-caddy`
   bound to the node's WireGuard address, FrankenPHP app containers, and Docker
   process runtime units.

   For `ingress`, assignments have no role settings in v1. The node
   baseline includes public production HTTP ingress, public `orbit-caddy` route
   artifacts, and forwarding readiness to the active `router`. Backend-pool
   selection belongs to the `router` role.

   For `websocket`, assignments have a valid `valkey_node_id` setting that
   references an active `database` role node with Valkey expected or installed.
   The node baseline owns Laravel Reverb in a Docker runtime container managed
   by Orbit, private backend certificate material, a container-wide internal
   listener published only on WireGuard, and router-facing readiness. The
   websocket role does not install or own Valkey.

   For `s3`, assignments have a valid absolute `data_path` setting, a supported
   platform, WireGuard identity, and role convergence status. The node family
   verifies the role assignment and the data path for that role only. SeaweedFS
   tool rows, service credentials, and capability inventory belong to the tool
   family. Its process row, runtime container, lifecycle, and logs belong to the
   process family. S3 service routes and backend pools belong to the proxy
   family.

   `database` and `gateway` assignments have no role settings in v1.
15. **Node-related defaults:** the issue catalog includes
   `node.local_default_invalid` for a missing or unauthorized local default.
   A dedicated default-preference probe that actively validates
   `node:default` under `--self` is not implemented as current doctor
   behavior; treat that probe as pending, not present tense.

Public IPv4/IPv6 metadata is not a probe fact. Node doctor does not detect,
compare, repair, or adopt public address metadata until a detection contract specific to the provider exists.

Protected Orbit Agent envelopes may surface OS privilege prompts when the
gateway submits protected local work through direct Agent push. Unsupported
privileged work fails explicitly; V1 has no separate Orbit approval UI or
pending/approve queue. Agent-push results belong in gateway
operation/activity history.

The SSH/bootstrap endpoint and gateway endpoint are operator-supplied
connectivity facts. Node doctor may validate retained provisioning metadata,
but it must not open an SSH session as a steady-state probe. It does not infer
public IPv4/IPv6 metadata from either endpoint.

## Role Assignment Status

Node doctor treats role assignment status as gateway desired-state metadata:

| Status | Doctor meaning |
| --- | --- |
| `pending` | The role is not yet converged. Doctor reports it as incomplete until the synchronous role mutation finishes or marks it `error`. |
| `active` | The role baseline is converged. Eligibility, compatibility, and role-dependent resource checks may use it. |
| `error` | The last synchronous convergence attempt failed. `doctor --family=node --restore` retries convergence after blockers are addressed. |
| `removing` | Cleanup is in progress or failed. The role is not eligible for new resources, and doctor can reevaluate cleanup blockers on a later restore. |

Eligibility checks only use active assignments. Compatibility checks treat
assignments in `active`, `pending`, or `error` as unresolved conflicts and
ignore assignments already in `removing`. Doctor still reports non-active
assignments that block the selected node's desired state.

## Role Removal

`node role:remove` blocks when dependents exist.
`node role:remove --force` removes Orbit-owned dependents and role-owned
configuration while preserving user data.
`node role:remove --force --purge-data` also deletes role-owned data for
resources whose command contract explicitly supports purging.

Doctor does not perform destructive role removal on its own. After the operator
addresses a removal blocker, `doctor --family=node --restore` reevaluates the
selected assignment and retries the assignment cleanup path when the assignment
is `removing`, or the baseline convergence path when the assignment is `error`.

## Node Issue Codes

Every code below is registered in the Doctor issue catalog owned by this
family, with an explicit public disposition (`genuine_drift`,
`blocked_inspection`, `invalid_intent`, or `runtime_incident`). Genuine drift
codes declare a restore action in the Fix Map and catalog; non-genuine
dispositions are never auto-repaired as if they were restorable drift. See the
global
[doctor technical contract](../11_operation/3_doctor/technical/1_doctor.md#issue-dispositions)
for disposition semantics.

Each code below identifies a specific kind of node-family drift that `doctor --family=node` may report.

| Code | Detected when |
| --- | --- |
| `node.record_incomplete` | A selected active node record lacks a mandatory valid node-owned TLD, uses reserved TLD `orbit`, or lacks a required platform-version identifier, host/endpoint metadata, or WireGuard address. |
| `node.role_assignment_missing` | A selected active node has no compatible active role assignment for the role implied by its registry record. |
| `node.role_assignment_invalid` | A persisted role assignment names an unknown role or otherwise cannot be validated as a real role row. |
| `node.role_conflict` | Active, pending, or error role assignments violate the compatibility matrix. Assignments already in `removing` are ignored. |
| `node.role_settings_invalid` | A role assignment's typed settings cannot be hydrated or contain unsupported role-local values. Node TLD is node-owned and never role-local. |
| `node.role_convergence_failed` | A role assignment is left in `error` after synchronous convergence failed. |
| `node.role_baseline_mismatch` | Active role-owned baseline artifacts do not match the role assignment's desired state. |
| `node.websocket.backend_cert_missing` | An active `websocket` role node is missing its backend certificate or key, or the certificate does not match the expected backend name. |
| `node.websocket.bind_public_interface` | The Reverb runtime for an active `websocket` role does not listen on the container-wide interface with its host port published only on the node's WireGuard address. |
| `node.managed_agent_intent_invalid` | `managed=true` is stored on a gateway or another role-bearing node even though managed intent is reserved for roleless operators. |
| `node.agent_expectation_stale` | Installed Agent expectation remains after the node has neither an active workload role nor explicit roleless managed intent. |
| `node.access_grant_invalid` | A node access grant references a missing or non-active consuming or serving node. |
| `node.access_permission_invalid` | A node access grant stores an unknown permission string or a permission set that does not normalize cleanly against the permission registry. |
| `node.identity_unresolved` | The caller presents no WireGuard identity or an identity that does not resolve to exactly one active node record. |
| `node.gateway_api_unreachable` | A client or node CLI caller cannot reach the configured gateway API over WireGuard. |
| `node.gateway_ca_mismatch` | The gateway API presents a certificate chain that does not match the configured gateway trust. |
| `node.wireguard_peer_missing` | An active node record has no matching gateway-managed WireGuard peer. |
| `node.wireguard_peer_extra` | Gateway-managed WireGuard state contains a peer that does not belong to an active node record. |
| `node.wireguard_address_mismatch` | A gateway-managed WireGuard peer address differs from the node record's WireGuard address. |
| `node.platform_unsupported` | A gateway or node reports an unsupported platform-version identifier. |
| `node.platform_record_mismatch` | Live platform detection differs from the node record's platform-version identifier. |
| `node.transport_unreachable` | Gateway-local execution is unavailable for the gateway target, or the gateway cannot reach an Agent-eligible non-gateway node through Agent push. |
| `node.gateway_runtime_unready` | The gateway node does not expose the Orbit API or required gateway service. |
| `node.runtime_missing` | A node lacks the minimum Orbit runtime required for gateway applying. Report-only; recovery returns to the initiating client bootstrap flow. |
| `node.dns_mapping_mismatch` | A source node's concrete or role-gated wildcard directives are missing or wrong, or the active gateway projection anchor finds an orphan directive for a deleted or renamed node. |
| `node.s3_data_path_invalid` | An active `s3` role assignment has a missing, relative, or otherwise invalid `data_path` setting. |
| `node.s3.wireguard_missing` | An active `s3` role node has a missing or empty WireGuard address. SeaweedFS requires a WireGuard address to bind its API endpoint. |
| `node.node_identity_artifact_missing` | A node is missing bootstrap identity material required to prove its node record. |
| `node.bootstrap_network_policy_mismatch` | A gateway or node's role bootstrap network policy is missing, unsafe, or inconsistent with its role assignments. |
| `node.security.runtime_user` | A persisted managed node record has no Orbit owner/runtime user, or that user is absent on the host. |
| `node.security.public_ssh_deny` | A provisioned Linux node does not deny public SSH exposure according to node-owned bootstrap policy. |
| `node.security.sysctl` | A provisioned Linux node is missing or diverges from the node-owned sysctl baseline. |
| `node.security.home_perms` | Managed home is weaker than owner `0700`-equivalent posture. Linux may keep managed Agent traversal ACL `u:agent:--x` (mask `--x`); broader ACLs or group/world access remain findings. |
| `node.security.posture_probe_failed` | The remote node security posture probe raised, returned non-success, or produced an empty/malformed payload, so posture drift is unverifiable for this run. |
| `node.updates_config_missing` | A supported update driver found that `unattended-upgrades` or required apt auto-upgrade config is absent. The issue object uses `key=node.updates` and this value as `code`. |
| `node.updates_config_mismatch` | A supported update driver found apt auto-upgrade config that differs from Orbit's expected policy. The issue object uses `key=node.updates` and this value as `code`. |
| `node.updates_dry_run_failed` | A supported update driver found that `sudo unattended-upgrade --dry-run` failed. The issue object uses `key=node.updates` and this value as `code`. |
| `node.updates_last_run_failed` | A supported update driver found recent unattended-upgrades evidence reporting a failed run. The issue object uses `key=node.updates` and this value as `code`. |
| `node.updates_reboot_required` | A supported update driver found `/var/run/reboot-required`. The issue object uses `key=node.updates` and this value as `code`. |
| `node.updates_unverifiable` | A supported update driver cannot inspect update posture. Unsupported targets are silent instead. The issue object uses `key=node.updates` and this value as `code`. |
| `node.local_default_invalid` | Catalogued issue for a missing or unauthorized local `node:default` preference. A dedicated default-preference probe under `--self` is pending, not current doctor behavior. |

`node.access_permission_invalid` and `node.wireguard_peer_extra` are not restore targets (`invalid_intent` / adopt-only respectively); doctor restore does not invent permission or peer intent. Use `node:permissions` for permission-set repair and `doctor --family=node --adopt` when peer attachment is the intended recovery.

## Node Fix Map

This table describes what `doctor --restore --family=node` does for each resolvable issue code.

| Code | `doctor --restore --family=node` behavior |
| --- | --- |
| `node.gateway_api_unreachable` | Restart or restore gateway service only when running on the gateway node; otherwise leave the issue for gateway-side repair. |
| `node.gateway_ca_mismatch` | Restore local gateway trust from gateway-owned trust material when the caller is authorized to receive it. |
| `node.wireguard_peer_missing` | Reserved for gateway-managed peer recreation; private key material is not read from nodes. Compatible live peer attachment belongs to `doctor --family=node --adopt`. |
| `node.wireguard_address_mismatch` | Rewrite gateway-managed peer material to the WireGuard address recorded on the active node record. |
| `node.access_grant_invalid` | Remove stale grant rows that reference missing or non-active nodes. |
| `node.role_convergence_failed` | Retry synchronous convergence for error role assignments on the selected node and leave an assignment in `error` again if the retry fails. |
| `node.role_baseline_mismatch` | Re-apply the baseline artifacts for the selected active role assignments through the shared convergence path. |
| `node.websocket.backend_cert_missing` | Re-apply the active `websocket` role baseline, then re-probe the backend certificate and keep the issue visible if drift remains. |
| `node.websocket.bind_public_interface` | Re-apply the active `websocket` role baseline, then re-probe Reverb's container bind and WireGuard-only Docker publication and keep the issue visible if drift remains. |
| `node.managed_agent_intent_invalid` | Clear the invalid `managed` flag; workload role intent remains derived from active roles. |
| `node.agent_expectation_stale` | Clear stale installed-Agent expectation metadata after Agent intent is absent. |
| `node.gateway_runtime_unready` | Restart or reinstall the gateway service artifacts required by Orbit API readiness. |
| `node.dns_mapping_mismatch` | Re-render only `dnsmasq.d/10-node-records.conf` from active node intent, atomically replace that artifact through the ownership-neutral materializer, and reload or restart DNS once. If the projection directory mount is not active, leave drift unresolved rather than reporting success. |
| `node.node_identity_artifact_missing` | Reinstall node identity material from the active node record. |
| `node.bootstrap_network_policy_mismatch` | Reapply the node-owned bootstrap network policy for the node's role assignments with rollback and reachability checks, preserving gateway-owned `firewall_rule` extras. |
| `node.security.public_ssh_deny` | Reapply the node-owned public provisioning-SSH deny policy gateway-locally or through Agent push while preserving user-owned firewall rules. |
| `node.security.sysctl` | Restore the managed sysctl baseline and reload sysctl. |
| `node.security.home_perms` | Through the authenticated node path, chmod only `/home/{nodes.user}` to `0700` after passwd-home and ownership checks. Runtime-user absence is report-only. Does not run against a correctly ACL-hardened Agent home (owner `0700` bits plus managed `u:agent:--x`), so restore cannot fight role-baseline ACL repair. |
| `node.updates` | For exact `--key=node.updates`, repair apt auto-upgrade config through `UnattendedUpgradesInstaller`, run `sudo unattended-upgrade`, re-probe, and report any remaining drift. Orbit never reboots automatically. |
`doctor --family=node --restore` does not handle `node.record_incomplete`,
`node.role_assignment_missing`, `node.role_assignment_invalid`, `node.role_conflict`,
`node.role_settings_invalid`,
`node.identity_unresolved`, `node.platform_unsupported`,
`node.platform_record_mismatch`, `node.transport_unreachable`,
`node.runtime_missing`, `node.security.runtime_user`,
`node.security.posture_probe_failed`,
`node.access_permission_invalid`, `node.wireguard_peer_extra`,
or `node.local_default_invalid`.

`node.runtime_missing` is report-only because the gateway never owns bootstrap
SSH credentials or a client-to-target SSH session. Resume or rerun `node:new`
from the initiating client so that client-owned bootstrap can reinstall the
minimum runtime and establish Agent readiness; then rerun doctor through the
normal gateway-to-Agent path.

`node.dns_mapping_mismatch` is not adoptable. DNS record projection is derived
from gateway node intent; observed resolver content cannot become node state.

`node.local_default_invalid` is reported only. `node:default` is an explicit
user action; doctor must not silently clear or replace those preferences under
`doctor --family=node --restore`.

`node.updates_reboot_required` is non-restorable drift. The restore path may
repair configuration and run the trusted backend, but a required reboot remains
visible until an operator explicitly reboots the server.

Node doctor never creates fleet membership, grants access, adds the `gateway`
role through role mutation, or edits public IPv4/IPv6 metadata. Those
changes remain explicit node commands such as `node:new`, `node:update`,
`node:grant`, `node:revoke`, and `node:remove`.

## Node Adopt Map

This table describes what `doctor --family=node --adopt` does for each adoptable issue code.

| Code | `doctor --family=node --adopt` behavior |
| --- | --- |
| `node.wireguard_peer_missing` | Attach a compatible live WireGuard peer. See conditions below. |
| `node.wireguard_peer_extra` | Attach the peer when the registry peer public key matches live WireGuard reality. See conditions below. |
| `node.wireguard_address_mismatch` | Update the node record's WireGuard address only when the peer proves the same node identity. |
| `node.platform_record_mismatch` | Update the node record's platform-version identifier only when live detection is supported and unambiguous. |

Conditions for `node.wireguard_peer_missing` adoption: the selected active
node has a non-secret identity artifact that matches gateway
configuration, and live WireGuard reality proves exactly one allowed address.
Private key material is not read or adopted.

Conditions for `node.wireguard_peer_extra` adoption: the selected scope names
a node identity that is already provisioned and compatible, the registry peer
public key is present in live WireGuard reality, and that live peer has
exactly one unambiguous allowed address.

`doctor --family=node --adopt` does not handle `node.runtime_missing`,
unselected hosts, unresolved caller identities, unknown WireGuard peers,
public IPv4/IPv6 metadata, security settings, evidence for SSH host keys, or
artifacts that belong to tools, firewall rules, projects, instances, workspaces,
processes, proxy routes, schedules, or deployments.

Node doctor never stores, probes, compares, restores, or adopts SSH host keys.
Host-key trust is client-owned bootstrap state; mismatches return to the
initiating client for explicit operator resolution.

Adoption of a missing peer on an active node requires proof of non-secret node identity
from the target host. That proof must bind the selected node name, role,
active role assignments, assignment-local settings, supported platform, live
interface public key, and
WireGuard address to gateway configuration and live WireGuard reality. Unknown-host
materialization belongs to explicit node-membership flows such as `node:new`,
because doctor must not invent node names or roles from unselected live reality.
An operator-supplied host, a live WireGuard peer, or a registry row alone must
leave the adoption result as `conflict` or `skipped`.

`doctor --fix` is the interactive driver: it prompts per drift item for either
`restore` (re-apply gateway configuration to the node) or `adopt` (record
observed node reality back into gateway configuration). `--restore` and
`--adopt` are the non-interactive forms that select one direction up front.

## Implementation Contract

The node-family doctor implements the
[Family Doctor Implementation Contract](../11_operation/3_doctor/technical/1_doctor.md#family-doctor-implementation-contract).
`key()` returns `node`.

The implementation lives in `App\Services\Nodes\NodesProbe`. There is no
separate domain-local technical contract; this document is the canonical
product and implementation reference for the node-family probe.

Family-specific notes on the shared method surface:

| Method | Purpose |
| --- | --- |
| `key()` | Returns the singular state family key: `node`. |
| `label()` | Returns the human-readable family label: `Node`. |
| `introspect(Node $node)` | Reads physical node state gateway-locally or through Agent push for ordinary drift checks. The current implementation returns an empty snapshot for layers that do not need preloaded external state. |
| `diff(Node $node, ProbeSnapshot $snapshot)` | Compares registry state, local/gateway context, WireGuard state, platform state, runtime readiness, role baselines, and node defaults into `DriftEntry` results. |
| `canReconcile()` | Returns whether `doctor --family=node --restore` is supported. |
| `reconcile(Node $node, DriftEntry $entry)` | Applies restore behavior for supported keys and throws for unsupported keys. |
| `canAdopt()` | Returns whether `doctor --family=node --adopt` is supported. |
| `snapshotForAdopt(Node $node)` | Reads adoption-specific proof such as identity artifacts, WireGuard reality, runtime readiness, and local platform facts. |
| `adopt(Node $node, ProbeSnapshot $snapshot)` | Attempts supported adoption paths and returns `AdoptResult` rows with `updated`, `skipped`, or `conflict` actions. |

New probe layers must add the issue code here, add focused Pest coverage in
`NodesProbeTest.php`, and document restore/adopt behavior before the code starts
returning the new key.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Operation/DoctorCommandTest.php` | CLI doctor scope selection and rendered output when node doctor sections are exercised from the CLI. |
| `apps/gateway/tests/Feature/Http/Api/DoctorRunControllerTest.php` | Gateway verify scope and authorization when node-family doctor probes run through the API. |
| `apps/gateway/tests/Feature/Services/Doctor/DoctorDnsProjectionRestoreTest.php` | Family-specific restore routing for node and proxy DNS projections. |
| `apps/gateway/tests/Unit/Services/Doctor/NodeDnsProjectionProbeTest.php` | Source-node host/wildcard findings, one-time anchor reporting for orphan directives, and family isolation. |
