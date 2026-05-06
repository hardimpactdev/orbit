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
| `canReconcile()` | `bool` | Whether this family supports `--fix`. Returns `true`. |
| `canAdopt()` | `bool` | Whether this family supports `--adopt`. Returns `true`. |
| `reconcile(Node $node, DriftEntry $entry)` | `void` | Apply a fix for a supported drift entry. Throws `RuntimeException` for unsupported keys. |
| `snapshotForAdopt(Node $node)` | `ProbeSnapshot` | Read physical state for adoption. Current implementation returns an empty snapshot. |
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

1. **Registry intent** (`node.record_incomplete`)
   - Detects missing role, status, platform, wireguard_address, host.
   - App nodes additionally require environment.

2. **Local caller role** (`node.local_role_invalid`, `node.local_role_mismatch`)
   - Checks the local active node record (`is_local=true`, `status=active`).
   - Missing local role is accepted (control default or pre-bootstrap).
   - Invalid role reports `node.local_role_invalid`.
   - Mismatch between local record role and probed node role reports `node.local_role_mismatch`.
   - Only runs when the probed node `is_local=true`.

3. **Local default preference** (`node.local_default_invalid`)
   - Checks `LocalNodeDefault` setting against active development app nodes.
   - Validates the default node exists, is development, and is authorized via `NodeAccess`.
   - Only runs when the probed node is a local control node.

4. **Agent IDE default** (`node.agent_ide_default_invalid`)
   - Checks `agent_ide_config` on the node record for supported adapters.
   - Supported adapters: `none`, `opencode`, `polyscope`.

5. **Access grant integrity** (`node.access_grant_invalid`)
   - Detects `NodeAccess` rows referencing missing or non-active nodes.
   - Checks both consumer and serving directions.

6. **Development TLD intent** (`node.development_tld_missing`)
   - Detects development app-node records without a `nodes.tld` value.

### Implemented Local External Checks

These layers perform bounded local-only external inspection. They do not SSH
into remote nodes or mutate host state:

- Platform reality (`node.platform_unsupported`, `node.platform_record_mismatch`)
  - Runs only for the local node record.
  - Detects the current host platform using `PlatformDetector`.
  - Reports mismatch when the local node record's platform identifier differs
    from local detection.

### Stubs (External Service Required)

These layers return empty arrays. They are reserved for future probe
implementations that require external services:

- WireGuard identity (`node.wireguard_peer_missing`, `node.wireguard_peer_extra`, `node.wireguard_address_mismatch`)
- SSH reachability (`node.app_ssh_unreachable`)
- Gateway runtime readiness (`node.gateway_runtime_unready`)
- App-node bootstrap readiness (`node.app_runtime_missing`, `node.node_identity_artifact_missing`)
- Development TLD reality (`node.development_tld_mismatch`, `node.development_dns_mapping_mismatch`, `node.development_dns_public_exposure`)
- CLI PHP default (`node.cli_php_default_mismatch`)
- Local caller identity (`node.identity_unresolved`)
- Gateway API reachability (`node.gateway_api_unreachable`)
- Gateway CA mismatch (`node.gateway_ca_mismatch`)
- Bootstrap network policy (`node.bootstrap_network_policy_mismatch`)

### Public IP Exclusion

Public IPv4/IPv6 metadata is intentionally excluded from probe behavior, per the
public contract. The probe never detects, compares, repairs, or adopts public
address metadata.

## Reconciliation (Fix)

### Supported Keys

| Key | Behavior |
| --- | --- |
| `node.local_role_invalid` | Updates local node record role to match verified active record. |
| `node.local_role_mismatch` | Updates local node record role to match verified active record. |
| `node.wireguard_peer_missing` | Stub: reserved for gateway-managed peer recreation. |
| `node.wireguard_address_mismatch` | Stub: reserved for gateway-managed peer rewrite. |
| `node.gateway_runtime_unready` | Stub: reserved for gateway-side runtime restart. |
| `node.app_runtime_missing` | Stub: reserved for app-node bootstrap rerun. |
| `node.access_grant_invalid` | Removes stale `NodeAccess` rows referencing missing or non-active nodes. |

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

Adoption returns `Skipped` results for all supported adoptable keys:

| Key | Behavior |
| --- | --- |
| `node.wireguard_peer_extra` | Skipped (requires WireGuard peer inspection). |
| `node.wireguard_address_mismatch` | Skipped (requires peer identity verification). |
| `node.platform_record_mismatch` | Skipped (requires live platform detection). |

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
| `tests/Unit/Services/Nodes/NodesProbeTest.php` | Interface contract, record completeness, local role, local default, agent IDE default, access grants, external service stubs, reconciliation behavior, adoption behavior, public IP exclusion. |
