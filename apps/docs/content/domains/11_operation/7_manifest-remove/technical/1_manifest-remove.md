# Technical Contract: `orbit manifest:remove`

[Back to public `manifest:remove` documentation.](../manifest-remove.md)

**Owner:** `operation`.

**Effects:** `write`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The gateway authorizes the calling WireGuard peer with gateway-admin
  authority (`*` on the active gateway node).

## Signature

```bash
orbit manifest:remove [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Input Resolution

1. Select the output renderer.
2. Send DELETE `/api/manifest`.

The command has no required inputs and does not prompt.

## Behavior Contract

### Source Persistence

- Clear the gateway's custom release manifest URL.
- Return the gateway's effective release manifest source after the clear.
- Keep the configured default release manifest URL unchanged.

### Gateway Authority

- Require gateway-admin authority against the active gateway node.

### Update Boundaries

- Do not start an update operation.
- Do not delete artifacts from the topology artifact store or GitHub.

## Renderer Contracts

- [Human renderer](6.1_manifest-remove_output-render_human.md)
- [JSON renderer](6.2_manifest-remove_output-render_json.md)

## Failure Semantics

No command-specific failures exist beyond the shared authorization and gateway
failures in [Common Failures](../../../README.md#common-failures).

## Activity Logging

The gateway logs the API call as a write with type `api:DELETE /manifest`.

## Doctor Relationship

`manifest:remove` changes the release source used by future updates. It does
not verify fleet drift. Run `update:all` to apply the default manifest source,
then run the doctor family that owns any changed artifact.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Manifest/ManifestCommandTest.php` | CLI DELETE request and JSON rendering. |
| `apps/gateway/tests/Feature/Http/Api/ManifestSourceControllerTest.php` | Gateway persistence, authorization, route middleware, and permission attribute. |
