# Technical Contract: `orbit instance:register`

**Owner:** `instance`.

**Effects:** `write`, `stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The current node identity has `instance:register` on the selected instance's
  serving node, or on the proposed serving node for first adoption.
- `app-dev` self-grants provide that permission only for the same node;
  `app-prod` self-grants do not.
- The target non-gateway node is reachable through Agent push.

[Back to the public command page.](../instance-register.md)

## Signature

```bash
orbit instance:register [project] [--node=] [--path=] [--root=] [--php-version=] [--runtime-proxy-transport=] [--domain=] [--json]
```

`--repo` is intentionally absent. In the current converted project and instance command surface,
repository URL is metadata that is captured only at creation time by `project:new`.
`instance:register` re-applies management for an existing path; it never clones,
re-clones, mutates app source, or changes repository metadata. Re-registering an
existing project preserves its stored repository value. Explicitly supplying both
`--node` and `--path` for a dotted instance may move only that instance to the
pre-existing path on another eligible app node. First adoption atomically
creates the project with `repository=null` and its first instance.

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Primitive | Required when | Default | Validation |
| --- | --- | --- | --- | --- |
| `name` | `text` | Always (can be prompted) | n/a | Dotted instance selector for existing state. A bare logical slug resolves only a sole visible instance, or creates the deterministic first instance for a new app. No hostname input. |
| `--path` | `text` | Adopting an unmanaged path. | Selected instance path. | Absolute path on the target node. Must not be owned by a different registered instance on the same node. |
| `--node` | `text` | No default can be resolved. | Selected instance serving node; for first adoption, gateway-resolved default node. | Valid node name. |
| `--root` | `text` | Optional | Selected instance root; otherwise `public`. | Path relative to instance path. |
| `--php-version` | `text` | Optional | Existing app value; otherwise `8.5` | Supported app runtime container version. This is app runtime configuration, not a host PHP default. |
| `--runtime-proxy-transport` | `text` | Optional | Existing app value; otherwise `http` | FrankenPHP app-dev transport between `orbit-caddy` and the runtime container. Accepted values: `http`, `https`. `https` opts the app into inner TLS on app-dev routes. |
| `--domain`| `text` | Optional | Selected instance domain. | Valid hostname. For first adoption it names the instance `production`; otherwise the first instance is `development`. |
| `--json` | `flag` | Optional | `false` | n/a |

## Input Resolution

1. **Resolve Project Instance**: Resolve `name` from argument or interactive prompt.
   A dotted selector resolves that instance. A bare existing logical slug is
   accepted only when exactly one eligible visible instance exists; otherwise
   fail with `validation_failed`, `error.meta.reason=instance_required`.
   A logical slug absent from the registry starts first adoption and derives the
   instance name as `development` without `--domain` or `production` with it.
2. **Resolve Target Node**:
   - Explicit `--node`.
   - Selected instance serving node when it already exists.
   - The CLI's stored `node:default` node.
   - Interactive prompt or non-interactive failure.
3. **Resolve Path**:
   - Explicit `--path`.
   - Selected instance path when it already exists.
   - Non-interactive failure if path is required for adoption.
4. **Resolve PHP version**:
   - Explicit `--php-version`.
   - Existing project PHP version when registered.
   - Orbit's app runtime default (`8.5`) for first-time registration or
     adoption.
   - Do not read or inherit any host PHP default; app runtime container
     configuration is the architecture concept.
5. **Resolve runtime proxy transport**:
   - Explicit `--runtime-proxy-transport`.
   - Existing project runtime config when registered.
   - `http` for first-time registration or adoption.
   - `https` stores PHP/FrankenPHP runtime config that makes app-dev app and
     workspace proxy routes use inner TLS.
6. **Validate Eligibility**:
   - Target node must have the active app role required by the requested
     registration mode: `app-dev` when `--domain` is absent,
     `app-prod` when `--domain` is supplied.
   - Authorization remains grant-scoped to the resolved target node. The
     self-grant for an `app-dev` node authorizes registration only on that same
     node, and does not authorize registering on another app node.
   - Provided `--path` must exist on the target node.
   - An existing selected instance moves only when both `--node` and `--path`
     are explicit. Explicit instance moves require the target
    path to already exist and pass the same path-collision checks as adoption.
   - Provided `--path` on the resolved node must not already be owned by a
     different registered instance. A collision fails before side effects with
     `error.code=project.path_collision` and `error.meta.path`,
     `error.meta.existing_instance`, `error.meta.serving_node`.

## Input Mode Contracts

- [`5.1_instance-register_input-mode_interactive.md`](5.1_instance-register_input-mode_interactive.md)
- [`5.2_instance-register_input-mode_non-interactive.md`](5.2_instance-register_input-mode_non-interactive.md)

## Behavior Contract

### Instance Registration Rules

`instance:register` converges one instance and its node artifacts:

- **Registry Convergence**: Ensures the project owns only identity,
  repository, and shared runtime policy. Ensures the selected instance owns
  node, path, root, URL, domain, environment, and `adopted`. First adoption
  creates both rows atomically.
- **Artifact Apply**: Sends typed apply commands to the concrete instance
  node through Agent push to:
  - Configure and restart the runtime container for the app.
  - Install managed app runtime configuration (e.g., environment files).
  - Ensure instance-owned route configuration exists in `proxy`.
  - Hand proxy backend artifact convergence to the `proxy` family.
- **Production Activation**: If `--domain` is supplied:
  - Verifies DNS records point to the Orbit fleet.
  - Requests/verifies TLS certificates.
  - Updates proxy routes to serve the app on the production domain.
  - If DNS or TLS prerequisites are not yet satisfied (propagation pending,
    certificate not yet issued), the command still completes successfully:
    project and selected-instance configuration persist, and the inactive domain
    is reported as a non-fatal warning under `success.meta.warnings[]` with
    `code=proxy.domain_inactive`, `family=proxy`, and a
    self-pointing `next_command=instance:register <project.instance> --domain=<host>`. The
    retry command is safe to call repeatedly. Hard activation failures
    unrelated to propagation (malformed domain, registry conflict, internal
    proxy route registry write failure) fail validation up front before any
    side effects and use the `error` envelope.
- **Idempotence (Re-apply Refresh)**: `instance:register` always re-applies
  management. Re-running on an instance that is already managed re-renders only its artifacts and
  verifies the result; if nothing changes, the command still
  succeeds. This verification does not assert application HTTP readiness. A new
  or adopted instance may still need project setup steps before it is healthy, and
  durable runtime health belongs to `doctor --family=instance`. The outcome layer
  reports which path was taken via `result.action`:
  - `registered` — first-time registration of a known path that is not yet
    managed by Orbit.
  - `adopted` — first-time registration where the path existed on the node
    but was unmanaged. The durable `instance.adopted` boolean on the instance is
    set to `true` for this run only; subsequent re-runs report
    `result.action=converged` with `instance.adopted=true` preserved.
  - `moved` — explicit re-application of the selected instance to a different
    eligible node/path, requested with both `--node` and `--path`.
  - `converged` — idempotent re-application of an already-managed app where no
    observable artifact change was needed.
  - `partial` — the registry was already managed, but proxy enactment failed
    after recording intent or applying only part of the backend, router, and
    ingress chain. The matching warning names the dotted instance, failed node,
    and operation.
  This separation keeps durable adoption state on the instance while letting
  `result.action` describe what this run did, mirroring the `node:new` and
  `gateway:add` exemplars.

## Renderer Contracts

- [`technical/6.1_instance-register_output-render_human.md`](6.1_instance-register_output-render_human.md)
- [`technical/6.2_instance-register_output-render_json.md`](6.2_instance-register_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

- **Instance required**: A bare existing logical slug resolves zero or
  multiple eligible visible instances. Fail before authorization or effects
  with `error.code=validation_failed`,
  `error.meta.reason=instance_required`.
- **Path Collision**: Provided `--path` is already owned by a different
  registered instance on the resolved node
  (`error.code=project.path_collision`, `error.meta.path`,
  `error.meta.existing_instance`, `error.meta.serving_node`). Fails before any side
  effects. The remediation is to pick a different path or remove the
  existing project first; there is no interactive re-assign prompt.
- **Remote Execution Failures**: Agent-push timeout before configuration can be
  written, permission denied that prevents Orbit from determining whether configuration was applied, or
  an app-role execution failure that is not convergent
  (`error.code=project.creation_failed`).
- **Retryable Artifact Drift**: After configuration is durable, runtime container or runtime
  configuration drift is reported as `success.meta.warnings[]` with singular
  `project.*` or `instance.*` product codes and their matching public family.
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

- `instance:register` resolves drift detected by `doctor --family=instance`.
- Family doctor behavior is documented in [`instance-doctor.md`](../../instance-doctor.md).

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed app
registration attempts.

| Field | Value |
| --- | --- |
| Type | `api:POST /instances/register` |
| Effect | `write` |
| Subject | Selected `AppInstance`, plus its parent `Project` when first adoption creates both; `none` before target resolution. |
| Properties | `project` (string or null), `instance` (string or null), and `serving_node` (string or null). No raw path contents, shell command text, node-side output, repository credentials, or secrets. |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/App/AppWriteCommandTest.php` | CLI payload/validation, human registered/adopted/moved/partial/converged output, and warning pass-through. |
| `apps/gateway/tests/Feature/Http/Api/AppRegisterControllerTest.php` | Register/adopt/converged actions, authorization, and ineligible-node rejection. |
| `apps/gateway/tests/Feature/Actions/Apps/EnactAppRuntimeTest.php` | Agent-push artifact convergence across development and production runtimes. |

Context-specific behavior and test mapping live in:

- [`2_instance-register_on-client.md`](2_instance-register_on-client.md)
- [`3_instance-register_on-gateway-node.md`](3_instance-register_on-gateway-node.md)
