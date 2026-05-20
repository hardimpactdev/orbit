# `app:remove` Technical Contract

**Owner:** `app`.

**Effects:** `destructive`, `write`, `stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The target app exists in the gateway app registry.
- The current node identity is authorized to remove the resolved app.
- The gateway uses SSH to the owning node for artifact cleanup when
  available. SSH reachability is not a pre-configuration prerequisite; if cleanup
  cannot finish after app configuration removal, the command succeeds with structured
  warnings.
- App-role callers are denied by the gateway with `error.code=caller_role_not_allowed` before prompts or side effects.

This is the canonical technical contract for the `app:remove` command. It owns the signature, input resolution, behavior, and failure semantics.

## Signature

`orbit app:remove [app] [--force] [--json]`

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `app` | `[app]` | Always. | Never. | None. | App name or hostname. Must resolve to exactly one gateway app record. |
| `force` | `--force` | Optional. | Never. | `false`. | Explicit destructive consent. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Input Resolution

1. **App Resolution:** Resolve `app` against gateway app registry (name or hostname).
2. **Caller Context:** Identify workspace context to detect self-targeting.
3. **Consent Check:** Identify interactive presence or `--force` flag.

## Input Mode Contracts

- [`5.1_app-remove_input-mode_interactive.md`](5.1_app-remove_input-mode_interactive.md)
- [`5.2_app-remove_input-mode_non-interactive.md`](5.2_app-remove_input-mode_non-interactive.md)

## Behavior Contract

`app:remove` is a destructive-write command with cross-family cleanup.

### 1. Pre-flight
- Resolve target app.
- Check permissions.
- If self-targeting (caller is inside a workspace of the target app), warn the operator.

### 2. Destructive Consent
- Identify confirmation or `--force`.
- Interactive prompt must list major dependent artifacts (proxy routes, workspaces, processes).

### 3. Execution Sequence
- **Step 1: Gateway App Configuration:** Delete the gateway app record. This is the point of no return.
- **Step 2: Dependent Configuration Cleanup:**
    - Delete app-owned proxy route records.
    - Delete app-owned `schedule`.
    - Delete app-owned `workspace` rows.
    - Stop and delete app-owned `process`.
- **Step 3: Node Artifact Cleanup:**
    - Connect to the node over SSH.
    - Remove app PHP-FPM configuration.
    - Remove managed runtime configuration.
    - Remove the app path if it is eligible for deletion (see below).

#### App path deletion eligibility

The app path is removed only when Orbit created or managed it and no other app shares it. App-level removal does not honor per-workspace `--keep-files` preferences. Child workspace worktrees under the removed app path are removed together with the app path.

### 4. Convergence and Drift
- Once gateway configuration is removed, the app record is gone from gateway app
  registry scope.
- Remaining Orbit-owned app artifacts that failed to clean up are reported as
  orphaned app drift by [`app-doctor.md`](../../app-doctor.md).
- Artifacts belonging to other families (e.g. leftover workspace files) are reported as drift by their respective family doctors.

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| App not found | `app` does not match an existing app record. Already-absent removal is not idempotent. | Failure (`error.code=app.not_found`). |
| Step 1 (gateway configuration) failure | Deleting the gateway app record itself fails. No dependent or node-side side effects have occurred. | Failure (`error.code=app.removal_failed`). |

Partial cleanup is **not** a command failure. Once Step 1 (gateway app configuration
removal) succeeds, the app record is gone from gateway app registry scope by
definition. Any failure during Step 2 (dependent gateway configuration) or Step 3
(node-side artifact cleanup) is reported as `success` with a structured warning
per affected family in `success.meta.warnings[]`. Each warning carries `code`,
`family`, `message`, and `next_command` (typically
`doctor --fix --family=<family> --restore`). The exit code remains `0`; the warnings are
the machine-readable signal.

Gateway-owned configuration removal is the point of no return. Leftover dependent or
node-side artifacts are convergence drift owned by the affected family doctor,
not a removal failure.

## Doctor Relationship

- Removed apps disappear from `app:list` and `app:show`.
- App-owned artifacts remaining after a failed cleanup are detected as orphaned
  app drift by [`app-doctor.md`](../../app-doctor.md). Related-family artifacts
  are detected by the affected family doctors (`proxy`, `workspace`,
  `process`, `schedule`).
- `app:remove` does not duplicate drift item shapes for each family; it points operators at the affected `doctor --fix --family=<family> --restore` via the warning's `next_command`.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed app
removal attempts.

| Field | Value |
| --- | --- |
| Type | `api:DELETE /apps/{app}` |
| Effect | `destructive` |
| Subject | `App` when the app is resolved before deletion; `none` for not-found, caller-role, or authorization failures before the target app can be logged. |
| Properties | `name` (string), the requested route selector for the app being removed. No raw shell command text, node-side output, or secrets. |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Actions/Apps/RemoveAppActionTest.php` | Configuration removal, dependent artifact deletion logic, and self-targeting detection. |
| `tests/Unit/Concerns/ResolvesAppFromPathTest.php` | App resolution from name, hostname, and current working directory context. |
| `tests/Feature/Commands/Apps/AppRemoveCallerRoleTest.php` | Control and gateway caller allowance when authorized, app-role caller denial before prompts or side effects, and forwarded caller authorization failure. |
| `tests/E2E/Ephemeral/AppRemoveTest.php` | Real `app:remove` execution with/without `--force`, dependent cleanup verification, JSON envelope validation, and warning payload shape for `success.meta.warnings[]`. |
