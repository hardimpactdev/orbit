# Node Doctor

[Back to Node commands.](README.md)

`doctor --family=node` verifies whether gateway node records still match the
machine facts that make those records usable as Orbit nodes.

The node family owns these facts:

- gateway-owned node records and access policy: name, role, status,
  platform-version identifier, environment for app-node records, host/endpoint
  metadata, WireGuard address, and `node_access` grant integrity;
- local caller identity: presented WireGuard identity, local gateway
  endpoint/trust config, and gateway-managed app-node gateway-client
  endpoint/trust artifacts;
- node connectivity: gateway API reachability for CLI callers and
  gateway-owned SSH reachability for app nodes;
- node bootstrap artifacts: gateway runtime readiness, app-node minimum Orbit
  runtime, app-node gateway-client endpoint/trust artifacts, node identity
  artifacts, role bootstrap network policy, and gateway-managed WireGuard
  peers;
- node-related defaults: development app-node TLDs, gateway development DNS
  mappings for those TLDs, gateway development DNS resolver safety, local
  `node:default` preferences when `--self` inspects the current CLI, gateway-
  owned node-level PHP CLI defaults, and gateway-owned node-level agent IDE
  defaults.

Tools, firewall rules, apps, workspaces, processes, proxy routes, schedules,
and deployments depend on node reachability, but their own artifacts are not
node probe facts.

## Probe Layers

The node probe reads gateway node records and checks these layers:

1. **Registry configuration:** every selected node record has a supported role,
   `provisioning` or `active` status, platform-version identifier such as
   `ubuntu_24-04`, environment for app-node records, required host/endpoint
   metadata, and WireGuard address.
2. **Access policy integrity:** every node access grant references active node
   records. Stale grant rows that point at missing or non-active nodes are node
   family drift.
3. **Local caller identity:** for `--self`, a presented WireGuard identity
   resolves to exactly one active gateway-known node record. Pre-first-gateway-
   bootstrap callers without identity are accepted as the bootstrap path.
4. **Gateway API reachability:** control and app-node CLI callers reach the
   configured gateway API over HTTPS through WireGuard, trust the configured
   gateway certificate authority, and receive their node identity from
   `/api/me`.
5. **WireGuard identity:** each active node record has matching
   gateway-managed WireGuard peer material, and the peer address equals the
   recorded WireGuard address.
6. **Platform reality:** gateway and app nodes report supported Ubuntu platform
   identifiers through SSH. The local control node reports a supported macOS or
   Ubuntu platform identifier when `--self` can inspect it. Remote control
   nodes are verified through identity and gateway API reachability, not SSH.
7. **SSH reachability:** the gateway can SSH to app nodes. Control nodes are
   not SSH targets for node doctor checks.
8. **Gateway runtime readiness:** the gateway node exposes the Orbit API and
   the gateway runtime required by CLI callers.
9. **App-node bootstrap readiness:** app nodes have the minimum Orbit runtime
   and node identity artifacts needed for the gateway to apply other state
   families over SSH.
10. **Role bootstrap network policy:** gateway and app nodes have the
    node-owned baseline network policy for their role and environment. This
    verifies bootstrap reachability policy only, including that SSH management
    traffic is not publicly exposed after bootstrap and instead uses the
    Orbit/WireGuard path. Editable operator firewall rules belong to
    `firewall_rule`.
11. **Development TLD readiness:** development app nodes have a `nodes.tld`
   value, the app node's local TLD default matches the node record, and the
   gateway maps `*.{nodes.tld}` to the node's WireGuard address through the
   node-family development DNS configuration model. The gateway development DNS
   resolver must be WireGuard-reachable and must not expose a public open
   resolver. Production app nodes, gateways, and control nodes have no
   development TLD mapping.
12. **Node-related defaults:** local `node:default` preferences point at
   active, authorized development app nodes when `--self` inspects the CLI's
   local configuration, node-level PHP CLI defaults point at installed
   supported PHP runtimes, and node-level agent IDE defaults point at
   supported adapters.

Public IPv4/IPv6 metadata is not a probe fact. Node doctor does not detect,
compare, repair, or adopt public address metadata until a provider-specific
detection contract exists.

The SSH/bootstrap endpoint and gateway endpoint are operator-supplied
connectivity facts. Node doctor may verify that an endpoint works for the node
path that uses it, but it does not infer public IPv4/IPv6 metadata from that
endpoint.

## Node Issue Codes

| Code | Detected when |
| --- | --- |
| `node.record_incomplete` | A selected node record lacks role, status, platform-version identifier, required environment, required host/endpoint metadata, or WireGuard address. |
| `node.access_grant_invalid` | A node access grant references a missing or non-active consuming or serving node. |
| `node.identity_unresolved` | The caller presents no WireGuard identity or an identity that does not resolve to exactly one active node record. |
| `node.gateway_api_unreachable` | A control or app-node CLI caller cannot reach the configured gateway API over WireGuard. |
| `node.gateway_ca_mismatch` | The gateway API presents a certificate chain that does not match the configured gateway trust. |
| `node.wireguard_peer_missing` | An active node record has no matching gateway-managed WireGuard peer. |
| `node.wireguard_peer_extra` | Gateway-managed WireGuard state contains a peer that does not belong to an active node record. |
| `node.wireguard_address_mismatch` | A gateway-managed WireGuard peer address differs from the node record's WireGuard address. |
| `node.platform_unsupported` | A gateway or app node reports an unsupported platform-version identifier. |
| `node.platform_record_mismatch` | Live platform detection differs from the node record's platform-version identifier. |
| `node.app_ssh_unreachable` | The gateway cannot SSH to an app node. |
| `node.gateway_runtime_unready` | The gateway node does not expose the Orbit API or required gateway runtime. |
| `node.app_runtime_missing` | An app node lacks the minimum Orbit runtime required for gateway applying. |
| `node.node_identity_artifact_missing` | A node is missing bootstrap identity material required to prove its node record. |
| `node.bootstrap_network_policy_mismatch` | A gateway or app node's role bootstrap network policy is missing, unsafe, or inconsistent with its role/environment. |
| `node.development_tld_missing` | A development app-node record has no `nodes.tld` value. |
| `node.development_tld_mismatch` | The app node's local TLD default differs from the gateway node record. |
| `node.development_dns_mapping_mismatch` | The gateway development DNS mapping for `*.{nodes.tld}` is absent, is not owned by the selected node, or points anywhere other than the app node's WireGuard address. |
| `node.development_dns_public_exposure` | Gateway-provisioned development DNS is exposed as a public resolver instead of being reachable only through the Orbit network. |
| `node.local_default_invalid` | During `doctor --self`, the local `node:default` preference points at a missing, unauthorized, or non-development app node. |
| `node.cli_php_default_mismatch` | A node-level CLI PHP default in gateway configuration is absent on the selected node or the target node's default `php` binary differs from gateway configuration. |
| `node.agent_ide_default_invalid` | A node-level agent IDE default points at a missing or unsupported adapter. |

## Node Restore Map

| Code | `doctor --family=node --restore` behavior |
| --- | --- |
| `node.gateway_api_unreachable` | Restart or restore gateway runtime only when running on the gateway node; otherwise leave the issue for gateway-side repair. |
| `node.gateway_ca_mismatch` | Restore local gateway trust from gateway-owned trust material when the caller is authorized to receive it. |
| `node.wireguard_peer_missing` | Reserved for gateway-managed peer recreation; private key material is not read from nodes. Compatible live peer attachment belongs to `doctor --family=node --adopt`. |
| `node.wireguard_peer_extra` | Remove stale gateway-managed peer material when no active node record owns the peer. |
| `node.wireguard_address_mismatch` | Rewrite gateway-managed peer material to the WireGuard address recorded on the active node record. |
| `node.access_grant_invalid` | Remove stale grant rows that reference missing or non-active nodes. |
| `node.gateway_runtime_unready` | Restart or reinstall the gateway runtime artifacts required by Orbit API readiness. |
| `node.app_runtime_missing` | Rerun the app-node bootstrap step that installs the minimum Orbit runtime. |
| `node.node_identity_artifact_missing` | Reinstall node identity material from the active node record. |
| `node.bootstrap_network_policy_mismatch` | Reapply the node-owned bootstrap network policy for the node's role/environment with rollback and reachability checks, preserving gateway-owned `firewall_rule` extras. |
| `node.development_tld_missing` | Restore the development TLD from gateway node configuration when that configuration has exactly one value. |
| `node.development_tld_mismatch` | Rewrite the app node's local TLD default to the value in the gateway node record. |
| `node.development_dns_mapping_mismatch` | Reconcile the derived gateway development DNS mapping to the app node's WireGuard address, or remove an orphaned Orbit-managed mapping when no active development app node owns it. |
| `node.development_dns_public_exposure` | Recreate the gateway development DNS resolver so it is reachable only through the Orbit network and not bound as a public resolver. |
| `node.cli_php_default_mismatch` | Rewrite the node's default `php` binary link to match the gateway-owned node CLI PHP default when the target version is installed and supported. |

`doctor --family=node --restore` does not handle `node.record_incomplete`,
`node.identity_unresolved`, `node.platform_unsupported`,
`node.platform_record_mismatch`, `node.app_ssh_unreachable`,
`node.local_default_invalid`, or
`node.agent_ide_default_invalid`.

`node.local_default_invalid` and `node.agent_ide_default_invalid` are
reported only. `node:default` and `node:agent-ide` are explicit user actions;
doctor must not silently clear or replace those preferences under
`doctor --family=node --restore`.

Node doctor never creates fleet membership, grants access, changes node roles,
or edits public IPv4/IPv6 metadata. Those changes remain explicit node commands
such as `node:new`, `node:update`, `node:grant`, `node:revoke`, and
`node:remove`.

## Node Adopt Map

| Code | `doctor --family=node --adopt` behavior |
| --- | --- |
| `node.wireguard_peer_missing` | Attach a compatible live WireGuard peer only when a selected active app node's non-secret identity artifact matches gateway configuration and live WireGuard reality proves exactly one allowed address. Private key material is not read or adopted. |
| `node.wireguard_peer_extra` | Attach the peer only when the selected scope names a compatible already-provisioned node identity, the registry peer public key is present in live WireGuard reality, and that live peer has exactly one unambiguous allowed address. |
| `node.wireguard_address_mismatch` | Update the node record's WireGuard address only when the peer proves the same node identity. |
| `node.app_runtime_missing` | Verify compatible app runtime readiness; report conflict when runtime readiness cannot be verified. |
| `node.platform_record_mismatch` | Update the node record's platform-version identifier only when live detection is supported and unambiguous. |

`doctor --family=node --adopt` does not handle unselected hosts, unresolved caller identities,
unknown WireGuard peers, public IPv4/IPv6 metadata, or artifacts that belong to
tools, firewall rules, apps, workspaces, processes, proxy routes, schedules, or
deployments.

Active app-node missing-peer adoption requires non-secret node identity artifact
proof from the target host. That proof must bind the selected node name, role,
local role setting, supported platform, live interface public key, and
WireGuard address to gateway configuration and live WireGuard reality. Unknown-host
materialization belongs to explicit node-membership flows such as `node:new`,
because doctor must not invent node names or roles from unselected live reality.
An operator-supplied host, a live WireGuard peer, or a registry row alone must
leave the adoption result as `conflict` or `skipped`.

`doctor --fix` is the interactive driver: it prompts per drift item for either
`restore` (re-apply gateway configuration to the node) or `adopt` (record
observed node reality back into gateway configuration). `--restore` and
`--adopt` are the non-interactive forms that select one direction up front.

## Test Mapping

Required test files:

| Path | Coverage |
| --- | --- |
| `tests/Unit/Services/Nodes/NodesProbeTest.php` | In-memory node probe diff behavior for registry configuration, access grant integrity, WireGuard identity, presented caller identity resolving to a unique active node record, platform reality, SSH reachability, public IP metadata exclusion from probe/restore/adopt behavior, gateway runtime readiness, app-node bootstrap readiness, development TLD mapping readiness, `node.local_default_invalid`, `node.cli_php_default_mismatch`, and `node.agent_ide_default_invalid`. |
| `tests/Feature/Commands/Operations/DoctorCommandContractTest.php` | Node-family dispatch through the global doctor command, drift-detected exit semantics, healthy and unhealthy human/JSON output for the node family, and rejection of unsupported node-family flag combinations. |
