# Technical Contract: `orbit ca:trust`

[Back to public `ca:trust` documentation.](../ca-trust.md)

**Owner:** `operation`.

**Effects:** `read`, `write`, `local-only`, `stream`.

**Prerequisites:**
- A configured local gateway endpoint exists, or `ca_trust.gateway` is supplied.
- The caller machine can reach the selected gateway root CA endpoint.
- The caller platform has an Orbit-supported local trust-store installation
  path.
- The process has the local OS privileges required to update the trust store.

## Signature

```bash
orbit ca:trust [--gateway=<url-or-address>] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `gateway` | `--gateway` | Required when no local gateway endpoint is configured. | Never. | Configured local gateway endpoint. | Gateway URL, hostname, or IP address. Normalized to an HTTPS gateway URL unless a scheme is supplied. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Caller Role Behavior

`ca:trust` is local-only. Caller role does not change the command path. Control,
gateway, and app callers may repair trust on their own caller machine.

For app-node callers, `ca:trust` is only a local trust-store repair. It does not
grant app-node write authority, create gateway intent, or replace
gateway-managed app-node bootstrap artifacts.

## Input Resolution

1. Select the output renderer.
2. Resolve `ca_trust.gateway`.
   - If `--gateway` is supplied, normalize that value to a gateway URL.
   - If `--gateway` is omitted, read the configured local gateway endpoint.
   - If no gateway endpoint can be resolved, fail before network or trust-store
     side effects.
3. Validate the normalized gateway URL.
4. Start the local trust repair sequence.

No input-mode-specific contracts are required. The command does not prompt for a
missing gateway endpoint; it fails fast and tells the operator to supply
`--gateway` or run `gateway:add`.

## Behavior Contract

### Gateway Trust Resolution Rules

- Use the configured local gateway endpoint when `--gateway` is omitted.
- Accept explicit gateway URLs, hostnames, and WireGuard addresses through
  `--gateway`.
- Normalize hostnames and IP addresses without a scheme to `https://<value>`.
- Do not add speculative multi-network selection flags before Orbit has a
  multi-network model.

### Trust Material Rules

- Fetch the gateway root CA public certificate through the bootstrap-safe trust
  path exposed by the gateway.
- Accept the current gateway root CA response shape documented by
  `gateway:add`, including JSON payloads that carry the root CA and PEM bodies
  from the trust endpoint.
- Reject empty, malformed, or non-certificate trust material before writing the
  OS trust store.
- Compute the SHA-256 fingerprint from the PEM certificate bytes that were
  installed.

### Local Trust Store Rules

- Install the fetched gateway root CA into the caller machine's OS trust store.
- Use a stable Orbit trust label so repeated runs update or converge the same
  local trust entry.
- Write local trust metadata after trust-store installation succeeds:
  `gateway_url`, `ca_sha256`, and `trusted_at`.
- Return success when the selected gateway CA is already trusted and the stored
  metadata matches the installed certificate.
- Return failure when the trust store cannot be updated or local trust metadata
  cannot be written.

### Scope Boundaries

`ca:trust` must not:
- Create or update gateway node records, control node records, or app node
  records.
- Mint WireGuard identity, peer material, or node access grants.
- Verify `/api/me` or decide whether the local peer is authorized for gateway
  commands; that belongs to `gateway:add` and node doctor.
- SSH to the gateway or app nodes.
- Repair node, app, workspace, process, proxy route, schedule, tool, or
  firewall drift.
- Expose `--export` as a public command option.

## Renderer Contracts

- [Human renderer](6.1_ca-trust_output-render_human.md)
- [JSON renderer](6.2_ca-trust_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Gateway missing | No configured gateway exists and `--gateway` is omitted. | Failure before network or trust-store side effects |
| Gateway value invalid | `--gateway` cannot be normalized to a gateway URL. | Failure before network or trust-store side effects |
| Gateway unavailable | The root CA endpoint cannot be reached or returns a non-success response. | Failure before trust-store side effects |
| Trust material invalid | The gateway response does not contain a valid PEM root CA certificate. | Failure before trust-store side effects |
| Unsupported platform | The caller platform has no supported OS trust-store installer. | Failure before trust-store side effects |
| Trust store failed | The OS trust store rejects the certificate or lacks required privileges. | Failure |
| Local metadata failed | Trust-store installation succeeds but local trust metadata cannot be written. | Failure with trust-store side effect already applied |

## Doctor Relationship

- `ca:trust` repairs only caller-local gateway CA trust.
- `doctor --family=node --self` verifies configured gateway trust, gateway API
  reachability, and local caller identity. See
  [`node-doctor.md`](../../../1_node/node-doctor.md).
- `doctor --family=node --fix` may call the same trust-store repair behavior for
  `node.gateway_ca_mismatch` when the caller is authorized to receive
  gateway-owned trust material.
- `ca:trust` does not replace `gateway:add` for first-time onboarding because it
  does not verify `/api/me`, store complete gateway endpoint configuration, or
  verify node identity.

## Test Mapping

Required split contract tests:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Operations/CaTrustCommandTest.php` | Local trust contract: gateway endpoint resolution, no-prompt missing gateway failure, root CA fetch, PEM validation, local trust-store side effect, local metadata persistence, idempotent already-trusted success, no gateway intent writes, no `/api/me` identity verification, and no public `--export` option. |
| `tests/Feature/Commands/Operations/CaTrustJsonRendererTest.php` | JSON renderer selection, success envelope, CA DTO shape, every `error.code` value, error metadata, and `--json` forcing non-interactive mode. |
| `tests/Feature/Commands/Operations/CaTrustHumanRendererTest.php` | Human renderer progress tree, trusted success prose, already-trusted success prose, gateway fetch failure prose, unsupported-platform prose, and trust-store failure prose. |
| `tests/E2E/Ephemeral/CaTrustTest.php` | Real local trust installation against an ephemeral gateway CA on a supported control-node platform. |
