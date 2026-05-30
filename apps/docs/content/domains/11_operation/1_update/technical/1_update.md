# Technical Contract: `orbit update`

[Back to public `update` documentation.](../update.md)

**Owner:** `operation`.

**Effects:** `write`, `local-only`, `stream`.

**Prerequisites:**
- The Orbit install root is writable (`ORBIT_INSTALL_PATH` or `$HOME/orbit`).
- The configured release source is reachable (GitHub Releases by default, or
  the `ORBIT_BINARY_URL` override for offline and E2E scenarios).
- Docker and the `orbit-runtime` runtime are available for dependency
  installation and migrations.

## Signature

```bash
orbit update [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

No required inputs exist; the command takes no positional arguments and all
options are optional.

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Input Resolution

1. Select the output renderer.
2. Validate the install root and runtime prerequisites.
3. Start the local update sequence.

No input-mode-specific contracts are required. The command has no required
fields and does not prompt.

## Behavior Contract

### CLI Binary Update Rules

- Download the prebuilt Orbit CLI binary for this host OS/arch from the
  configured release source.
- Honor `ORBIT_BINARY_URL` as a full URL override (supports `file://` scheme
  for offline and E2E scenarios that point at a local artifact).
- When `ORBIT_BINARY_URL` is not set, resolve the asset URL from
  `ORBIT_BINARY_BASE_URL/<asset>` (default base:
  `https://github.com/hardimpactdev/orbit/releases/latest/download`).
- Supported asset names: `orbit-macos-arm64` (macOS Apple Silicon) and
  `orbit-linux-x64` (Ubuntu x86_64). Fail with an unsupported-platform error
  when the host OS/arch does not match a supported binary target.
- Download to `<install-root>/bin/orbit-binary` where `<install-root>` is
  `ORBIT_INSTALL_PATH` when set, or `$HOME/orbit` by default.
- After download, relink the host `orbit` launcher: `ln -sf
  <install-root>/bin/orbit-binary <link-path>` where `<link-path>` is
  `ORBIT_BIN_PATH` when set, or `/usr/local/bin/orbit` by default.
- Verify the updated binary responds to `--version`. A failed verify step
  returns failure and identifies the failed step.

### Gateway-Source Update Rules

- Install Composer dependencies inside `orbit-runtime` after the CLI binary
  update succeeds.
- Run Orbit migrations inside `orbit-runtime` after dependencies are installed.
- The gateway continues to run from source mounted into `orbit-runtime`; the
  gateway source update (steps 2 and 3) is separate from and subsequent to the
  CLI binary update.

### Local Migration Rules

- Apply migrations inside `orbit-runtime` with non-interactive production-safe
  semantics.
- When the local installation is a gateway installation, migrations may update
  the gateway database schema.
- Migrations must not create or mutate fleet configuration beyond normal
  schema/data migrations owned by the application version.

### Privilege, Version Source, And Rollback Rules

- Run every step as the current OS user. `update` must not prompt for `sudo`,
  escalate privileges, or rewrite host ownership to make the install root
  writable.
- Treat the configured release source as the version source. The download step
  targets the release source without selecting arbitrary release tags, channels,
  or versions beyond what the configured URL provides.
- Do not perform automatic rollback. If dependency installation or migrations
  fail after the binary download succeeds, report the failed step and leave
  already completed local changes in place so the operator can repair and rerun
  the update.
- Do not hide partial local state behind a success result. Any failed step
  returns failure and identifies the failed step.

### Scope Boundaries

`update` must not:
- Update other nodes.
- SSH to the gateway or nodes.
- Query or mutate gateway fleet configuration as a command behavior.
- Repair node, app, workspace, process, proxy route, schedule, tool, or
  firewall drift.
- Replace `doctor` verification after an update.

## Renderer Contracts

- [Human renderer](6.1_update_output-render_human.md)
- [JSON renderer](6.2_update_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Binary unavailable | The binary download fails or the release source is unreachable. | Failure |
| Unsupported platform | The host OS/arch is not a supported binary target. | Failure |
| Verify failed | The downloaded binary does not respond to `--version`. | Failure |
| Runtime unavailable | Docker or `orbit-runtime` cannot be found or executed. | Failure |
| Dependency install failed | Composer dependency installation inside `orbit-runtime` fails. | Failure |
| Migration failed | Local Orbit migrations inside `orbit-runtime` fail. | Failure |

## Doctor Relationship

- `update` changes the local Orbit installation.
- It does not verify fleet drift or runtime readiness.
- After updating a gateway or node, run the `doctor --family=<family>`
  command for the family whose artifacts or readiness need verification.

## Activity Logging

`orbit update` is a caller-local CLI command. It does not call the gateway API
and does not emit a gateway activity entry. Local update attempts are reflected
only in command output and exit status.

## Test Mapping

Primary CLI test owners:

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Operation/UpdateCommandTest.php` | Local update contract: step ordering, JSON success and error envelopes, human progress tree, failure prose, and binary-unavailable handling. |
| `apps/cli/tests/Feature/Services/Updates/LocalCheckoutUpdaterTest.php` | Download-and-relink step invocation (binary URL, install root, link path), verify step, orbit-runtime dependency install, and migration execution. Offline proof via `ORBIT_BINARY_URL=file://`. |
| `apps/cli/tests/Feature/Services/Updates/LocalUpdateWorkflowTest.php` | Ordered workflow orchestration, binary-unavailable detection, and failed-step metadata. |
| `apps/cli/tests/Feature/Services/Updates/CheckoutPathResolverTest.php` | Install-root resolution from `ORBIT_INSTALL_PATH`, `HOME/orbit` fallback, and no `phar://` or `base_path()` paths. |

Gateway coverage retained for bridged local-update behavior:

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Commands/UpdateCommandTest.php` | Gateway bootstrap implementation for local update command execution. |
| `apps/gateway/tests/Feature/Commands/Operations/UpdateCommandTest.php` | Gateway-local update contract, renderer selection, and failure handling. |
| `apps/gateway/tests/E2E/UpdateTest.php` | Integrated local update behavior inside the gateway topology. |

## Named Blocker

**Live `orbit update --check` smoke against a published release** is
PUSH/RELEASE-GATED. The download-and-relink mechanism is proven offline via
`ORBIT_BINARY_URL=file://` (see `LocalCheckoutUpdaterTest`). A live resolution
of `https://github.com/hardimpactdev/orbit/releases/latest/download/<asset>`
requires a published GitHub release. This smoke belongs in the binary
acceptance lane (`apps/e2e` tier 4a) and runs once a release exists — the same
gate as the `--version` binary smoke from `ORBIT-CLI-BINARY-02`.
