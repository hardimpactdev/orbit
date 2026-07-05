# Technical Contract: `orbit extension:disable <extension> [--node=<node>] [--node-transport=<transport>] [--json]`

[Back to public `extension:disable` documentation.](../extension-disable.md)

**Owner:** `extension`.

**Effects:** `write`.

**Prerequisites:**
- The extension slug exists in the built-in extension registry.
- Gateway-side disablement requires a reachable gateway and gateway permission
  for extension mutation.

## Signature

```bash
orbit extension:disable <extension> [--node=<node>] [--node-transport=<transport>] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `extension` | Argument `extension` | Always. | Never. | None. | Built-in extension slug. |
| `node` | `--node` | Optional. | Never. | Local caller node. | Only `gateway` is supported in this slice. |
| `node_transport` | `--node-transport` | Optional. | Never. | `auto`. | One of `auto`, `agent-push`, or `transitional-ssh-fallback`. |
| `json` | `--json` | Optional. | Never. | `false` | Selects the JSON renderer and non-interactive input mode. |

## Behavior Contract

### Validation

- Reject unknown extension slugs with `extension_unknown`.

### Local Disablement

- With no `--node`, persist local node disablement for the extension.
- Disabling local state must not delete extension-specific configuration outside
  the extension enablement flag.

### Gateway Target

- With `--node=gateway`, call `POST /api/extensions/{extension}/disable` and
  do not change local node state.
- Disabling gateway state prevents extension routes from running after
  identity and grant checks pass.

## API Surface

| Method | Path | Permission | Action |
| --- | --- | --- | --- |
| `POST` | `/api/extensions/{extension}/disable` | `extension:disable` | Disable gateway extension state. |

## Failure Semantics

Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Unknown extension | The slug is not registered. | `error.code=extension_unknown`; `error.meta.extension=<extension>` |
| Unsupported node target | `--node` is present and not `gateway`. | `error.code=extension_node_target_unsupported`; `error.meta.node=<node>` |

## Doctor Relationship

`extension:disable` mutates explicit extension state. It does not create doctor
issues or run restore flows.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Extension/ExtensionCommandTest.php` | Local disablement, gateway disablement, unsupported node target, and unknown extension. |
| `apps/gateway/tests/Feature/Http/Api/ExtensionControllerTest.php` | Gateway extension disable API behavior. |
