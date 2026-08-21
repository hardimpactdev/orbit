# Technical Contract: `orbit solo:process:stop`

[Back to public `solo:process:stop` documentation.](../solo-process-stop.md)

**Owner:** `solo`.

**Effects:** `write`.

**Prerequisites:**
- The local Solo extension is enabled on the CLI node.
- The gateway Solo extension is enabled before proxy execution.
- The caller has `solo:process:lifecycle` on the target node.

## Signature

```bash
orbit solo:process:stop <process> [--node=<node>] [--force] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model). Arguments and options are validated before the gateway request is sent.

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `force` | `--force` | Always (`forceRequired`). | Never. | `false` | Hard consent gate; interactive confirmation is not offered. |

Missing `--force` fails before the gateway request with
`error.code=validation_failed` and `error.meta.reason=force_required` (no
`error.meta.field`).

## Behavior Contract

### Local Gate

The command checks local Solo extension state before making a gateway request. Disabled local state returns `extension_disabled` with `meta.scope=local`.

### Force Gate

When the operation is `forceRequired`, the CLI rejects the invocation unless
`--force` is present, even in interactive human mode.

### Gateway Proxy

The command calls `POST /api/solo/process/stop` through the configured gateway client. The CLI resolves an omitted target from local `node:default`, then falls back to the authenticated caller node. Gateway execution requires gateway Solo extension state and the caller permission listed above on the target node.

### Upstream Boundary

The gateway calls a gateway target directly over its configured loopback URL. For a non-gateway target, the gateway requires an active Agent-eligible node and sends the typed Solo HTTP request through Agent push to target-local loopback. Solo ports and SSH transport are never exposed.

### Activity

The gateway records Orbit activity for each Solo operation with the resolved Solo target node.

## Failure Semantics

Standard failures defined in [Common Failures](../../../README.md#common-failures) apply. Solo-specific failures include `extension_disabled`, `authorization_failed`, `validation_failed`, and `solo_upstream_unavailable`.

## Doctor Relationship

The Solo domain does not own a doctor state family. Related drift belongs to node, process, or tool doctor families.

## Activity Logging

| Field | Value |
| --- | --- |
| Type | `solo.process.stop` |
| Effect | `write` |
| Subject | The target Solo-serving `Node`; `none` when target resolution fails. |
| Properties | `operation` and `target_node`. |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Solo/SoloReadOnlyCommandTest.php` | Read-only Solo CLI gateway request shaping, renderer envelope behavior, and gateway error mapping. |
| `apps/cli/tests/Feature/Commands/Solo/SoloMutatingCommandTest.php` | Mutating Solo CLI gateway request shaping, consent, validation, and gateway error mapping. |
| `apps/gateway/tests/Feature/Http/Api/SoloProxyControllerTest.php` | Gateway extension gate, authorization, activity logging, upstream proxying, and Solo upstream validation. |