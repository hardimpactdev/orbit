# Technical Contract: `orbit app:register`

**Owner:** `app`.

**Effects:** `write`, `stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The current node identity has `app:register` on the resolved target app node.
- The gateway can reach the target node over SSH.

[Back to the public command page.](../app-register.md)

## Signature

```bash
orbit app:register [name] [--node=] [--path=] [--root=] [--php-version=] [--domain=] [--json]
```

`--repo` is intentionally absent. In the current converted app command surface,
repository URL is metadata that is captured only at creation time by `app:new`.
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
| `--node` | `text` | No default can be resolved. | Existing owner / gateway-resolved default node | Valid node name. |
| `--root` | `text` | Optional | `public` | Path relative to app path. |
| `--php-version` | `text` | Optional | Existing app value; otherwise `8.5` | Supported app runtime container version. This is app runtime configuration, not a host PHP default. |
| `--domain`| `text` | Optional | n/a | Valid hostname. |
| `--json` | `flag` | Optional | `false` | n/a |

## Input Resolution

1. **Resolve App Identity**: Resolve `name` from argument or interactive prompt.
2. **Resolve Target Node**:
   - Explicit `--node`.
   - Existing app owner if `name` is already registered.
   - The CLI's stored `node:default` development node.
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
   - Do not read or inherit any host PHP default; app runtime container
     configuration is the architecture concept.
5. **Validate Eligibility**:
   - Target node must have the active app role required by the requested
     registration mode: `app-dev` when `--domain` is absent,
     `app-prod` when `--domain` is supplied.
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
- **Artifact Apply**: Connects to the node over SSH to:
  - Configure and restart the runtime container for the app.
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
  management. Re-running on an app that is already managed re-renders artifacts and
  verifies the result; if nothing changes, the command still
  succeeds. This verification does not assert application HTTP readiness. A new
  or adopted app may still need project setup steps before it is healthy, and
  durable runtime health belongs to `doctor --family=app`. The outcome layer
  reports which path was taken via `result.action`:
  - `registered` — first-time registration of a known path that is not yet
    managed by Orbit.
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
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

- **Path Collision**: Provided `--path` is already owned by a different
  registered app on the resolved node
  (`error.code=app.path_collision`, `error.meta.path`,
  `error.meta.existing_app`, `error.meta.node`). Fails before any side
  effects. The remediation is to pick a different path or remove the
  existing app first; there is no interactive re-assign prompt.
- **Remote Execution Failures**: SSH timeout before configuration can be written, permission
  denied that prevents Orbit from determining whether configuration was applied, or
  an app-role execution failure that is not convergent
  (`error.code=app.enactment_failed`).
- **Retryable Artifact Drift**: After configuration is durable, runtime container or runtime
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
  standard command failure status (`1`). This command defines no numeric exit codes specific to it.

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
| Subject | `App` when registration resolves to an app row; `none` for validation, authorization, or apply failures before an app row is resolved. |
| Properties | `name` (string or null) and `node` (string or null). No raw path contents, shell command text, node-side output, repository credentials, or secrets. |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Actions/Apps/RegisterAppActionTest.php` | Configuration convergence, adoption logic, path-collision rejection, and apply dispatch. |
| `apps/gateway/tests/Feature/Commands/Apps/AppRegisterCommandTest.php` | Input resolution, authorization failure forwarding, interactive prompting, `result.action` selection across `registered`/`adopted`/`converged` paths, and warning payload shape for `success.meta.warnings[]`. |
| `apps/gateway/tests/Unit/Services/Apps/AppEnactmentServiceTest.php` | SSH-based artifact convergence for runtime container, runtime configuration, and proxy route handoff behavior using mocked node execution. |
| `apps/gateway/tests/E2E/Ephemeral/AppRegistrationTest.php` | Real-node registration, adoption, and idempotent re-apply refresh. |
| `apps/gateway/tests/E2E/Ephemeral/AppProductionActivationTest.php` | DNS/TLS activation retry behavior, including the success-with-`proxy.domain_inactive`-warning path and the hard-error path for malformed domain or registry conflicts. |

Context-specific behavior and test mapping live in:

- [`2_app-register_on-client.md`](2_app-register_on-client.md)
- [`3_app-register_on-gateway-node.md`](3_app-register_on-gateway-node.md)
