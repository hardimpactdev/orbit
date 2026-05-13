# Technical Contract: `orbit app:register`

**Owner:** `app`.

**Effects:** `write`, `stream`.

## App-Node Denial

App-node callers are denied by the gateway with
`error.code=caller_role_not_allowed` before prompts or side effects. The CLI
does not perform client-side role detection.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The current node identity is authorized to inspect or manage the target app or app node.
- The gateway can reach the target app node over SSH.

[Back to the public command page.](../app-register.md)

## Signature

```bash
orbit app:register [name] [--node=] [--path=] [--root=] [--php-version=] [--domain=] [--json]
```

`--repo` is intentionally absent. In the current converted app command surface,
repository URL is creation-time metadata captured only by `app:new`.
`app:register` re-applies management for an existing path; it never clones,
re-clones, mutates app source, or changes repository metadata. Re-registering an
existing app preserves its stored repository value. Adopting an unmanaged path
through `app:register` stores `repository=null`.

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Primitive | Required when | Default | Validation |
| --- | --- | --- | --- | --- |
| `name` | `text` | Always (can be prompted) | n/a | App name slug. |
| `--path` | `text` | Adopting an unmanaged path. | n/a | Absolute path on the target node. Must not be owned by a different registered app on the same node. |
| `--node` | `text` | No default can be resolved. | Existing owner / gateway-resolved default app node | Valid app node name. |
| `--root` | `text` | Optional | `public` | Path relative to app path. |
| `--php-version` | `text` | Optional | Existing app value; otherwise `8.5` | Supported app PHP-FPM version. This is app runtime configuration, not the owning node's CLI PHP default. |
| `--domain`| `text` | Optional | n/a | Valid hostname. |
| `--json` | `flag` | Optional | `false` | n/a |

## Authorization By Caller Role

`app:register` authorization is owned by the gateway. The CLI does not branch
on client-side role detection. The gateway identifies the caller through its
WireGuard peer identity on every API call and applies the app-domain
[Caller Role Rule](../../README.md#caller-role-rule).

| Caller role on gateway | Behavior |
| --- | --- |
| `control` | Allowed. The gateway runs the full registration and apply pipeline, applying app-node artifacts over SSH via `RemoteShell`. |
| `gateway` | Allowed. Same gateway-side behavior as a control caller. The CLI invocation still calls the gateway API; the gateway then opens SSH back to the target app node via `RemoteShell`. |
| `app` | Rejected by the gateway before prompts, side effects, or registry reads, with `error.code=caller_role_not_allowed`. |

See also:
- [`technical/2_app-register_on-control-node.md`](2_app-register_on-control-node.md)
- [`technical/3_app-register_on-gateway-node.md`](3_app-register_on-gateway-node.md)
- [`technical/4_app-register_on-app-node.md`](4_app-register_on-app-node.md)

## Input Resolution

1. **Resolve App Identity**: Resolve `name` from argument or interactive prompt.
2. **Resolve Target Node**:
   - Explicit `--node`.
   - Existing app owner if `name` is already registered.
   - The CLI's stored `node:default` development app node.
   - Interactive prompt or non-interactive failure.
3. **Resolve Path**:
   - Explicit `--path`.
   - Existing app path if `name` is already registered.
   - Non-interactive failure if path is required for adoption.
4. **Resolve PHP version**:
   - Explicit `--php-version`.
   - Existing app PHP version when `name` is already registered.
   - Orbit's app runtime default (`8.5`) for first-time registration or
     adoption.
   - Do not read or inherit the owning node's CLI PHP default; node CLI PHP and
     app PHP-FPM configuration are separate architecture concepts.
5. **Validate Eligibility**:
   - Target node must be an active app node.
   - Provided `--path` must exist on the target node.
   - Provided `name` must not be owned by a different path or node.
   - Provided `--path` on the resolved node must not already be owned by a
     different registered app. A collision fails before side effects with
     `error.code=app.path_collision` and `error.meta.path`,
     `error.meta.existing_app`, `error.meta.node`.

## Input Mode Contracts

- [`5.1_app-register_input-mode_interactive.md`](5.1_app-register_input-mode_interactive.md)
- [`5.2_app-register_input-mode_non-interactive.md`](5.2_app-register_input-mode_non-interactive.md)

## Behavior Contract

### App Registration Rules

`app:register` converges gateway app configuration and node artifacts:

- **Registry Convergence**: Ensures a gateway app record exists with the resolved name, node, path, root, and PHP version.
- **Artifact Apply**: Connects to the app node over SSH to:
  - Configure and restart the PHP-FPM pool for the app.
  - Install managed app runtime configuration (e.g., environment files).
  - Ensure app-owned route configuration exists in `proxy`.
  - Hand proxy backend artifact convergence to the `proxy` family.
- **Production Activation**: If `--domain` is supplied:
  - Verifies DNS records point to the Orbit fleet.
  - Requests/verifies TLS certificates.
  - Updates proxy routes to serve the app on the production domain.
  - If DNS or TLS prerequisites are not yet satisfied (propagation pending,
    certificate not yet issued), the command still completes successfully:
    app configuration and production-domain configuration persist, and the inactive domain
    is reported as a non-fatal warning under `success.meta.warnings[]` with
    `code=proxy.domain_inactive`, `family=proxy`, and a
    self-pointing `next_command=app:register [name] --domain=<host>`. The
    retry command is safe to call repeatedly. Hard activation failures
    unrelated to propagation (malformed domain, registry conflict, internal
    proxy route registry write failure) fail validation up front before any
    side effects and use the `error` envelope.
- **Idempotence (Re-apply Refresh)**: `app:register` always re-applies
  management. Re-running on an already-managed app re-renders artifacts and
  verifies command-owned application; if nothing changes, the command still
  succeeds. This verification does not assert application HTTP readiness. A new
  or adopted app may still need project setup steps before it is healthy, and
  durable runtime health belongs to `doctor --family=app`. The
  command-outcome layer reports which path was taken via `result.action`:
  - `registered` — first-time registration of a previously-known path that
    was not yet managed by Orbit.
  - `adopted` — first-time registration where the path existed on the node
    but was unmanaged. The durable `app.adopted` boolean on the app entity is
    set to `true` for this run only; subsequent re-runs report
    `result.action=converged` with `app.adopted=true` preserved.
  - `converged` — idempotent re-application of an already-managed app where no
    observable artifact change was needed.
  This separation keeps durable adoption state on the app entity while letting
  `result.action` describe what this run did, mirroring the `node:new` and
  `gateway:add` exemplars.

## Renderer Contracts

- [`technical/6.1_app-register_output-render_human.md`](6.1_app-register_output-render_human.md)
- [`technical/6.2_app-register_output-render_json.md`](6.2_app-register_output-render_json.md)

## Failure Semantics

- **Validation Failures**: Invalid names, missing paths, or malformed input
  (`error.code=validation_failed`, `error.meta.field=<name>`).
- **Caller Role Not Allowed**: The caller role is not permitted to invoke
  `app:register` (`error.code=caller_role_not_allowed`).
- **Authorization Failed**: The caller is not authorized to inspect or manage
  the target app or app node (`error.code=authorization_failed`).
- **Path Collision**: Provided `--path` is already owned by a different
  registered app on the resolved node
  (`error.code=app.path_collision`, `error.meta.path`,
  `error.meta.existing_app`, `error.meta.node`). Fails before any side
  effects. The remediation is to pick a different path or remove the
  existing app first; there is no interactive re-assign prompt.
- **Remote Execution Failures**: SSH timeout before configuration can be written, permission
  denied that prevents Orbit from determining whether configuration was applied, or
  another non-convergent app-node execution failure
  (`error.code=app.enactment_failed`).
- **Retryable Artifact Drift**: After configuration is durable, PHP-FPM or runtime
  configuration drift is reported as `success.meta.warnings[]` with singular
  `app.*` product codes and `family: "app"`.
- **Activation Failures**:
  - Hard validation errors (malformed domain, registry conflict, internal
    proxy route registry write failure) fail before side effects with the
    `error` envelope.
  - Propagation-pending DNS or TLS becomes a non-fatal warning under
    `success.meta.warnings[]`; the registration itself succeeds.
- **Exit status**: Uses the shared exit status policy. Success and
  success-with-warnings exit `0`; all documented command failures exit with the
  standard command failure status (`1`). This command defines no
  command-specific numeric exit codes.

## Doctor Relationship

- `app:register` resolves drift detected by `doctor --family=app`.
- Family doctor behavior is documented in [`app-doctor.md`](../../app-doctor.md).

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed app
registration attempts.

| Field | Value |
| --- | --- |
| Type | `api:POST /apps/register` |
| Effect | `write` |
| Subject | `App` when registration resolves to an app row; `none` for validation, caller-role, authorization, or apply failures before an app row is resolved. |
| Properties | `name` (string or null) and `node` (string or null). No raw path contents, shell command text, node-side output, repository credentials, or secrets. |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Actions/Apps/RegisterAppActionTest.php` | Configuration convergence, adoption logic, path-collision rejection, and apply dispatch. |
| `tests/Feature/Commands/Apps/AppRegisterCommandTest.php` | Input resolution, role-based rejection, interactive prompting, `result.action` selection across `registered`/`adopted`/`converged` paths, and warning payload shape for `success.meta.warnings[]`. |
| `tests/Unit/Services/Apps/AppEnactmentServiceTest.php` | SSH-based artifact convergence for PHP-FPM, runtime configuration, and proxy route handoff behavior using mocked node execution. |
| `tests/E2E/Ephemeral/AppRegistrationTest.php` | Real-node registration, adoption, and idempotent re-apply refresh. |
| `tests/E2E/Ephemeral/AppProductionActivationTest.php` | DNS/TLS activation retry behavior, including the success-with-`proxy.domain_inactive`-warning path and the hard-error path for malformed domain or registry conflicts. |
