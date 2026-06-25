# Technical Contract: `orbit manifest:update <url>`

[Back to public `manifest:update` documentation.](../manifest-update.md)

**Owner:** `operation`.

**Effects:** `write`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The gateway authorizes the calling WireGuard peer with gateway-admin
  authority (`*` on the active gateway node).

## Signature

```bash
orbit manifest:update <url> [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `url` | `<url>` | Always. | Never. | None. | Non-empty HTTP or HTTPS URL, at most 2048 characters. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Input Resolution

1. Select the output renderer.
2. Resolve `<url>` from the required argument.
3. Send PUT `/api/manifest` with `{ "url": "<url>" }`.

The command has no interactive prompt. Missing or invalid input fails before the
gateway persists a new URL.

## Behavior Contract

### Source Persistence

- Store one custom release manifest URL on the gateway.
- Replace the stored custom URL when one already exists.
- Keep the configured default release manifest URL unchanged.

### Gateway Authority

- Require gateway-admin authority against the active gateway node.

### Update Boundaries

- Do not download or parse the manifest during this command.
- Do not start an update operation.

`update:all` consumes the stored URL later, during its `Checking for updates`
step. If no custom URL exists, the gateway falls back to the configured default
release manifest URL, normally the public GitHub release manifest.

## Renderer Contracts

- [Human renderer](6.1_manifest-update_output-render_human.md)
- [JSON renderer](6.2_manifest-update_output-render_json.md)

## Failure Semantics

This command uses the shared validation, authorization, and gateway failures in
[Common Failures](../../../README.md#common-failures).

## Activity Logging

The gateway logs the API call as a write with type `api:PUT /manifest`.

## Doctor Relationship

`manifest:update` changes the release source used by future updates. It does
not verify fleet drift. Run `update:all` to apply the selected manifest, then
run the doctor family that owns any changed artifact.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Manifest/ManifestCommandTest.php` | CLI request payload, JSON rendering, human rendering, and gateway error preservation. |
| `apps/gateway/tests/Feature/Http/Api/ManifestSourceControllerTest.php` | Gateway persistence, validation, authorization, route middleware, and permission attribute. |
| `apps/gateway/tests/Feature/Services/Operations/ReleaseManifestResolverTest.php` | Stored custom URL is preferred by update-plan manifest resolution. |
