# Technical Contract: `orbit extension:enable <extension> [--node=<node>] [--gateway] [--json]`

[Back to public `extension:enable` documentation.](../extension-enable.md)

**Owner:** `extension`.

**Effects:** `write`.

**Prerequisites:**
- The extension slug exists in the built-in extension registry.
- Gateway-side enablement requires a reachable gateway and gateway permission
  for extension mutation.

## Signature

```bash
orbit extension:enable <extension> [--node=<node>] [--gateway] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `extension` | Argument `extension` | Always. | Never. | None. | Built-in extension slug. |
| `node` | `--node` | Optional. | Never. | Local caller node. | Only `gateway` is supported in this slice. |
| `gateway` | `--gateway` | Optional. | Never. | `false` | Enables gateway state after local enablement when required; ignored when `--node=gateway` already targets gateway state. |
| `json` | `--json` | Optional. | Never. | `false` | Selects the JSON renderer and non-interactive input mode. |

## Behavior Contract

### Validation

- Reject unknown extension slugs with `extension_unknown`.

### Local Enablement

- With no `--node`, persist local node enablement for the extension.
- After local enablement, query gateway state when possible.
- If gateway state is disabled and `--gateway` is present, call the gateway
  enable API and include `local_enabled=true` in the result.
- If gateway state is disabled and interactive input is available, prompt the
  caller to enable gateway state.
- If gateway state is disabled and input is non-interactive, fail with
  `extension_gateway_enable_required`.

### Gateway Target

- With `--node=gateway`, call `POST /api/extensions/{extension}/enable` and do
  not change local node state.

## API Surface

| Method | Path | Permission | Action |
| --- | --- | --- | --- |
| `POST` | `/api/extensions/{extension}/enable` | `extension:enable` | Enable gateway extension state. |

## Failure Semantics

Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Unknown extension | The slug is not registered. | `error.code=extension_unknown`; `error.meta.extension=<extension>` |
| Unsupported node target | `--node` is present and not `gateway`. | `error.code=extension_node_target_unsupported`; `error.meta.node=<node>` |
| Gateway enable required | Local enablement succeeds, gateway state is disabled, and the caller did not authorize gateway enablement. | `error.code=extension_gateway_enable_required`; `error.meta.extension=<extension>` |

## Doctor Relationship

`extension:enable` mutates explicit extension state. It does not create doctor
issues or run restore flows.

## Activity Logging

| Field | Value |
| --- | --- |
| Type | `api:POST /extensions/{extension}/enable` |
| Effect | `write` |
| Subject | `none`; gateway extension state has no activity subject model. |
| Properties | `extension`. |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Extension/ExtensionCommandTest.php` | Local enablement, gateway enablement, unsupported node target, unknown extension, interactive gateway prompt, and non-interactive gateway-required failure. |
| `apps/gateway/tests/Feature/Http/Api/ExtensionControllerTest.php` | Gateway extension enable API behavior. |
