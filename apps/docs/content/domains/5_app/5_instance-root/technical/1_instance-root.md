# Technical Contract: `orbit instance:root`

[Back to public page](../instance-root.md)

**Owner:** `instance`

**Effects:** `write`

`instance:root` is `Effects: write`, not `Effects: destructive`. It does not invoke
the destructive-consent prompt or require `--force`, including for production
apps with active domains. Cross-cutting "production-write confirmation" is not
an Orbit-wide concept; if it ever becomes one, it must be added to the
architecture and propagated across every app, workspace, proxy, and deployment
command that writes production runtime state.

**Prerequisites:**
- The selected instance must exist in the gateway database.
- Its non-gateway serving node must be reachable through Agent push.
- The caller has `instance:root` on that serving node.

## Signature

```bash
orbit instance:root [instance] [root] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `instance` | `[instance]` | Always. | Never. | None. | Dotted instance selector. A bare logical slug resolves only one eligible visible instance; otherwise fail with `validation_failed`, `meta.reason=instance_required`. Hostnames are invalid. |
| `root` | `[root]` | Always. | Never. | None. | Path relative to the selected instance's path. Must not resolve outside that path; see [Validation](#validation). |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode according to the shared invocation model. |

## Input Resolution

1.  **Resolve `instance`**:
    - From positional argument.
    - In interactive mode, if still missing, prompt with `instance` (Select).
    - If still missing, fail with `error.code=validation_failed` and
      `error.meta.field=instance`.
2.  **Resolve `root`**:
    - From positional argument.
    - In interactive mode, if missing, prompt with `root` (Text).
    - If still missing, fail with `error.code=validation_failed` and
      `error.meta.field=root`.
3.  **Resolve instance:** Resolve the dotted selector, or sole-instance bare
    shorthand. Unknown dotted selectors fail with `instance.not_found`;
    ambiguous bare slugs fail with
    `validation_failed`, `meta.reason=instance_required`.
4.  **Authorize serving node:** Require `instance:root` on the selected instance's
    serving node before mutation.
5.  **Validate `root`** (see [Validation](#validation)).

## Input Mode Contracts

- [Interactive input mode](5.1_instance-root_input-mode_interactive.md)
- [Non-interactive input mode](5.2_instance-root_input-mode_non-interactive.md)

## Validation

`root` validation is two-tier:

1. **Gateway-side, pre-side-effect (input contract).**
   - Reject empty/null inputs and any value that resolves to an absolute path.
   - Resolve `root` lexically against the selected instance path stored by the
     gateway (for example, `Path::canonicalize($instance_path . '/' . $root)`).
     No node transport and no filesystem touch.
   - Require the normalized result to start with `instance_path` (with that path
     itself permitted when `root` is `.`).
   - Failure shape: `error.code=instance.invalid_root` with
     `error.meta.field=root`, `error.meta.root`, `error.meta.resolved_path`,
     `error.meta.instance`, and `error.meta.instance_path`.
2. **Node-side reality (doctor, not this command).**
   - A symlink inside the app path that points outside, or a missing document
     root directory on the node, is detected by the `instance` doctor probe at
     layer 4 (Document root) and surfaced as `instance.root_outside_path` or
     `instance.root_missing` with no `doctor --restore` mapping. Filesystem-level reality
     is not part of `instance:root` input validation.

This split keeps the gateway-owned write fast and registry-shaped while the
filesystem reality stays a doctor-owned convergence concern.

## Behavior Contract

### Instance Root Resolution Rules

`instance:root` is convergent and idempotent. It always re-applies the selected
instance so running it on an instance that is already managed refreshes only
its artifacts even when configuration
is unchanged.

1.  **Write Configuration:** Update `root` in the selected instance's
    driver configuration. The app is unchanged. If the requested root equals the current configuration, the
    configuration write is a no-op and `success.data.result.changed=false`; the command
    still proceeds to artifact re-application.
2.  **Identify Artifacts:** Determine which runtime container and proxy route artifacts
    are affected by the document root change.
3.  **Re-apply Artifacts:**
    - Re-render the affected artifacts using the current configuration.
    - Apply the artifacts to the selected instance's serving node through typed Agent
      push commands. Gateway-owned work, if any, executes locally.
    - The runtime container reload required to pick up the new document root is
      part of this step. It is not a separate user-facing surface; it is the
      apply plumbing for the runtime container artifact, in line with ARCHITECTURE
      Product Principle 5 ("Backend names are not product names"). When an
      instance-owned proxy route references the document root, `instance:root` updates
      the instance configuration and leaves proxy backend artifact convergence to the
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

- `instance.not_found`: The specified dotted instance could not be resolved.
- `validation_failed` with `error.meta.reason=instance_required`: A bare
  logical slug did not resolve exactly one eligible visible instance.
- `instance.invalid_root`: `root` failed gateway-side string validation (resolves
  outside the app path, is empty, or is absolute).
  `error.meta.field=root`, `error.meta.root`, `error.meta.resolved_path`,
  `error.meta.instance`, `error.meta.instance_path`.
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
| `process.runtime_unit_mismatch` | `process` | The instance's concrete runtime container could not be re-applied to match its process-backed runtime definition. |
| `process.runtime_unit_missing` | `process` | The instance's concrete runtime container could not be rendered or started while applying. |
| `instance.runtime_config_mismatch` | `instance` | Managed instance runtime configuration could not be re-applied to match gateway configuration. |
| `instance.runtime_config_missing` | `instance` | Managed instance runtime configuration could not be installed while applying. |

Process warnings use `doctor --family=process --restore`. Managed-configuration
warnings use `doctor --family=instance --instance=<app.instance> --restore`.

`app.creation_failed` and other drift codes specific to this command are
not used. The process family already owns concrete runtime-unit drift, while
the instance family owns managed app runtime configuration.

## Doctor Relationship

- This command updates gateway configuration that is verified by `doctor --family=instance`.
  See [`instance-doctor.md`](../../instance-doctor.md) for the instance-family probe and
  issue-code contract.
- If re-application fails, `doctor --family=process` reports concrete
  `process.runtime_unit_*` drift, while `doctor --family=instance` reports managed
  `instance.runtime_config_*` drift.
- Repairing drift caused by a partial success of `instance:root` uses the Doctor
  family named by each warning: `process` for runtime units and `instance` for
  managed runtime configuration.
- The filesystem reality of the document root (`instance.root_outside_path`,
  `instance.root_missing`) is doctor-owned and never duplicated as `instance:root`
  input validation.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed
document-root updates.

| Field | Value |
| --- | --- |
| Type | `api:POST /instances/{instance}/root` |
| Effect | `write` |
| Subject | Selected `Instance`; `none` before instance resolution. |
| Properties | `app` (string or null), `instance` (string or null), `serving_node` (string or null), and `root` (string or null). No raw shell command text, node-side output, or secrets. |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/App/AppWriteCommandTest.php` | CLI POST/validation, human changed/converged/drift output, and missing `root` validation before gateway. |
| `apps/gateway/tests/Feature/Http/Api/AppRootControllerTest.php` | Gateway API success, authorization, and structured envelopes. |

Root lexical validation unit coverage, `instance.invalid_root` gateway paths, and warning payload shape remain coverage gaps until focused tests land.
