# Technical Contract: `orbit update`

[Back to public `update` documentation.](../update.md)

**Owner:** `operation`.

**Effects:** `write`, `local-only`, `stream`.

**Prerequisites:**
- The Orbit install root is writable (`ORBIT_INSTALL_PATH` or `$HOME/orbit`).
- Production artifact installs require a reachable release source (GitHub
  Releases by default, or the `ORBIT_BINARY_URL` override for offline and E2E
  artifact scenarios) plus permission to write the binary and update the
  user-local host launcher link.
- Source-mounted Docker/Incus development and E2E lanes require access to the
  mounted checkout and keep `/usr/local/bin/orbit` pointed at
  `<source>/apps/cli/orbit`.

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
2. Validate the install root and local update prerequisites.
3. Start the local update sequence.

No input-mode-specific contracts are required. The command has no required
fields and does not prompt.

## Behavior Contract

### CLI Binary Update Rules

- Production installs download the prebuilt Orbit CLI binary for this host
  OS/arch from the configured release source. Source-mounted Docker/Incus
  development and E2E lanes keep `/usr/local/bin/orbit` pointed at
  `<source>/apps/cli/orbit` and update by changing the mounted source rather
  than downloading a binary artifact.
- Honor `ORBIT_BINARY_URL` as a full URL override (supports `file://` scheme
  for offline and E2E scenarios that point at a local artifact).
- When `ORBIT_BINARY_URL` is not set, resolve the asset URL from
  `ORBIT_BINARY_BASE_URL/<asset>` (default base:
  `https://github.com/hardimpactdev/orbit/releases/latest/download`).
- Supported asset names: `orbit-macos-arm64` (macOS Apple Silicon) and
  `orbit-linux-x64` (Ubuntu x86_64). Fail with an unsupported-platform error
  when the host OS/arch does not match a supported binary target.
- For production installs, download to `<install-root>/bin/orbit-binary`
  where `<install-root>` is `ORBIT_INSTALL_PATH` when set, or `$HOME/orbit`
  by default.
- After a production download, relink the host `orbit` launcher: `ln -sf
  <install-root>/bin/orbit-binary <link-path>` where `<link-path>` is
  `ORBIT_BIN_PATH` when set, or `$HOME/.local/bin/orbit` by default.
- Verify the resolved local Orbit entry point responds to `--version`.
  Production artifact installs verify the updated binary; source-mounted
  Docker/Incus development and E2E lanes verify the resolved
  `/usr/local/bin/orbit -> <source>/apps/cli/orbit` entry point. A failed
  verify step returns failure and identifies the failed step.

### Gateway-Service Boundary

- `orbit update` does not replace `orbit-gateway`, update
  `orbit-scheduler`, run gateway migrations, or install gateway Composer
  dependencies.
- Gateway service replacement and migrations belong to the durable
  [`orbit update:all`](../../2_update-all/technical/1_update-all.md) runner.
- Source-dev shells may run gateway maintenance commands explicitly, but that
  is not part of this public local update command contract.

### Privilege, version source, and rollback rules

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
| Binary unavailable | A production artifact download fails or the production release source is unreachable. | Failure |
| Unsupported platform | The host OS/arch is not a supported binary target. | Failure |
| Verify failed | The resolved local Orbit entry point does not respond to `--version`. | Failure |

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
| `apps/cli/tests/Feature/Commands/Operation/UpdateCommandTest.php` | Local update contract: step ordering, JSON success and error envelopes, human progress tree, failure prose, binary-unavailable handling, and checkout-unavailable handling. |
| `apps/cli/tests/Feature/Services/Updates/LocalCheckoutUpdaterTest.php` | Production/artifact binary download-and-relink invocation (binary URL, install root, link path), entry-point verification, and gateway-context dependency install/migration execution. Offline proof via `ORBIT_BINARY_URL=file://`. |
| `apps/cli/tests/Feature/Services/Updates/LocalUpdateWorkflowTest.php` | Ordered workflow orchestration, install-root availability detection, binary-unavailable detection, and failed-step metadata. |
| `apps/cli/tests/Feature/Services/Updates/CheckoutPathResolverTest.php` | Install-root resolution from `ORBIT_INSTALL_PATH`, `HOME/orbit` fallback, and no `phar://` or `base_path()` paths. |

There is no gateway-side coverage for this command: the gateway `update`
Artisan command was removed when public command ownership moved to
`apps/cli`, and integrated update coverage lives in the `apps/e2e` runner.

## Named Blocker

**Live `orbit update --check` smoke against a published release** is
PUSH/RELEASE-GATED. The download-and-relink mechanism is proven offline via
`ORBIT_BINARY_URL=file://` (see `LocalCheckoutUpdaterTest`). A live resolution
of `https://github.com/hardimpactdev/orbit/releases/latest/download/<asset>`
requires a published GitHub release. This smoke belongs in the binary
acceptance lane (`apps/e2e` tier 4a) and runs once a release exists — the same
gate as the `--version` binary smoke from `ORBIT-CLI-BINARY-02`.
