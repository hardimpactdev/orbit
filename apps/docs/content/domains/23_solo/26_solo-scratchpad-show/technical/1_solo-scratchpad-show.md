# Technical Contract: `orbit solo:scratchpad:show`

[Back to public `solo:scratchpad:show` documentation.](../solo-scratchpad-show.md)

**Owner:** `solo`.

**Effects:** `read`.

**Prerequisites:**
- The local Solo extension is enabled on the CLI node.
- The gateway Solo extension is enabled before proxy execution.
- The caller has `solo:*` on the target node.

## Signature

```bash
orbit solo:scratchpad:show <scratchpad> [--node=<node>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model). Arguments and options are validated before the gateway request is sent.

## Behavior Contract

### Local Gate

The command checks local Solo extension state before making a gateway request. Disabled local state returns `extension_disabled` with `meta.scope=local`.

### Gateway Proxy

The command calls `GET /api/solo/scratchpad/show` through the configured gateway client. The CLI resolves an omitted target from local `node:default`, then falls back to the authenticated caller node. Gateway execution requires gateway Solo extension state and the caller permission listed above on the target node.

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
| Type | `solo.scratchpad.show.read` |
| Effect | `read` |
| Subject | The target Solo-serving `Node`; `none` when target resolution fails. |
| Properties | `operation` and `target_node`. |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Solo/SoloReadOnlyCommandTest.php` | Read-only Solo CLI gateway request shaping, renderer envelope behavior, and gateway error mapping. |
| `apps/cli/tests/Feature/Commands/Solo/SoloMutatingCommandTest.php` | Mutating Solo CLI gateway request shaping, consent, validation, and gateway error mapping. |
| `apps/gateway/tests/Feature/Http/Api/SoloProxyControllerTest.php` | Gateway extension gate, authorization, activity logging, upstream proxying, and Solo upstream validation. |