# Technical Contract: `orbit solo:process:close`

[Back to public `solo:process:close` documentation.](../solo-process-close.md)

**Owner:** `solo`.

**Effects:** `destructive`.

**Prerequisites:**
- The local Solo extension is enabled on the CLI node.
- The gateway Solo extension is enabled before proxy execution.
- The caller has `solo:process:delete` on the target node.

## Signature

```bash
orbit solo:process:close <process> [--node=<node>] [--force] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model). Arguments and options are validated before the gateway request is sent.

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `force` | `--force` | Non-interactive mode. | Never. | `false` | Explicit destructive consent; skips interactive confirmation. |
| `json` | `--json` | Optional. | Never. | `false` | Selects the JSON renderer and forces non-interactive mode; it is not destructive consent. |

## Input Mode Contracts

- [Interactive input mode](5.1_solo-process-close_input-mode_interactive.md)
- [Non-interactive input mode](5.2_solo-process-close_input-mode_non-interactive.md)

## Behavior Contract

### Local Gate

The command checks local Solo extension state before making a gateway request. Disabled local state returns `extension_disabled` with `meta.scope=local`.

### Gateway Proxy

The command calls `DELETE /api/solo/process/close` through the configured gateway client. The CLI resolves an omitted target from local `node:default`, then falls back to the authenticated caller node. Gateway execution requires gateway Solo extension state and the caller permission listed above on the target node.

### Destructive Consent

After resolving the node and process, apply the shared [Solo destructive-consent contract](../../README.md#destructive-consent). No gateway request is sent before consent succeeds.

### Upstream Boundary

The gateway calls a gateway target directly over its configured loopback URL. For a non-gateway target, the gateway requires an active Agent-eligible node and sends the typed Solo HTTP request through Agent push to target-local loopback. Solo ports and SSH transport are never exposed.

### Activity

The gateway records Orbit activity for each Solo operation with the resolved Solo target node.

## Failure Semantics

Standard failures defined in [Common Failures](../../../README.md#common-failures) apply. Missing or declined consent returns `validation_failed` with `meta.field=force` and `meta.reason=destructive_consent_required`. Additional Solo failures include `extension_disabled`, `authorization_failed`, and `solo_upstream_unavailable`.

## Doctor Relationship

The Solo domain does not own a doctor state family. Related drift belongs to node, process, or tool doctor families.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Solo/SoloReadOnlyCommandTest.php` | Read-only Solo CLI gateway request shaping, renderer envelope behavior, and gateway error mapping. |
| `apps/cli/tests/Feature/Commands/Solo/SoloMutatingCommandTest.php` | Mutating Solo CLI gateway request shaping, destructive consent, validation, and gateway error mapping. |
| `apps/gateway/tests/Feature/Http/Api/SoloProxyControllerTest.php` | Gateway extension gate, authorization, activity logging, upstream proxying, and Solo upstream validation. |
