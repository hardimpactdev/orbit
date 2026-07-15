# Technical Contract: `orbit app:root`

[Back to public page](../app-root.md)

**Owner:** `app`

**Effects:** `write`

`app:root` is `Effects: write`, not `Effects: destructive`. It does not invoke
the destructive-consent prompt or require `--force`, including for production
apps with active domains. Cross-cutting "production-write confirmation" is not
an Orbit-wide concept; if it ever becomes one, it must be added to the
architecture and propagated across every app, workspace, proxy, and deployment
command that writes production runtime state.

**Prerequisites:**
- The application record must exist in the gateway database.
- The owning non-gateway node must be reachable through Agent push.
- The caller has `app:root` on the app's owning node.

## Signature

```bash
orbit app:root [app] [root] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `app` | `[app]` | Always. | Never. | None. | App name or hostname. Must resolve to exactly one app record visible to the caller. |
| `root` | `[root]` | Always. | Never. | None. | Path relative to the app's base path. Must not resolve outside the app path; see [Validation](#validation). |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode according to the shared invocation model. |

## Input Resolution

1.  **Resolve `app`**:
    - From positional argument.
    - In interactive mode, if still missing, prompt with `app` (Select).
    - If still missing, fail with `error.code=validation_failed` and
      `error.meta.field=app`.
2.  **Resolve `root`**:
    - From positional argument.
    - In interactive mode, if missing, prompt with `root` (Text).
    - If still missing, fail with `error.code=validation_failed` and
      `error.meta.field=root`.
3.  **Validate `root`** (see [Validation](#validation)).

## Input Mode Contracts

- [Interactive input mode](5.1_app-root_input-mode_interactive.md)
- [Non-interactive input mode](5.2_app-root_input-mode_non-interactive.md)

## Validation

`root` validation is two-tier:

1. **Gateway-side, pre-side-effect (input contract).**
   - Reject empty/null inputs and any value that resolves to an absolute path.
   - Resolve `root` against the gateway-known `app_path` purely as strings
     (lexical normalization, e.g. `Path::canonicalize($app_path . '/' . $root)`).
     No node transport and no filesystem touch.
   - Require the normalized result to start with `app_path` (with `app_path`
     itself permitted when `root` is `.`).
   - Failure shape: `error.code=app.invalid_root` with
     `error.meta.field=root`, `error.meta.root`, `error.meta.resolved_path`,
     and `error.meta.app_path`.
2. **Node-side reality (doctor, not this command).**
   - A symlink inside the app path that points outside, or a missing document
     root directory on the node, is detected by the `app` doctor probe at
     layer 4 (Document root) and surfaced as `app.root_outside_path` or
     `app.root_missing` with no `doctor --restore` mapping. Filesystem-level reality
     is not part of `app:root` input validation.

This split keeps the gateway-owned write fast and registry-shaped while the
filesystem reality stays a doctor-owned convergence concern.

## Behavior Contract

### App Root Resolution Rules

`app:root` is convergent and idempotent. It always re-applies application so
running it on an app that is already managed refreshes node artifacts even when configuration
is unchanged.

1.  **Write Configuration:** Update the `document_root` field in the application's
    gateway record. If the requested root equals the current configuration, the
    configuration write is a no-op and `success.data.result.changed=false`; the command
    still proceeds to artifact re-application.
2.  **Identify Artifacts:** Determine which runtime container and proxy route artifacts
    are affected by the document root change.
3.  **Re-apply Artifacts:**
    - Re-render the affected artifacts using the current configuration.
    - Apply the artifacts to the concrete app-instance node through typed Agent
      push commands. Gateway-owned work, if any, executes locally.
    - The runtime container reload required to pick up the new document root is
      part of this step. It is not a separate user-facing surface; it is the
      apply plumbing for the runtime container artifact, in line with ARCHITECTURE
      Product Principle 5 ("Backend names are not product names"). When an
      app-owned proxy route references the document root, `app:root` updates
      the app configuration and leaves proxy backend artifact convergence to the
      `proxy` family.
    - `success.meta.artifacts_reenacted` reports whether application found
      observable changes on the node (`true`) or completed as a clean
      idempotent no-op (`false`). Both are success.
4.  **Convergence:** The command is convergent. If application fails after
    configuration has been written, the gateway configuration remains updated, the node
    reality is drifted, and the failure surfaces as a non-fatal warning
    under `success.meta.warnings[]` with structured `code`, `family`,
    `message`, and `next_command` (see
    [Failure Semantics](#failure-semantics)).

## Failure Semantics

Hard errors that cannot be retried through convergence use the `error`
envelope. Drift and retryable apply hiccups during a successful run go
to `success.meta.warnings[]`.

### Errors

- `app.not_found`: The specified application could not be resolved.
- `app.invalid_root`: `root` failed gateway-side string validation (resolves
  outside the app path, is empty, or is absolute).
  `error.meta.field=root`, `error.meta.root`, `error.meta.resolved_path`,
  `error.meta.app_path`.
- `authorization_failed`: The caller does not have permission to manage the
  application.
- `validation_failed`: A supplied input failed gateway-side static
  validation. `error.meta.field` names the offending input.

### Warnings (during a successful run)

Drift surfaces as structured warnings under `success.meta.warnings[]`. Each
entry carries `code`, `family`, `message`, and `next_command`. Warning codes
reuse the vocabulary of the family that owns the drift:

| Code | Family | Meaning |
| --- | --- | --- |
| `process.runtime_unit_mismatch` | `process` | The app instance's concrete runtime container could not be re-applied to match its process-backed runtime definition. |
| `process.runtime_unit_missing` | `process` | The app instance's concrete runtime container could not be rendered or started while applying. |
| `app.runtime_config_mismatch` | `app` | Managed app runtime configuration could not be re-applied to match gateway configuration. |
| `app.runtime_config_missing` | `app` | Managed app runtime configuration could not be installed while applying. |

Process warnings use `doctor --family=process --restore`. Managed-configuration
warnings use `doctor --family=app --app=<app> --restore`.

`app.enactment_failed` and other drift codes specific to this command are
not used. The process family already owns concrete runtime-unit drift, while
the app family owns managed app runtime configuration.

## Doctor Relationship

- This command updates gateway configuration that is verified by `doctor --family=app`.
  See [`app-doctor.md`](../../app-doctor.md) for the app-family probe and
  issue-code contract.
- If re-application fails, `doctor --family=process` reports concrete
  `process.runtime_unit_*` drift, while `doctor --family=app` reports managed
  `app.runtime_config_*` drift.
- Repairing drift caused by a partial success of `app:root` uses the Doctor
  family named by each warning: `process` for runtime units and `app` for
  managed runtime configuration.
- The filesystem reality of the document root (`app.root_outside_path`,
  `app.root_missing`) is doctor-owned and never duplicated as `app:root`
  input validation.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed
document-root updates.

| Field | Value |
| --- | --- |
| Type | `api:POST /apps/{app}/root` |
| Effect | `write` |
| Subject | `App` when the app is resolved and visible; `none` for not-found, validation, or authorization failures before the target app can be logged. |
| Properties | `root` (string or null), the requested document root value after static request normalization. No raw shell command text, node-side output, or secrets. |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/App/AppWriteCommandTest.php` | CLI POST/validation, human changed/converged/drift output, and missing `root` validation before gateway. |
| `apps/gateway/tests/Feature/Http/Api/AppRootControllerTest.php` | Gateway API success, authorization, and structured envelopes. |

Root lexical validation unit coverage, `app.invalid_root` gateway paths, and warning payload shape remain coverage gaps until focused tests land.
