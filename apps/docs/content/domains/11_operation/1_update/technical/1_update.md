# Technical Contract: `orbit update`

[Back to public `update` documentation.](../update.md)

**Owner:** `operation`.

**Effects:** `write`, `local-only`, `stream`.

**Prerequisites:**
- The Orbit install root is writable (`ORBIT_INSTALL_PATH` or `$HOME/orbit`).
- Production artifact installs require a reachable release source (GitHub
  Releases by default, or the `ORBIT_BINARY_URL` override for offline and E2E
  artifact scenarios) plus permission to write the binary and update the
  owner-user local host launcher link.
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
- After a production download, relink the configured owner-user `orbit`
  launcher. The default launcher is `$HOME/.local/bin/orbit`; it points at the
  versioned binary under `<install-root>/bin/`, where `<install-root>` is
  `ORBIT_INSTALL_PATH` when set or `$HOME/orbit` by default.
- Relink only the configured host launcher path. `update` must not rewrite
  unrelated `orbit` executables or symlinks discovered elsewhere in `PATH`.
  Operators who have duplicate or protected unmanaged launchers such as
  `/usr/local/bin/orbit` must remove, relink, or adopt them explicitly through a
  deployment/doctor path; normal `update` does not mutate them implicitly.
- Verify the resolved local Orbit entry point responds to `--version`.
  Production artifact installs verify the updated binary; source-mounted
  Docker/Incus development and E2E lanes verify the resolved
  `/usr/local/bin/orbit -> <source>/apps/cli/orbit` entry point. A failed
  verify step returns failure and identifies the failed step.

### Version check and the gateway-first gate

- `update` always runs a `Checking for updates` step first. It resolves the
  latest available release version from the configured release source without
  downloading the full binary, and compares it to the installed CLI version.
  `ORBIT_RELEASE_MANIFEST_URL` is the first release manifest source when set;
  otherwise the command uses the public GitHub latest-release manifest/API.
- When the installed version already equals the latest release, `update`
  performs no download and returns a success/skip result reporting that the
  current version is already installed.
- When the installed version is newer than the release source, such as during a
  release-candidate rollout while public GitHub releases lag, `update` treats
  the installed version as current and reports that installed version in both
  the check row and skip footer.
- The local CLI version must never exceed the version its gateway runs. When a
  newer release exists, `update` reads the gateway's current version (a
  read-only gateway status query) and only proceeds when the gateway is already
  on that release. When the gateway is still behind, `update` stops after the
  check step and returns a success/skip result directing the operator to update
  the gateway first (via `orbit update:all`). This is the gateway-first version
  gate.
- Reading the gateway version for the gate is the only gateway interaction
  `update` performs. It never mutates gateway fleet configuration and never
  updates other nodes.

<a id="gateway-first-version-gate"></a>

### Update Steps

When the gate allows an update, `update` runs these progress-tree rows:

| Row | Contract |
| --- | --- |
| `Downloading binary` | Downloads the versioned binary asset to a staged path away from the running binary. |
| `Replacing binary` | Moves the verified binary to `<install-root>/bin/orbit-binary-<version>` and relinks the host launcher. |
| `Running doctor` | Runs `orbit doctor` in verify mode for the local node. |

When the versioned binary is already present locally the updater skips the move,
but the public `Replacing binary` row still runs and settles as `Done`.

### Doctor Verification

- After the binary is relinked, `update` runs `orbit doctor` in verify mode for
  the local node and reports the issue count in the `Running doctor` step.
- The doctor step is verification only (read-only); it never repairs drift.
- A non-zero issue count does not fail the update — the binary swap already
  succeeded. The count is operator guidance to run `orbit doctor --fix`.

### Gateway-Service Boundary

- `orbit update` does not replace `orbit-gateway`, update
  `orbit-scheduler` or `orbit-runtime-hibernator`, run gateway migrations, or
  install gateway Composer dependencies.
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

`update` is caller-local. It must not update other nodes, SSH to the gateway or
nodes, mutate gateway fleet configuration, or repair node, app, workspace,
process, proxy route, schedule, tool, or firewall drift.

Reading the gateway's version for the gateway-first gate is the only permitted
gateway status query. The `Running doctor` step is verify-only.

## Renderer Contracts

- [Human renderer](6.1_update_output-render_human.md)
- [JSON renderer](6.2_update_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Update check failed | The latest release version cannot be resolved from the release source. | Failure |
| Binary unavailable | A production artifact download fails or the production release source is unreachable. | Failure |
| Unsupported platform | The host OS/arch is not a supported binary target. | Failure |
| Verify failed | The resolved local Orbit entry point does not respond to `--version`. | Failure |
| Gateway behind (skip) | A newer release exists but the gateway has not updated to it. | Success / skip (no side effect; directs the operator to update the gateway first). |
| Doctor reported drift | The post-update `doctor` verify reports issues. | Success (the update completed; the issue count is surfaced for follow-up). |

## Doctor Relationship

- `update` changes the local Orbit installation, then runs `orbit doctor` in
  verify mode for the local node as its final step and reports the issue count.
- The doctor step verifies only; it does not repair drift or runtime readiness.
- A non-zero issue count does not fail the update. Run `orbit doctor --fix` to
  resolve reported drift.

## Activity Logging

`orbit update` is a caller-local CLI command. It does not call the gateway API
and does not emit a gateway activity entry. Local update attempts are reflected
only in command output and exit status.

## Test Mapping

Primary CLI test owners:

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Operation/UpdateCommandTest.php` | Local update contract: gate outcomes, JSON success/skip and error envelopes, human progress tree, failure prose, and local-installation-unavailable handling. |
| `apps/cli/tests/Feature/Services/Updates/LocalUpdateRunnerTest.php` | Gate decisions (check-failed, already-installed, gateway-behind, proceed), step ordering (check→download→replace→doctor), and result fields (`fromVersion`/`toVersion`/`latestVersion`/`doctorIssues`). |
| `apps/cli/tests/Feature/Services/Updates/GatewayVersionProbeTest.php` | Gateway version read from `/api/status` and unknown-version handling (no gateway, unreachable, unparseable, `0.0.0`). |
| `apps/cli/tests/Feature/Services/Updates/LocalCheckoutUpdaterTest.php` | Local-installation binary download/replace split (`downloadBinary`/`replaceBinary`), source-checkout branch handling, entry-point verification, post-update doctor parsing, and offline proof via `ORBIT_BINARY_URL=file://`. |
| `apps/cli/tests/Feature/Services/Updates/CheckoutPathResolverTest.php` | Local install-root resolution from `ORBIT_INSTALL_PATH`, `HOME/orbit` fallback, and no `phar://` or `base_path()` paths; the historical class name does not narrow the public contract to checkouts. |

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
