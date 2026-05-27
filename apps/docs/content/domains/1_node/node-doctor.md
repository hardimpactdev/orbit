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
- node connectivity: gateway API reachability for CLI callers and
  gateway-owned SSH reachability for nodes;
- node bootstrap artifacts: gateway runtime readiness, node minimum Orbit
  runtime, gateway-client endpoint and trust artifacts on the node, node identity
  artifacts, role bootstrap network policy, and WireGuard peers managed by the gateway;
- node-owned security baseline: host-key pinning metadata, canonical
  steady-state SSH user, SSH management exposure policy, sysctl baseline, and
  permissions for home directories set during bake;
- node update posture: managed Ubuntu server update readiness through a
  supported update driver, starting with Ubuntu `unattended-upgrades`;
- node-related defaults: `app-development` and `agent` assignment TLD
  settings, development and agent DNS mappings for those TLDs, DNS resolver
  safety, `vpn` role settings and runtime, local `node:default` preferences for `--self`,
  Orbit launcher/runtime readiness, and agent IDE defaults at the node level.

Tools, firewall rules, apps, workspaces, processes, proxy routes, schedules,
and deployments depend on node reachability, but their own artifacts are not
node probe facts.

## Probe Layers

The node probe reads gateway node records and checks these layers:

1. **Registry configuration:** every selected node record has valid role
   assignments, assignment statuses, platform-version identifier such as
   `ubuntu_24-04`, required host/endpoint metadata, and WireGuard address.
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
6. **Platform reality:** gateway and nodes report supported Ubuntu platform
   identifiers through SSH. The local client reports a supported macOS or
   Ubuntu platform identifier when `--self` can inspect it. Remote client
   machines are verified through identity and gateway API reachability, not SSH.
7. **SSH reachability:** the gateway can SSH to nodes. Clients are
   not SSH targets for node doctor checks.
8. **Gateway runtime readiness:** the gateway node exposes the Orbit API and
   the gateway runtime required by CLI callers.
9. **Node-side bootstrap readiness:** nodes have the minimum Orbit runtime
   and node identity artifacts needed for the gateway to apply other state
   families over SSH.
10. **Role bootstrap network policy:** gateway and nodes have the
    baseline network policy that the node owns for its role and environment. This
    verifies bootstrap reachability policy only, including that SSH management
    traffic is not publicly exposed after bootstrap and instead uses the
    Orbit/WireGuard path. Editable operator firewall rules belong to
    `firewall_rule`.
11. **Node security posture:** provisioned Linux nodes satisfy node-owned
   security checks. These issue keys use the `node.security.*` prefix and remain
   inside the `node` family; `security` is not a doctor family. Persisted
   `nodes.user` values other than `orbit` are reported as drift. The
   `node:new --user` option remains valid as a bootstrap-only SSH user and is
   not itself drift.
12. **Node update posture:** managed Ubuntu server nodes may expose
   `node.updates` posture when the operator selects the exact
   `--key=node.updates` filter. The update layer runs only when a registered
   update driver supports the selected target. Unsupported targets are silent:
   node doctor never creates `node.updates_not_applicable` or
   `node.updates_driver_unsupported` findings.
13. **Role assignment readiness:** active role assignments have the settings
   their role requires, current assignment convergence state, and no baseline
   drift.

   For `app-development`, assignments have a `tld` value, the node's
   local TLD default matches the active assignment, and the gateway maps
   `*.{tld}` to the node's WireGuard address. The development DNS resolver that
   the gateway maintains must be WireGuard-reachable and must not expose a
   public open resolver.

   For `agent`, assignments have a `tld` value, the gateway maps `*.{tld}` to
   the node's WireGuard address through the same DNS configuration model, and
   the node baseline includes `orbit-runtime`, `orbit-caddy`, and the shared
   unprivileged `agent` runtime user.

   For `vpn`, assignments have valid `public_endpoint`, `wireguard_cidr`,
   `wireguard_port`, and `dns_ip` settings. The node baseline includes the
   WireGuard server runtime and DNS runtime, and the DNS runtime served through
   the `vpn` role matches desired DNS mappings owned by the gateway.

   For `app-production`, the assignment has a valid `ingress_node_id`
   setting. The role baseline owns private backend readiness: `orbit-caddy`
   bound to the node's WireGuard address, FrankenPHP app containers, and Docker
   process runtime units.

   For `ingress`, assignments have no role settings in v1. The node
   baseline includes public production HTTP ingress, public `orbit-caddy` route
   artifacts, and forwarding readiness to the active `router`. Backend-pool
   selection belongs to the `router` role.

   For `websocket`, assignments have a valid `redis_node_id` setting that
   references an active `database` role node with Redis expected or installed.
   The node baseline owns Laravel Reverb in a Docker runtime container managed
   by Orbit, private backend certificate material, WireGuard-only binding, and
   router-facing readiness. The websocket role does not install or own Redis.

   For `s3`, assignments have a valid absolute `data_path` setting, a supported
   platform, WireGuard identity, and role convergence status. The node family
   verifies the role assignment and the data path for that role only. RustFS tool
   rows, service credentials, and containers belong to the tool family; S3
   service routes and backend pools belong to the proxy family.

   `database` and `gateway` assignments have no role settings in v1.
14. **Node-related defaults:** local `node:default` preferences point at
   active, authorized `app-development` nodes when `--self` inspects the CLI's
   local configuration, and agent IDE defaults at the node level point at
   supported adapters.

Public IPv4/IPv6 metadata is not a probe fact. Node doctor does not detect,
compare, repair, or adopt public address metadata until a detection contract specific to the provider exists.

The SSH/bootstrap endpoint and gateway endpoint are operator-supplied
connectivity facts. Node doctor may verify that an endpoint works for the node
path that uses it, but it does not infer public IPv4/IPv6 metadata from that
endpoint.

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

Each code below identifies a specific kind of node-family drift that `doctor --family=node` may report.

| Code | Detected when |
| --- | --- |
| `node.record_incomplete` | A selected node record lacks required platform-version identifier, required host/endpoint metadata, or WireGuard address. |
| `node.role_assignment_missing` | A selected active node has no compatible active role assignment for the role implied by its registry record. |
| `node.role_assignment_invalid` | A persisted role assignment names an unknown role or otherwise cannot be validated as a real role row. |
| `node.role_conflict` | Active, pending, or error role assignments violate the compatibility matrix. Assignments already in `removing` are ignored. |
| `node.role_settings_invalid` | A role assignment's typed settings cannot be hydrated or are missing required values such as the `app-development` `tld`. |
| `node.role_convergence_failed` | A role assignment is left in `error` after synchronous convergence failed. |
| `node.role_baseline_mismatch` | Active role-owned baseline artifacts no longer match the role assignment's desired state. |
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
| `node.ssh_unreachable` | The gateway cannot SSH to a node. |
| `node.gateway_runtime_unready` | The gateway node does not expose the Orbit API or required gateway runtime. |
| `node.runtime_missing` | A node lacks the minimum Orbit runtime required for gateway applying. |
| `node.vpn_runtime_missing` | The active gateway-coupled `vpn` assignment is missing WireGuard server or VPN-facing DNS runtime artifacts. |
| `node.vpn_dns_mapping_mismatch` | The DNS runtime served through the `vpn` role does not match gateway-owned desired DNS mappings. |
| `node.s3_data_path_invalid` | An active `s3` role assignment has a missing, relative, or otherwise invalid `data_path` setting. |
| `node.node_identity_artifact_missing` | A node is missing bootstrap identity material required to prove its node record. |
| `node.bootstrap_network_policy_mismatch` | A gateway or node's role bootstrap network policy is missing, unsafe, or inconsistent with its role assignments. |
| `node.security.host_key.<node>` | A managed Linux node has no pinned host key, a mismatched host key, or host-key metadata that cannot be verified. First pin is adoptable only with explicit operator consent. |
| `node.security.ssh_user` | A persisted managed node record uses a steady-state SSH user other than `orbit`. |
| `node.security.public_ssh_deny` | A provisioned Linux node does not deny public SSH exposure according to node-owned bootstrap policy. |
| `node.security.sysctl` | A provisioned Linux node is missing or diverges from the node-owned sysctl baseline. |
| `node.security.home_perms` | `/home/orbit` or `/home/orbit/.ssh` permissions are weaker than the bake-time baseline. Report-only; restore requires operator re-bake. |
| `node.updates_config_missing` | A supported update driver found that `unattended-upgrades` or required apt auto-upgrade config is absent. The issue object uses `key=node.updates` and this value as `code`. |
| `node.updates_config_mismatch` | A supported update driver found apt auto-upgrade config that differs from Orbit's expected policy. The issue object uses `key=node.updates` and this value as `code`. |
| `node.updates_dry_run_failed` | A supported update driver found that `sudo unattended-upgrade --dry-run` failed. The issue object uses `key=node.updates` and this value as `code`. |
| `node.updates_last_run_failed` | A supported update driver found recent unattended-upgrades evidence reporting a failed run. The issue object uses `key=node.updates` and this value as `code`. |
| `node.updates_reboot_required` | A supported update driver found `/var/run/reboot-required`. The issue object uses `key=node.updates` and this value as `code`. |
| `node.updates_unverifiable` | A supported update driver cannot inspect update posture. Unsupported targets are silent instead. The issue object uses `key=node.updates` and this value as `code`. |
| `node.local_default_invalid` | During `doctor --self`, the local `node:default` preference points at a missing, unauthorized, or non-`app-development` node. |
| `node.agent_ide_default_invalid` | A node-level agent IDE default points at a missing or unsupported adapter. |

## Node Fix Map

This table describes what `doctor --restore --family=node` does for each resolvable issue code.

| Code | `doctor --restore --family=node` behavior |
| --- | --- |
| `node.gateway_api_unreachable` | Restart or restore gateway runtime only when running on the gateway node; otherwise leave the issue for gateway-side repair. |
| `node.gateway_ca_mismatch` | Restore local gateway trust from gateway-owned trust material when the caller is authorized to receive it. |
| `node.wireguard_peer_missing` | Reserved for gateway-managed peer recreation; private key material is not read from nodes. Compatible live peer attachment belongs to `doctor --family=node --adopt`. |
| `node.wireguard_peer_extra` | Remove stale gateway-managed peer material when no active node record owns the peer. |
| `node.wireguard_address_mismatch` | Rewrite gateway-managed peer material to the WireGuard address recorded on the active node record. |
| `node.access_grant_invalid` | Remove stale grant rows that reference missing or non-active nodes. |
| `node.access_permission_invalid` | Re-normalize the stored permission set on the grant when it can be reduced to a valid set without changing intent; otherwise leave the drift visible for explicit operator action through `node:permissions`. |
| `node.role_convergence_failed` | Retry synchronous convergence for error role assignments on the selected node and leave an assignment in `error` again if the retry fails. |
| `node.role_baseline_mismatch` | Re-apply the baseline artifacts for the selected active role assignments, including role-owned derived artifacts such as development DNS mappings. |
| `node.gateway_runtime_unready` | Restart or reinstall the gateway runtime artifacts required by Orbit API readiness. |
| `node.runtime_missing` | Rerun the node bootstrap step that installs the minimum Orbit runtime. |
| `node.vpn_runtime_missing` | Re-apply the active `vpn` role baseline for WireGuard server and VPN-facing DNS runtime artifacts. |
| `node.vpn_dns_mapping_mismatch` | Rewrite the DNS runtime served through the active `vpn` role so it matches gateway-owned desired DNS mappings. |
| `node.node_identity_artifact_missing` | Reinstall node identity material from the active node record. |
| `node.bootstrap_network_policy_mismatch` | Reapply the node-owned bootstrap network policy for the node's role assignments with rollback and reachability checks, preserving gateway-owned `firewall_rule` extras. |
| `node.security.public_ssh_deny` | Reapply the node-owned public SSH deny policy through the node family while preserving user-owned firewall rules. |
| `node.security.sysctl` | Restore the managed sysctl baseline and reload sysctl. |
| `node.updates` | For exact `--key=node.updates`, repair apt auto-upgrade config through `UnattendedUpgradesInstaller`, run `sudo unattended-upgrade`, re-probe, and report any remaining drift. Orbit never reboots automatically. |
`doctor --family=node --restore` does not handle `node.record_incomplete`,
`node.role_assignment_missing`, `node.role_assignment_invalid`, `node.role_conflict`,
`node.role_settings_invalid`,
`node.identity_unresolved`, `node.platform_unsupported`,
`node.platform_record_mismatch`, `node.ssh_unreachable`,
`node.security.host_key.<node>`, `node.security.ssh_user`,
`node.security.home_perms`, `node.local_default_invalid`, or
`node.agent_ide_default_invalid`.

`node.vpn_runtime_missing` reports that the active gateway-coupled `vpn`
assignment is missing WireGuard server artifacts or DNS runtime artifacts.

`node.vpn_dns_mapping_mismatch` reports that the DNS runtime served through
the `vpn` role does not match desired DNS mappings owned by the gateway.

`node.local_default_invalid` and `node.agent_ide_default_invalid` are
reported only. `node:default` and `node:agent-ide` are explicit user actions;
doctor must not silently clear or replace those preferences under
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
| `node.runtime_missing` | Verify compatible app runtime readiness; report conflict when runtime readiness cannot be verified. |
| `node.platform_record_mismatch` | Update the node record's platform-version identifier only when live detection is supported and unambiguous. |
| `node.security.host_key.<node>` | Pin the currently observed host key only when the operator selected this exact key and explicitly chose adopt. |

Conditions for `node.wireguard_peer_missing` adoption: the selected active
node has a non-secret identity artifact that matches gateway
configuration, and live WireGuard reality proves exactly one allowed address.
Private key material is not read or adopted.

Conditions for `node.wireguard_peer_extra` adoption: the selected scope names
a node identity that is already provisioned and compatible, the registry peer
public key is present in live WireGuard reality, and that live peer has
exactly one unambiguous allowed address.

`doctor --family=node --adopt` does not handle unselected hosts, unresolved caller identities,
unknown WireGuard peers, public IPv4/IPv6 metadata, non-host-key security
settings, or artifacts that belong to tools, firewall rules, apps, workspaces,
processes, proxy routes, schedules, or deployments.

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
| `introspect(Node $node)` | Reads physical node state for ordinary drift checks. The current implementation returns an empty snapshot for layers that do not need preloaded external state. |
| `diff(Node $node, ProbeSnapshot $snapshot)` | Compares registry state, local/gateway context, WireGuard state, platform state, runtime readiness, role baselines, and node defaults into `DriftEntry` results. |
| `canReconcile()` | Returns whether `doctor --family=node --restore` is supported. |
| `reconcile(Node $node, DriftEntry $entry)` | Applies restore behavior for supported keys and throws for unsupported keys. |
| `canAdopt()` | Returns whether `doctor --family=node --adopt` is supported. |
| `snapshotForAdopt(Node $node)` | Reads adoption-specific proof such as identity artifacts, WireGuard reality, runtime readiness, VPN DNS/runtime state, and local platform facts. |
| `adopt(Node $node, ProbeSnapshot $snapshot)` | Attempts supported adoption paths and returns `AdoptResult` rows with `updated`, `skipped`, or `conflict` actions. |

New probe layers must add the issue code here, add focused Pest coverage in
`NodesProbeTest.php`, and document restore/adopt behavior before the code starts
returning the new key.

## Test Mapping

Required test files:

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Unit/Services/Nodes/NodesProbeTest.php` | In-memory node probe diff behavior (see breakdown below). |
| `apps/gateway/tests/Feature/Commands/Operations/DoctorCommandContractTest.php` | Node-family dispatch through the global doctor command, drift-detected exit semantics, healthy and unhealthy human/JSON output for the node family, and rejection of unsupported node-family flag combinations. |

`NodesProbeTest` covers diff behavior for registry configuration, role
assignment compatibility and status, access grant integrity, WireGuard
identity, and presented caller identity resolving to a unique active node
record. It also covers platform reality, SSH reachability, public IP metadata
exclusion from probe/restore/adopt behavior, gateway runtime readiness,
node bootstrap readiness, node security posture, and development TLD mapping readiness. The
probe additionally covers `node.local_default_invalid`,
`node.cli_php_default_mismatch`, and `node.agent_ide_default_invalid`.
