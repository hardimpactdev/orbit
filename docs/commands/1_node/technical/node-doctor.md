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
| `snapshotForAdopt(Node $node)` | `ProbeSnapshot` | Read physical state for adoption. Current implementation snapshots proven active app-node missing peers, proven live WireGuard peer extras, unambiguous WireGuard address mismatches, app runtime readiness, and local platform record mismatches. |
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

## Probe Layers

### Implemented (In-Memory)

These layers are implemented without external service dependencies:

1. **Registry configuration** (`node.record_incomplete`)
   - Detects missing role, status, platform, wireguard_address, host.
   - App nodes additionally require environment.

2. **Local default preference** (`node.local_default_invalid`)
   - Checks `LocalNodeDefault` setting against active development app nodes.
   - Validates the default node exists, is development, and is authorized via `NodeAccess`.
   - Only runs when `--self` inspects the local CLI's configuration.

3. **Agent IDE default** (`node.agent_ide_default_invalid`)
   - Checks `agent_ide_config` on the node record for supported adapters.
   - Supported adapters: `none`, `opencode`, `polyscope`.

4. **Access grant integrity** (`node.access_grant_invalid`)
   - Detects `NodeAccess` rows referencing missing or non-active nodes.
   - Checks both consumer and serving directions.

5. **Development TLD configuration** (`node.development_tld_missing`)
   - Detects development app-node records without a `nodes.tld` value.

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
  - Reports mismatch when the local-host node record's platform identifier
    differs from local detection.

### Implemented Remote Read Checks

These layers perform bounded read-only remote inspection through `RemoteShell`.
They do not mutate host state:

- SSH reachability (`node.app_ssh_unreachable`)
  - Runs only for active app-node records.
  - Executes `true` through `RemoteShell` with a short timeout.
  - Reports `Unverifiable` drift when the gateway cannot reach the app node over
    SSH.

- App-node runtime readiness (`node.app_runtime_missing`)
  - Runs only for active app-node records.
  - Reuses `RuntimeBackendProbe` to verify the minimum remote process manager
    needed for gateway applying.
  - Reports `Unverifiable` drift when supervisor/runtime readiness is missing or
    cannot be verified.

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
    `NodeIdentityArtifactProbe` for selected active app-node records missing a
    registry peer row. Adoption attaches a peer row only when the remote
    identity artifact matches the selected node configuration, the artifact reports the
    live interface public key, and live WireGuard reality has exactly one
    allowed address matching the node record.
- Gateway runtime readiness (`node.gateway_runtime_unready`)
- App-node identity artifact readiness (`node.node_identity_artifact_missing`)
  - `NodeIdentityArtifactProbe` can read bounded non-secret identity facts from
    the selected host: local active node name, role, status, platform,
    WireGuard address, registry public key, and live interface public key when
    available.
  - `NodesProbe` consumes this read-only service for selected active app-node
    missing-peer adoption. Unknown-host and gateway-role adoption still require
    a separate materialization path before they can use this proof safely.
- Development TLD reality (`node.development_tld_mismatch`, `node.development_dns_mapping_mismatch`, `node.development_dns_public_exposure`)
  - `DevelopmentDnsMappingProbe` reads gateway-local Orbit-managed development
    DNS resolver artifacts for the derived node configuration model:
    active development app-node rows with non-empty `nodes.tld` and
    non-empty WireGuard addresses.
  - The canonical mapping is `*.{nodes.tld}` to the app node's WireGuard
    address, owned by the app node name. Missing artifacts, conflicting
    ownership, and target mismatches report
    `node.development_dns_mapping_mismatch`.
  - Resolver bindings or listener configuration that expose the development DNS
    resolver outside the Orbit/WireGuard network report
    `node.development_dns_public_exposure`.
  - Production app nodes, gateway nodes, and control nodes must not have derived
    development DNS mappings.
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

Unknown-host adoption and active-node missing-peer adoption require stronger
proof than an operator-supplied host, a live WireGuard peer, or a registry row
alone. `NodeIdentityArtifactProbe` reads bounded, non-secret node identity facts
from the target host. `NodesProbe` compares those facts with gateway configuration for
selected active app-node missing-peer adoption before it attaches unowned live
reality to a node record.

The minimum proof set is:

- the target host is reached through the role-appropriate path: local read for
  the gateway itself or gateway-owned SSH for app nodes;
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

| Key | Behavior |
| --- | --- |
| `node.wireguard_peer_missing` | Attaches a gateway peer row for selected active app-node records only when identity artifact proof and live WireGuard reality agree. The private key is intentionally left empty because the proof path never reads private keys. |
| `node.wireguard_address_mismatch` | Stub: reserved for gateway-managed peer rewrite. |
| `node.gateway_runtime_unready` | Stub: reserved for gateway-side runtime restart. |
| `node.app_runtime_missing` | Stub: reserved for app-node bootstrap rerun. |
| `node.access_grant_invalid` | Removes stale `NodeAccess` rows referencing missing or non-active nodes. |
| `node.development_dns_mapping_mismatch` | Stub: reserved for `DevelopmentDnsMappingEnactor` convergence or orphaned mapping removal. |
| `node.development_dns_public_exposure` | Stub: reserved for recreating the gateway development DNS resolver with Orbit/WireGuard-only binding. |

### Unsupported Keys

Reconciliation throws `RuntimeException` for all other keys, including:

- `node.record_incomplete`
- `node.identity_unresolved`
- `node.platform_unsupported`
- `node.platform_record_mismatch`
- `node.app_ssh_unreachable`
- `node.local_default_invalid`
- `node.agent_ide_default_invalid`
- `node.cli_php_default_mismatch`

## Adoption

Adoption returns `Skipped` results for unsupported adoptable keys and `Updated`
when a supported compatible record can be safely adopted:

| Key | Behavior |
| --- | --- |
| `node.wireguard_peer_missing` | Attaches a gateway peer row for selected active app-node records only when identity artifact proof and live WireGuard reality agree. The private key is intentionally left empty because the proof path never reads private keys. |
| `node.wireguard_peer_extra` | Activates the selected non-active node record when existing registry peer material matches a live WireGuard peer by public key and that live peer has exactly one unambiguous allowed address. |
| `node.wireguard_address_mismatch` | Updates the node record's WireGuard address when an existing gateway-owned peer has exactly one unambiguous allowed address. |
| `node.app_runtime_missing` | Verifies compatible app runtime readiness when the process manager is available; returns a conflict when runtime readiness cannot be verified. |
| `node.platform_record_mismatch` | Updates the node record to the observed platform when local platform detection is supported and unambiguous. |

## Extensibility

New probe layers should:

1. Add a private `check*` method to `NodesProbe`.
2. Call it from `diff()` in the appropriate order.
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
