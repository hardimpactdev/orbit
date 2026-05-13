# Technical Contract: `app:new`

**Owner:** `app`.

**Effects:** `write`, `stream`.

## App-Node Denial

App-node callers are denied by the gateway with
`error.code=caller_role_not_allowed` before prompts or side effects. The CLI
does not perform client-side role detection.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The current node identity is authorized to create apps on the target app node.
- The gateway can reach the target app node over SSH.
- The resolved target node is an active `app` node.

[Back to public page](../app-new.md)

`app:new` is the primary creation command for Orbit applications. It orchestrates
remote source creation over SSH, writes gateway registry configuration, and executes
the app-family registration pipeline.

## Signature

```bash
orbit app:new [name] [--node=<name>] [--repo=<url>] [--root=<path>] [--php-version=<version>] [--domain=<host>] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Input | Type | Required | Default | Constraint |
| --- | --- | --- | --- | --- |
| `name` | string | Always; can be prompted in interactive input mode. | n/a | slug: `^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$`, max 40 chars. Must be globally unique in the gateway app registry. |
| `--node` | string | No | (resolved) | Must be an active `app` node. |
| `--repo` | string | No | null | Full Git repository URL, or GitHub-only `owner/repo` shorthand. No credential discovery, prompting, or forwarding. |
| `--root` | string | No | `public` | App document root relative to app path. |
| `--php-version` | string | No | `8.5` | Must match Orbit's supported PHP version set (gateway-side static check). Node-side availability is verified while applying. |
| `--domain` | string | No | null | Valid production domain; implies production activation. |
| `--json` | flag | No | false | Force non-interactive mode and JSON output. |

### Input Resolution

1. **Node Resolution:** Use explicit `--node`. If missing, use local
   `node:default` development app node if configured. If still missing,
   interactive mode prompts; non-interactive mode fails.
2. **Name Validation:** Validate `name` against the slug regex and length limit.
3. **Collision Check:** Fail if `name` is already taken in the gateway app
   registry. App slugs are globally unique across all app nodes; there is no
   per-node uniqueness namespace and no `--node`-disambiguation prompt.
4. **PHP Validation (gateway-side, static):** Validate `--php-version` against Orbit's
   supported PHP version set. An unsupported value fails before any side
   effects with `error.code=validation_failed` and `error.meta.field=php_version`.
   Node-side availability of the requested PHP runtime is verified while
   applying, not during input resolution.

## Authorization By Caller Role

`app:new` authorization is owned by the gateway. The CLI does not branch on
client-side role detection. The gateway identifies the caller through its
WireGuard peer identity on every API call and applies the app-domain
[Caller Role Rule](../../README.md#caller-role-rule).

| Caller role on gateway | Behavior |
| --- | --- |
| `control` | Allowed. The gateway accepts the request, writes the app record, and applies app-node work over SSH via `RemoteShell`. See [`2_app-new_on-control-node.md`](2_app-new_on-control-node.md). |
| `gateway` | Allowed. Same gateway-side behavior as a control caller. The CLI invocation still calls the gateway API; the gateway then opens SSH back to the target app node via `RemoteShell`. See [`3_app-new_on-gateway-node.md`](3_app-new_on-gateway-node.md). |
| `app` | Rejected by the gateway before prompts, side effects, or registry reads. The CLI surfaces the rejection with `error.code=caller_role_not_allowed`. See [`4_app-new_on-app-node.md`](4_app-new_on-app-node.md). |

Role-specific behavior is defined in these companion contracts:

- [`2_app-new_on-control-node.md`](2_app-new_on-control-node.md)
- [`3_app-new_on-gateway-node.md`](3_app-new_on-gateway-node.md)
- [`4_app-new_on-app-node.md`](4_app-new_on-app-node.md)

## Input Mode Contracts

- [`5.1_app-new_input-mode_interactive.md`](5.1_app-new_input-mode_interactive.md)
- [`5.2_app-new_input-mode_non-interactive.md`](5.2_app-new_input-mode_non-interactive.md)

## Behavior Contract

### 1. Source Creation (Remote)
If `--repo` is supplied, clone the repository into the app path on the target
node. Otherwise, create an empty directory at the app path.
- App path is derived from the app name and the target node's app root.
- All remote work is applied through the gateway over SSH via `RemoteShell`.
- `--repo` accepts either a full Git URL or a GitHub-only `owner/repo` shorthand.
  Shorthand expands to `git@github.com:owner/repo.git`. Full Git URLs are used
  as supplied after validation and may point at any Git host the target app node
  can access.
- Cloning is non-interactive on the target app node and uses whatever
  credentials that node already has provisioned (host SSH keys, machine git
  config, deploy tokens). `app:new` does not prompt for, store, or forward
  git credentials, and does not perform any auth-method discovery flow. A
  clone failure surfaces as a structured source-creation error with a
  `transport=ssh|https` indicator so the operator can address node-side
  credentials directly.
- Source creation happens before the gateway app record is written. If source
  creation fails, `app:new` fails with `app.source_creation_failed`, does not
  create app configuration, and the retry path is to fix the node-side source problem
  and rerun `app:new`.

### 2. Registry Write (Local)
Write authoritative app configuration to the gateway SQLite database:
- `name`, `environment` (production if `--domain` supplied, else development),
  `node_id`, `path`, `document_root`, `php_version`.

### 3. Registration Pipeline
Execute the convergent behavior shared with `app:register`:
- **PHP-FPM:** Render and install PHP-FPM pool configuration on the target node.
- **Proxy Routes:** Create a gateway-owned proxy route for the app.
- **Process Artifacts:** Render and install runtime units (Supervisor programs)
  for any app-owned process definitions already present in gateway configuration.
  `app:new` does not invent undocumented default process definitions.
- **Apply Verification:** Verify that command-owned setup and artifact
  writes completed. This does not assert application HTTP readiness; a new app
  may still need project setup steps before it is healthy, and durable runtime
  health belongs to `doctor --family=app`.

`app:new` does not create schedules. The Laravel scheduler (a per-minute
`php artisan schedule:run`) is added explicitly with `schedule:add` after
the app exists when the operator wants it. Apps that do not run scheduled
work do not need that schedule at all.

### 4. Production Activation
If `--domain` is supplied:
- Record production domain configuration.
- Configure production runtime policy (e.g., user isolation).
- DNS and TLS application are handled by the `proxy` family; `app:new`
  triggers the request.
- If DNS or TLS prerequisites are not yet satisfied (propagation pending,
  certificate not yet issued), the command still completes successfully:
  app configuration and production-domain configuration persist, and the inactive domain is
  reported as a non-fatal warning. Operators retry with
  `app:register [name] --domain=<host>`, which is safe to call repeatedly.
  Hard activation failures unrelated to propagation (malformed domain,
  registry conflict, internal proxy route registry write failure) fail
  validation up front before any side effects and use the `error` envelope.

## Renderer Contracts

- [`6.1_app-new_output-render_human.md`](6.1_app-new_output-render_human.md)
- [`6.2_app-new_output-render_json.md`](6.2_app-new_output-render_json.md)

## Failure Semantics

- **Node Ineligible:** Fails if the resolved node is not an `app` node.
- **Resolution Failure:** Fails if no node can be resolved.
- **Collision:** Fails if the app name is already registered in the gateway
  app registry on any node (`error.code=app.collision`,
  `error.meta.name`, `error.meta.node`).
- **Unsupported PHP Version:** Fails if `--php-version` is not in Orbit's supported
  set (`error.code=validation_failed`, `error.meta.field=php_version`).
- **Transport Error:** Fails if the gateway cannot reach the app node over SSH.
- **Source Creation Failure:** Clone or directory creation failures occur before
  gateway app configuration is written. They use
  `error.code=app.source_creation_failed` with `error.meta.reason` and
  `error.meta.transport=ssh|https` for clone failures so operators can address
  node-side credentials directly. No app row is preserved for this failure.
- **Apply Drift:** If configuration is written but registration (FPM,
  runtime configuration, or proxy handoff) encounters retryable conditions, the
  command reports success and surfaces the drift in `success.meta.warnings[]`
  with a `next_command` handoff (e.g. `doctor --fix --family=app --restore` or
  `app:register [name] --domain=<host>`). Examples include node-side PHP
  version unavailable (`app.php_version_unavailable`) and pending domain
  activation (`proxy.domain_inactive`). Process runtime-unit drift is surfaced
  as process-family warnings such as `process.runtime_backend_unavailable` or
  `process.runtime_unit_missing`.

## Doctor Relationship

- **Family:** `app` (see [`app-doctor.md`](../../app-doctor.md)).
- **Probe:** `doctor --family=app --app=<name>` verifies registry configuration and
  runtime artifacts.
- **Convergence:** `doctor --fix --family=app --restore` repairs missing or divergent
  FPM/runtime configuration.

## Activity Logging

Emitted through the gateway API Loggable contract when the forwarded control
path lands. The initial gateway-local implementation slice is tracked in
`docs/porting/PORTING.md`; API activity emission remains part of the control-forwarding
slice.

| Field | Value |
| --- | --- |
| Type | `api:POST /apps` |
| Effect | `write` |
| Subject | Created `App` when registry configuration is written; `none` for validation, authorization, source-creation, or transport failures before an app row exists. |
| Properties | `name` (string or null), `node` (string or null), `environment` (`development`, `production`, or null), `domain` (string or null), `repository` (boolean), `source_created` (boolean). No secrets, raw repository credentials, SSH command text, or node-side command output. |
| Description | `derived`, for example `"Created app docs on app-1."` |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Apps/AppNewCommandTest.php` | Signature validation, input resolution logic, collision checks, source-creation failure before gateway registry writes, successful gateway registry writes, and warning payload shape for `success.meta.warnings[]`. |
| `tests/Feature/Http/Api/AppStoreControllerTest.php` | Gateway API creation path: access-policy authorization, source creation through gateway-owned `RemoteShell`, registry configuration write after source success, and structured success/error envelopes. |
| `tests/Unit/Actions/Apps/CreateAppActionTest.php` | Internal action logic, default value assignment, and resolution chain. |
| `tests/E2E/Ephemeral/AppNewTest.php` | End-to-end creation of a development app with source directory creation. |
| `tests/E2E/Ephemeral/AppNewProductionTest.php` | End-to-end creation of a production app with domain activation. |
| `tests/E2E/Ephemeral/AppNewRepoTest.php` | End-to-end creation from a git repository. |
