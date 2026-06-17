# Technical Contract: `orbit version`

[Back to public `version` documentation.](../version.md)

**Owner:** `operation`.

**Effects:** `read`, `local-only`.

**Prerequisites:**
- The CLI can read its own configured `app.version`.
- Release metadata is available from public GitHub Releases for full freshness,
  but release lookup failures must not fail the command.

## Signature

```bash
orbit version [--json]
```

The root `orbit --version` and `orbit -V` forms are normalized to this command
before command handling.

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

No required inputs exist; the command takes no positional arguments and all
options are optional.

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer. |

Root `--version` and `-V` invocations are normalized to the first-party
`version` command so Orbit can render release and install metadata. When a real
command name is present, the normal framework global version option remains
available.

## Behavior Contract

### Version Sources

- `version` is the current CLI version from application configuration, which
  is sourced from the monorepo release version.
- `latest_version` is read from
  `https://api.github.com/repos/hardimpactdev/orbit/releases/latest` on a
  best-effort basis. The command uses a short timeout and treats network,
  response, or schema failures as missing metadata.
- `released_at` is the publish timestamp for the installed version. If the
  latest release is the installed version, reuse the latest release timestamp.
  Otherwise, fetch `releases/tags/v<version>` on a best-effort basis.
- `installed_at` is read from `ORBIT_INSTALL_METADATA_PATH` when set, or
  `$HOME/.config/orbit/install.json` by default, only when the metadata version
  matches the installed version. If no matching metadata exists, fall back to
  the linked binary mtime when available.

Install metadata uses this JSON shape:

```json
{
    "schema_version": 1,
    "version": "0.1.105",
    "installed_at": "2026-06-17T10:54:00+00:00",
    "binary_path": "/home/orbit/.local/bin/orbit",
    "install_root": "/home/orbit/orbit"
}
```

The installer and local updater write the metadata only after the target binary
responds to `--version`.

### Scope Boundaries

`version` must not:
- Contact the gateway API.
- Start an operation.
- Mutate local configuration, fleet configuration, or node state.
- Fail merely because public release metadata is temporarily unavailable.

## Renderer Contracts

- [Human renderer](6.1_version_output-render_human.md)
- [JSON renderer](6.2_version_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Release metadata unavailable | GitHub Releases cannot be reached, returns an error, or returns an unexpected body. | Success with `latest_version=null`, `update_available=false`, and unknown release metadata. |
| Install metadata unavailable | No matching install metadata or binary mtime exists. | Success with unknown install metadata. |

## Activity Logging

`orbit version` is a caller-local CLI command. It does not call the gateway API
and does not emit a gateway activity entry.

## Doctor Relationship

- `version` observes local Orbit release metadata.
- It does not verify fleet drift or runtime readiness.
- For drift or runtime readiness questions, run the doctor family that owns the
  changed artifact.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Operation/VersionCommandTest.php` | Human output, update annotation, JSON metadata, and release lookup failure behavior. |
| `apps/cli/tests/Feature/CompatibilityBridgeTest.php` | Root `--version` and `-V` normalization to the first-party command. |
| `apps/cli/tests/Feature/Services/Updates/LocalCheckoutUpdaterTest.php` | Local update writes install metadata after relinking and verifying the host launcher. |
| `apps/gateway/tests/Feature/InstallOrbitLauncherTest.php` | Installer writes install metadata after the binary verifies. |
