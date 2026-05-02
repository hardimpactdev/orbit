# `app:remove` Technical Contract

**Owner:** `app`.

**Effects:** `destructive`, `write`, `stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The target app exists in the gateway app registry.
- The current node identity is authorized to remove the resolved app.
- The gateway uses SSH to the owning app node for artifact cleanup when
  available. SSH reachability is not a pre-intent prerequisite; if cleanup
  cannot finish after app intent removal, the command succeeds with structured
  warnings.

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

## Caller Role Behavior

`app:remove` is destructive cleanup, not an app-context workflow. App-node
callers are denied before prompts or side effects. Control and gateway callers
may remove an app only when authorized by gateway-owned access policy.

| Caller role | Behavior |
| --- | --- |
| `control` | Forwards the removal request to the gateway over HTTPS through WireGuard when configured and authorized. |
| `gateway` | Executes the removal flow locally on the gateway when authorized. |
| `app` | Denied before prompts or side effects. |
| `unknown` | Invalid local context. Fail before prompts or side effects. |

## Input Resolution

1. **Caller Role:** Resolve local caller role. Deny `app` and `unknown` before
   prompts or side effects.
2. **App Resolution:** Resolve `app` against gateway app registry (name or hostname).
3. **Caller Context:** Identify workspace context to detect self-targeting.
4. **Consent Check:** Identify interactive presence or `--force` flag.

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
- **Step 1: Gateway App Intent:** Delete the gateway app record. This is the point of no return.
- **Step 2: Dependent Intent Cleanup:**
    - Delete app-owned `proxy_route`.
    - Delete app-owned `schedule`.
    - Delete app-owned `workspace` rows.
    - Stop and delete app-owned `process`.
- **Step 3: Node Artifact Cleanup:**
    - Connect to the app node over SSH.
    - Remove app PHP-FPM configuration.
    - Remove managed runtime configuration.
    - Remove the app path only if it was created/managed by Orbit and no other app shares it.
      App-level removal does not honor per-workspace `--keep-files` preferences:
      child workspace worktrees under the removed app path are removed with the
      app path when that path is eligible for deletion.

### 4. Convergence and Drift
- Once gateway intent is removed, the app record is gone from gateway app
  registry scope.
- Remaining Orbit-owned app artifacts that failed to clean up are reported as
  orphaned app drift by [`app-doctor.md`](../../app-doctor.md).
- Artifacts belonging to other families (e.g. leftover workspace files) are reported as drift by their respective family doctors.

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| App not found | `app` does not match an existing app record. Already-absent removal is not idempotent. | Failure (`error.code=app.not_found`). |
| Caller role not allowed | The caller role is not permitted to invoke `app:remove`. | Failure (`error.code=caller_role_not_allowed`). |
| Gateway unavailable | A control caller has no configured gateway or cannot reach the gateway API. | Failure (`error.code=gateway_unavailable`). |
| Authorization failed | A forwarded control caller is not authorized to operate on the resolved app. | Failure (`error.code=authorization_failed`). |
| Step 1 (gateway intent) failure | Deleting the gateway app record itself fails. No dependent or node-side side effects have occurred. | Failure (`error.code=app.removal_failed`). |

Partial cleanup is **not** a command failure. Once Step 1 (gateway app intent
removal) succeeds, the app record is gone from gateway app registry scope by
definition. Any failure during Step 2 (dependent gateway intent) or Step 3
(node-side artifact cleanup) is reported as `success` with a structured warning
per affected family in `success.meta.warnings[]`. Each warning carries `code`,
`family`, `message`, and `next_command` (typically
`doctor --family=<family> --fix`). The exit code remains `0`; the warnings are
the machine-readable signal.

Gateway-owned intent removal is the point of no return. Leftover dependent or
node-side artifacts are convergence drift owned by the affected family doctor,
not a removal failure.

## Doctor Relationship

- Removed apps disappear from `app:list` and `app:show`.
- App-owned artifacts remaining after a failed cleanup are detected as orphaned
  app drift by [`app-doctor.md`](../../app-doctor.md). Related-family artifacts
  are detected by the affected family doctors (`proxy_route`, `workspace`,
  `process`, `schedule`).
- `app:remove` does not duplicate per-family drift item shapes; it points operators at the affected `doctor --family=<family> --fix` via the warning's `next_command`.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Actions/Apps/RemoveAppActionTest.php` | Intent removal, dependent artifact deletion logic, and self-targeting detection. |
| `tests/Unit/Concerns/ResolvesAppFromPathTest.php` | App resolution from name, hostname, and current working directory context. |
| `tests/Feature/Commands/Apps/AppRemoveCallerRoleTest.php` | Control and gateway caller allowance when authorized, app-node caller denial before prompts or side effects, unknown-role failure, and forwarded control caller authorization failure. |
| `tests/E2E/Ephemeral/AppRemoveTest.php` | Real `app:remove` execution with/without `--force`, dependent cleanup verification, JSON envelope validation, and warning payload shape for `success.meta.warnings[]`. |
