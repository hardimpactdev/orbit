# Node Doctor Technical Contract

This document defines the internal service contract for the node-family doctor
probe. It is the implementation authority for `NodesProbe`.

The public product contract lives in [`node-doctor.md`](../node-doctor.md).

## Service Location

`App\Services\Nodes\NodesProbe`

## Interface

`NodesProbe` is a plain service class (not an interface) with these public
methods:

| Method | Return | Purpose |
| --- | --- | --- |
| `key()` | `string` | Machine-readable family key: `'nodes'`. |
| `label()` | `string` | Human-readable label: `'Nodes'`. |
| `introspect(Node $node)` | `ProbeSnapshot` | Read physical node state. Current implementation returns an empty snapshot (stub for future probe layers). |
| `diff(Node $node, ProbeSnapshot $snapshot)` | `list<DriftEntry>` | Compare node record against probe snapshot and return drift entries. |
| `canReconcile()` | `bool` | Whether this family supports `doctor --family=node --restore`. Returns `true`. |
| `canAdopt()` | `bool` | Whether this family supports `doctor --family=node --adopt`. Returns `true`. |
| `reconcile(Node $node, DriftEntry $entry)` | `void` | Apply a fix for a supported drift entry. Throws `RuntimeException` for unsupported keys. |
| `snapshotForAdopt(Node $node)` | `ProbeSnapshot` | Read physical state for adoption. Snapshots proven peer and address mismatches, app readiness, VPN readiness, DNS drift served by VPN, and platform mismatches. |
| `adopt(Node $node, ProbeSnapshot $snapshot)` | `list<AdoptResult>` | Attempt to adopt node reality into the gateway database. |

## Data Structures

### `App\Data\Doctor\DriftEntry`

```php
final readonly class DriftEntry
{
    public function __construct(
        public string $family,
        public string $key,
        public DriftKind $kind,
        public string $summary,
        public ?array $detail = null,
        public ?string $action = null,
    ) {}
}
```

### `App\Data\Doctor\ProbeSnapshot`

```php
final readonly class ProbeSnapshot
{
    public function __construct(public array $items) {}
    public function keys(): list<string>
    public function get(string $key): ?array
    public function isEmpty(): bool
}
```

### `App\Data\Doctor\AdoptResult`

```php
final readonly class AdoptResult
{
    public function __construct(
        public string $family,
        public string $key,
        public AdoptAction $action,
        public string $summary,
        public ?array $detail = null,
    ) {}
    public function toArray(): array
}
```

## Enums

### `App\Enums\DriftKind`

| Case | Value |
| --- | --- |
| `Missing` | `missing` |
| `Extra` | `extra` |
| `Divergent` | `divergent` |
| `Unverifiable` | `unverifiable` |
| `Unknown` | `unknown` |

### `App\Enums\AdoptAction`

| Case | Value |
| --- | --- |
| `Created` | `created` |
| `Updated` | `updated` |
| `Skipped` | `skipped` |
| `Conflict` | `conflict` |

### `App\Enums\Nodes\NodeRoleStatus`

| Case | Value | Doctor meaning |
| --- | --- | --- |
| `Pending` | `pending` | Desired role is stored, but convergence has not completed. |
| `Active` | `active` | Role baseline is converged and eligible for compatibility and resource checks. |
| `Error` | `error` | Last synchronous convergence attempt failed; restore may retry after blockers are addressed. |
| `Removing` | `removing` | Cleanup is in progress or failed; the role is not eligible for new resources. |

Eligibility checks only use active assignments. Compatibility checks treat
assignments in `active`, `pending`, or `error` as unresolved conflicts and
ignore assignments already in `removing`. The probe still reports non-active
assignments that block the selected node's desired state.

## Probe Layers

### Implemented (In-Memory)

These layers are implemented without external service dependencies:

1. **Registry configuration** (`node.record_incomplete`, `node.role_assignment_missing`, `node.role_assignment_invalid`, `node.role_conflict`, `node.role_settings_invalid`, `node.role_convergence_failed`)
   - Detects missing role assignments implied by legacy node role fields before
     falling back to legacy completeness checks.
   - Detects assignment rows with unknown roles, invalid statuses, or
     non-hydratable typed settings.
   - Detects invalid active-assignment combinations from the compatibility
     matrix.
   - Detects assignments left in `error` after synchronous convergence failed.

2. **Local default preference** (`node.local_default_invalid`)
   - Checks `LocalNodeDefault` setting against active `app-development` nodes.
   - Validates the default node exists, is development, and is authorized via `NodeAccess`.
   - Only runs when `--self` inspects the local CLI's configuration.

3. **Agent IDE default** (`node.agent_ide_default_invalid`)
   - Checks `agent_ide_config` on the node record for supported adapters.
   - Supported adapters: `none`, `opencode`, `polyscope`.

4. **Access grant integrity** (`node.access_grant_invalid`)
   - Detects `NodeAccess` rows referencing missing or non-active nodes.
   - Checks both consumer and serving directions.

5. **Role assignment settings and baseline drift** (`node.role_settings_invalid`, `node.role_baseline_mismatch`, `node.vpn_runtime_missing`, `node.vpn_dns_mapping_mismatch`)
   - Detects active `app-development` role assignments without a valid `tld`.
   - Detects missing or mismatched role-owned derived artifacts such as gateway
     development DNS mappings for active `app-development` assignments.
   - Detects active `vpn` role assignments with invalid `public_endpoint`,
     `wireguard_cidr`, `wireguard_port`, or `dns_ip` settings.
   - Detects missing WireGuard server artifacts or DNS runtime artifacts owned
     by the active gateway-coupled `vpn` role.
   - Detects drift where DNS runtime served through the `vpn` role does not
     match desired DNS mappings owned by the gateway.

6. **WireGuard peer configuration** (`node.wireguard_peer_missing`, `node.wireguard_peer_extra`, `node.wireguard_address_mismatch`)
   - Detects missing `wireguard_peers` rows for active non-gateway node records.
   - Detects `wireguard_peers` rows attached to non-active node records.
   - Checks `wireguard_peers.allowed_ips` against the node record's `wireguard_address`.
   - Gateway node peer rows are not required by this in-memory layer because first
     gateway bootstrap can complete before gateway-side peer material is mirrored
     into the clean registry.

### Implemented Local External Checks

These layers perform bounded local-only external inspection. They do not SSH
into remote nodes or mutate host state:

- Platform reality (`node.platform_unsupported`, `node.platform_record_mismatch`)
  - Runs only when `--self` inspects the local host.
  - Detects the current host platform using `PlatformDetector`.
  - Reports mismatch when the platform identifier on the local host's node record
    differs from local detection.

### Implemented Remote Read Checks

These layers perform bounded read-only remote inspection through `RemoteShell`.
They do not mutate host state:

- SSH reachability (`node.app_ssh_unreachable`)
  - Runs only for active node records.
  - Executes `true` through `RemoteShell` with a short timeout.
  - Reports `Unverifiable` drift when the gateway cannot reach the node over
    SSH.

- Node-side runtime readiness (`node.app_runtime_missing`)
  - Runs only for active node records.
  - Reuses `RuntimeBackendProbe` to verify the minimum remote process manager
    needed for gateway applying.
  - Reports `Unverifiable` drift when supervisor/runtime readiness is missing or
    cannot be verified.

- VPN runtime readiness (`node.vpn_runtime_missing`, `node.vpn_dns_mapping_mismatch`)
  - Runs only for active `vpn` role assignments.
  - Verifies the WireGuard server runtime and DNS runtime owned by the active
    gateway-coupled `vpn` role.
  - Reports `node.vpn_runtime_missing` when the baseline runtime artifacts are
    missing or cannot be verified.
  - Reports `node.vpn_dns_mapping_mismatch` when the DNS runtime served through
    the `vpn` role does not match desired DNS mappings owned by the gateway.

### Stubs (External Service Required)

These layers are reserved for future probe implementations that require
additional external services:

- WireGuard live interface reality (`node.wireguard_peer_missing`, `node.wireguard_peer_extra`, `node.wireguard_address_mismatch`)
  - `WireGuardPeerRealityProbe` can read and parse live `wg show <interface>
    allowed-ips` output by public key.
  - `NodesProbe` consumes this read-only service for selected non-active,
    non-gateway node records that already have registry peer material. Adoption
    activates the node only when the registry peer public key is present in live
    WireGuard reality and has exactly one unambiguous allowed address.
  - `NodesProbe` also consumes this read-only service with
    `NodeIdentityArtifactProbe` for selected active node records missing a
    registry peer row. Adoption attaches a peer row only when the remote
    identity artifact matches the selected node configuration, the artifact reports the
    live interface public key, and live WireGuard reality has exactly one
    allowed address matching the node record.
- Gateway runtime readiness (`node.gateway_runtime_unready`)
- Identity artifact readiness on nodes (`node.node_identity_artifact_missing`)
  - `NodeIdentityArtifactProbe` can read bounded non-secret identity facts from
    the selected host: local active node name, role assignments, assignment
    status, platform,
    WireGuard address, registry public key, and live interface public key when
    available.
  - `NodesProbe` consumes this read-only service for adoption of a missing peer on a selected active node. Adoption of an unknown host or a node with the gateway role still requires
    a separate materialization path before the proof can be used safely.
- DNS runtime served by VPN (`node.vpn_runtime_missing`, `node.vpn_dns_mapping_mismatch`)
  - `DevelopmentDnsMappingProbe` reads development DNS runtime artifacts that
    Orbit manages from the active `vpn` role runtime for the derived node
    configuration model: active `app-development` role assignments with
    non-empty `tld` and non-empty WireGuard addresses. In v1 that runtime is
    gateway-coupled.
  - The canonical mapping is `*.{tld}` to the node's WireGuard address, owned
    by the node name. Missing DNS runtime artifacts or unverifiable DNS runtime
    readiness report `node.vpn_runtime_missing`.
  - Mismatched ownership, target mismatches, and public exposure in the DNS
    runtime served through the `vpn` role report
    `node.vpn_dns_mapping_mismatch`.
  - `app-production`, `database`, gateway, and client nodes must not have
    derived development DNS mappings in the active `vpn` role runtime.
- CLI PHP default (`node.cli_php_default_mismatch`)
- Local caller identity (`node.identity_unresolved`)
- Gateway API reachability (`node.gateway_api_unreachable`)
- Gateway CA mismatch (`node.gateway_ca_mismatch`)
- Bootstrap network policy (`node.bootstrap_network_policy_mismatch`)

### Public IP Exclusion

Public IPv4/IPv6 metadata is intentionally excluded from probe behavior, per the
public contract. The probe never detects, compares, repairs, or adopts public
address metadata.

### Identity Artifact Proof Contract

Adopting an unknown host or adopting a missing peer on an active node requires stronger
proof than a host supplied by the operator, a live WireGuard peer, or a registry row
alone. `NodeIdentityArtifactProbe` reads bounded, non-secret identity facts
from the target host. `NodesProbe` compares those facts with gateway configuration when
adopting a missing peer on a selected active node before it attaches unowned live
reality to a node record.

The minimum proof set is:

- the target host is reached through the path defined by its role: local read
  for the gateway itself or gateway-owned SSH for nodes;
- the target host reports the expected Orbit node name and role;
- if WireGuard is already configured on the target host, the reported
  WireGuard public key or address matches a gateway-owned peer or the peer
  being considered for adoption;
- the target platform is supported for the requested role.

The probe must not read private keys, infer identity from public IP metadata, or
adopt a live WireGuard peer that cannot be tied to a selected node identity.
When this proof is unavailable, adoption remains a conflict or a
`doctor --family=node --restore` handoff. Unknown-host materialization belongs to
explicit node-membership flows such as `node:new`, because those flows provide
the requested node name, role, host, and app-specific configuration needed to create a
record before normal node-family adoption can run. Node doctor does not invent
node names or roles from unselected live reality.

## Reconciliation (Fix)

### Supported Keys

The `node.wireguard_peer_missing` proof path never reads private keys, so the
private key is left empty.

| Key | Behavior |
| --- | --- |
| `node.wireguard_peer_missing` | Attaches a gateway peer row for selected active node records when identity artifact proof and live WireGuard reality agree. |
| `node.wireguard_address_mismatch` | Stub: reserved for gateway-managed peer rewrite. |
| `node.gateway_runtime_unready` | Stub: reserved for gateway-side runtime restart. |
| `node.app_runtime_missing` | Stub: reserved for node bootstrap rerun. |
| `node.access_grant_invalid` | Removes stale `NodeAccess` rows referencing missing or non-active nodes. |
| `node.role_convergence_failed` | Retries convergence for selected `error` role assignments and persists `error` again on failure. |
| `node.role_baseline_mismatch` | Re-applies the baseline artifacts that the selected active role assignments own, including development DNS mappings derived from role settings. |
| `node.vpn_runtime_missing` | Re-applies the active `vpn` role baseline for WireGuard server and VPN-facing DNS runtime artifacts. |
| `node.vpn_dns_mapping_mismatch` | Rewrites the DNS runtime served through the active `vpn` role so it matches gateway-owned desired DNS mappings. |

### Unsupported Keys

Reconciliation throws `RuntimeException` for all other keys, including:

- `node.record_incomplete`
- `node.role_assignment_missing`
- `node.role_assignment_invalid`
- `node.role_conflict`
- `node.role_settings_invalid`
- `node.identity_unresolved`
- `node.platform_unsupported`
- `node.platform_record_mismatch`
- `node.app_ssh_unreachable`
- `node.local_default_invalid`
- `node.agent_ide_default_invalid`
- `node.cli_php_default_mismatch`

## Adoption

Adoption returns `Skipped` results for unsupported adoptable keys and `Updated`
when a supported compatible record can be safely adopted. The
`node.wireguard_peer_missing` proof path never reads private keys, so the
private key is left empty.

| Key | Behavior |
| --- | --- |
| `node.wireguard_peer_missing` | Attaches a gateway peer row for selected active node records when identity artifact proof and live WireGuard reality agree. |
| `node.wireguard_peer_extra` | Activates the selected non-active node record when existing registry peer material matches a live WireGuard peer by public key and that live peer has exactly one unambiguous allowed address. |
| `node.wireguard_address_mismatch` | Updates the node record's WireGuard address when an existing gateway-owned peer has exactly one unambiguous allowed address. |
| `node.app_runtime_missing` | Verifies compatible app runtime readiness when the process manager is available; returns a conflict when runtime readiness cannot be verified. |
| `node.vpn_runtime_missing` | Verifies compatible `vpn` runtime readiness when the WireGuard server and VPN-facing DNS runtime can be proven; returns a conflict when runtime readiness cannot be verified. |
| `node.vpn_dns_mapping_mismatch` | Adopts the observed VPN-served DNS runtime mapping only when it can be proven to belong to the active `vpn` role and match gateway-owned node intent. |
| `node.platform_record_mismatch` | Updates the node record to the observed platform when local platform detection is supported and unambiguous. |

## Extensibility

New probe layers should:

1. Add a private `check*` method to `NodesProbe`.
2. Call it from `diff()` after any probe layer it depends on.
3. Return `list<DriftEntry>` (empty list when no drift).
4. Add the issue code to the public `node-doctor.md` contract if not already
   documented.
5. Add focused Pest coverage to `NodesProbeTest.php`.

Reconciliation for new layers should:

1. Add the key to `$fixableKeys` in `reconcile()`.
2. Add a `match` arm dispatching to a private `reconcile*` method.
3. Document the behavior in this file and the public contract.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Unit/Services/Nodes/NodesProbeTest.php` | Interface contract, record completeness, local default, agent IDE default, access grants, external service stubs, reconciliation behavior, adoption behavior, public IP exclusion. |
