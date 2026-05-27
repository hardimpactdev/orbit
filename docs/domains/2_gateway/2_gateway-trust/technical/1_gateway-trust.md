# Technical Contract: `orbit gateway:trust`

[Back to public `gateway:trust` documentation.](../gateway-trust.md)

**Owner:** `gateway`.

**Effects:** `read`, `write`, `local-only`.

**Prerequisites:**
- A configured local gateway endpoint exists.
- The caller machine can reach the configured gateway root CA endpoint.
- The caller platform has a local trust-store installation path that Orbit supports.
- The process has the local OS privileges required to update the trust store.

## Signature

```bash
orbit gateway:trust [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Input Resolution

1. Select the output renderer.
2. Resolve the configured local gateway endpoint.
   - If no gateway endpoint can be resolved, fail before network or
     trust-store side effects.
   - If local gateway settings cannot be read, fail with
     `node.local_config_read_failed` before network or trust-store side effects.
3. Validate the configured gateway endpoint.
4. Start the local trust repair sequence.

No input-mode-specific contracts are required. The command does not prompt for a
missing gateway endpoint; it fails fast and tells the operator to run
`gateway:add`.

## Behavior Contract

### Gateway Trust Resolution Rules

- Use the configured local gateway endpoint as the only public command target.
- Do not accept arbitrary gateway URLs through `gateway:trust`; first-time
  gateway selection belongs to [`gateway:add`](../../1_gateway-add/gateway-add.md).
- Do not add speculative multi-network selection flags before Orbit has a
  multi-network model.

### Trust Material Rules

- Fetch the gateway root CA public certificate through the bootstrap-safe trust
  path exposed by the configured gateway.
- Accept the current gateway root CA response shape documented by
  `gateway:add`, including JSON payloads that carry the root CA and PEM bodies
  from the trust endpoint.
- Reject empty, malformed, or non-certificate trust material before writing the
  OS trust store.
- Compute the SHA-256 fingerprint from the PEM certificate bytes that were
  installed.

### Orbit Route Trust Rules

The gateway root CA is the Orbit network trust anchor for Orbit-managed route
certificates. Trusting that root makes the caller trust Orbit-managed app,
workspace, proxy, gateway, and tool route leaf certificates that chain to the
same root.

The gateway remains the only node that owns root CA private material and route
certificate issuance. Serving nodes may hold only the route-scoped certificate
and key material required to serve HTTPS for routes applied on that node. App,
workspace, proxy, gateway, and tool route applying owns leaf certificate
creation, upload, renewal, cleanup, and backend TLS drift repair.
`gateway:trust` owns only caller-local installation of the public root.

### Local Trust Store Rules

- Install the fetched gateway root CA into the caller machine's OS trust store.
- Use a stable Orbit trust label so repeated runs update or converge the same
  local trust entry.
- Write local trust metadata after trust-store installation succeeds:
  `gateway_url`, `gateway_wg_ip`, `ca_sha256`, `ca_pem_path`, and
  `trusted_at`.
- Return success when the selected gateway CA is already trusted and the stored
  metadata points to the same CA file, hash, and trust timestamp.
- Return failure when the trust store cannot be updated or local trust metadata
  cannot be written.

### Scope Boundaries

`gateway:trust` must not:
- Create or update gateway node records, client records, or node
  records.
- Change the configured gateway endpoint.
- Mint WireGuard identity, peer material, or node access grants.
- Verify `/api/me` or decide whether the local peer is authorized for gateway
  commands; that belongs to `gateway:add` and node doctor.
- SSH to the gateway or nodes.
- Repair node, app, workspace, process, proxy route, schedule, tool, or
  firewall drift.
- Expose `--export` as a public command option.

It also must not issue, upload, renew, or remove app, workspace, proxy,
gateway, or tool route leaf certificates, and it must never place gateway root
private key material, intermediate CA material, or general certificate-signing
authority on nodes or clients.

## Renderer Contracts

- [Human renderer](6.1_gateway-trust_output-render_human.md)
- [JSON renderer](6.2_gateway-trust_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Gateway missing | No configured gateway exists. | Failure before network or trust-store side effects |
| Local config read failed | Local gateway settings cannot be read from the local Orbit database. | Failure before network or trust-store side effects |
| Gateway endpoint invalid | The configured gateway endpoint cannot be normalized to a gateway URL. | Failure before network or trust-store side effects |
| Trust material invalid | The gateway response does not contain a valid PEM root CA certificate. | Failure before trust-store side effects |
| Unsupported platform | The caller platform has no supported OS trust-store installer. | Failure before trust-store side effects |
| Trust store failed | The OS trust store rejects the certificate or lacks required privileges. | Failure |
| Local metadata failed | Trust-store installation succeeds but local trust metadata cannot be written. | Failure with trust-store side effect already applied |

`validation_failed` remains reserved for settings that load successfully but are
not usable:

- `reason=missing`: no gateway endpoint is configured.
- `reason=invalid`: the configured gateway endpoint cannot be normalized.

`node.local_config_read_failed` is emitted only when the initial local gateway
settings read fails. Its `error.meta.reason` values are:

- `local_database_unavailable`: the local Orbit SQLite database path cannot be
  opened or reached.
- `local_database_locked`: another local process holds a SQLite lock.
- `local_database_read_only`: the local Orbit database opened but cannot accept
  the settings read/create operation because it is read-only.
- `local_database_corrupt`: the database file is malformed, corrupt, or not a
  SQLite database.
- `settings_table_missing`: the `local_gateway_settings` table is absent; local
  migrations have not been applied.

Post-installation metadata write failures keep using
`node.local_config_write_failed` with `reason=metadata_write_failed`.

## Doctor Relationship

- `gateway:trust` repairs only the gateway CA trust that is local to the caller.
- `doctor --family=node --self` verifies configured gateway trust, gateway API
  reachability, and local caller identity. See
  [`node-doctor.md`](../../../1_node/node-doctor.md).
- `doctor --fix --family=node --restore` may call the same trust-store repair behavior for
  `node.gateway_ca_mismatch` when the caller is authorized to receive
  gateway-owned trust material.
- `gateway:trust` does not replace `gateway:add` for first-time onboarding
  because it does not select a gateway, verify `/api/me`, store complete
  gateway endpoint configuration, or verify node identity.

## Activity Logging

The local CLI command emits an activity entry for successful and failed
gateway CA trust repair attempts. Activity logging is best-effort and must not
change the documented command result.

| Field | Value |
| --- | --- |
| Type | `gateway:trust` |
| Effect | `write` |
| Subject | `none`; the command writes caller-local trust-store state and local gateway trust metadata, not a gateway-owned registry entity. |
| Properties | `gateway_url` and `gateway_ip` when a configured gateway resolves; `ca_sha256` and `status` (`trusted` or `already_trusted`) when trust material is successfully installed or converged. No CA PEM, trust-store command output, raw HTTP response body, or secrets. |
| Description | derived |

## Test Mapping

Required split contract tests:

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Commands/Gateway/GatewayTrustCommandTest.php` | Local trust contract: configured endpoint resolution, missing gateway failure, root CA fetch, PEM validation, trust-store side effect, metadata persistence, idempotent already-trusted success, and the no-write guarantees listed below. |
| `apps/gateway/tests/Feature/Commands/Gateway/GatewayTrustLocalConfigReadFailureTest.php` | Local gateway settings read failures before network or trust-store side effects, including actionable `node.local_config_read_failed` reasons and human prose. |
| `apps/gateway/tests/Feature/Commands/Gateway/GatewayTrustJsonRendererTest.php` | JSON renderer selection, success envelope, trust DTO shape, every `error.code` value, error metadata, and `--json` forcing non-interactive mode. |
| `apps/gateway/tests/Feature/Commands/Gateway/GatewayTrustHumanRendererTest.php` | Human renderer progress tree, trusted success prose, already-trusted success prose, gateway fetch failure prose, unsupported-platform prose, and trust-store failure prose. |
| `apps/gateway/tests/E2E/GatewayTrustTest.php` | Real local trust installation against an ephemeral gateway CA on a supported client platform, including local metadata persistence and idempotent convergence. |

`GatewayTrustCommandTest.php` also verifies that the command performs no
gateway config writes, no `/api/me` verification, no public gateway override,
and exposes no public `--export` option.
