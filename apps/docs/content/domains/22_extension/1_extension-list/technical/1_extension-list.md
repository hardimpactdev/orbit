# Technical Contract: `orbit extension:list [--json]`

[Back to public `extension:list` documentation.](../extension-list.md)

**Owner:** `extension`.

**Effects:** `read`.

**Prerequisites:**
- The CLI can read local Orbit configuration.
- Gateway state requires a reachable configured gateway.

## Signature

```bash
orbit extension:list [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `json` | `--json` | Optional. | Never. | `false` | Selects the JSON renderer and non-interactive input mode. |

## Behavior Contract

### Registry and Local State

- Load the built-in extension registry.
- Read local enablement from the caller's node-local CLI configuration.

### Gateway State

- Attempt `GET /api/extensions` to read gateway enablement.
- If the gateway request fails, continue with local state and mark gateway state
  as unknown.

## API Surface

| Method | Path | Permission | Action |
| --- | --- | --- | --- |
| `GET` | `/api/extensions` | `extension:read` | Read gateway extension enablement. |

### Side-Effect Boundary

- Never enable, disable, install, or download an extension.

## Failure Semantics

Standard failures defined in [Common Failures](../../../README.md#common-failures) apply.
There are no command-specific hard failures.

## Doctor Relationship

`extension:list` is a read-only state view. It does not create doctor issues or
repair local or gateway extension state.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Extension/ExtensionCommandTest.php` | Local and gateway extension list output, JSON envelope shape, and gateway-unavailable warning behavior. |
| `apps/gateway/tests/Feature/Http/Api/ExtensionControllerTest.php` | Gateway extension list API shape. |
