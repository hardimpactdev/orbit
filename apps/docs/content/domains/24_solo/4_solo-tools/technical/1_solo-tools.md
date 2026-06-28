# Technical Contract: `orbit solo:tools`

[Back to public `solo:tools` documentation.](../solo-tools.md)

**Owner:** `solo`.

**Effects:** `read`.

**Prerequisites:**
- The local Solo extension is enabled on the CLI node.
- The gateway Solo extension is enabled before proxy execution.
- The caller has `solo:*` on the serving gateway node.

## Signature

```bash
orbit solo:tools [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model). Arguments and options are validated before the gateway request is sent.

## Behavior Contract

### Local Gate

The command checks local Solo extension state before making a gateway request. Disabled local state returns `extension_disabled` with `meta.scope=local`.

### Gateway Proxy

The command calls `GET /api/solo/tools` through the configured gateway client. Gateway execution requires gateway Solo extension state and the caller permission listed above.

### Upstream Boundary

The gateway proxies only to the Solo API configured as a loopback URL on the serving gateway node. Solo ports are not exposed directly to WireGuard.

### Activity

The gateway records Orbit activity for each Solo operation with the resolved gateway node as the target.

## Failure Semantics

Standard failures defined in [Common Failures](../../../README.md#common-failures) apply. Solo-specific failures include `extension_disabled`, `authorization_failed`, `validation_failed`, and `solo_upstream_unavailable`.

## Doctor Relationship

The Solo domain does not own a doctor state family. Related drift belongs to node, process, or tool doctor families.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Solo/SoloReadOnlyCommandTest.php` | Read-only Solo CLI gateway request shaping, renderer envelope behavior, and gateway error mapping. |
| `apps/cli/tests/Feature/Commands/Solo/SoloMutatingCommandTest.php` | Mutating Solo CLI gateway request shaping, consent, validation, and gateway error mapping. |
| `apps/gateway/tests/Feature/Http/Api/SoloProxyControllerTest.php` | Gateway extension gate, authorization, activity logging, upstream proxying, and Solo upstream validation. |