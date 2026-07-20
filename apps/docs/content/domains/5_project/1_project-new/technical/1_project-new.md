# Technical Contract: `project:new`

**Owner:** `project`.

**Effects:** `write`, `stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The current node identity has `project:new` on the target node selected to serve
  the first instance, or is the gateway itself.
- The gateway can reach the target node through its selected node execution
  transport.
- The resolved target node is active with the applicable role: `app-dev`
  without `--domain`, or `app-prod` when `--domain` is supplied.

[Back to public page](../project-new.md)

`project:new` is the primary creation command for Orbit applications. It orchestrates
remote source creation, writes gateway registry configuration, and executes
the instance-family registration pipeline.

## Signature

```bash
orbit project:new [name] [--node=<name>] [--repo=<url> | --template-repo=<owner/repo> --new-repo=<owner/repo>] [--root=<path>] [--php-version=<version>] [--runtime-proxy-transport=<http|https>] [--domain=<host>] [--json|--stream-json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Input | Type | Required | Default | Constraint |
| --- | --- | --- | --- | --- |
| `name` | string | Always; can be prompted in interactive input mode. | n/a | slug: `^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$`, max 40 chars. Must be globally unique in the gateway project registry. |
| `--node` | string | No | (resolved) | Must be active with `app-dev`, or with `app-prod` when `--domain` is supplied. |
| `--repo` | string | One source branch is required. | none | Existing repository as a full HTTPS/SSH Git URL, SCP-style `git@host:path`, or GitHub-only `owner/repo` shorthand; rejects embedded credentials, query strings, fragments, whitespace, and control characters; mutually exclusive with `--template-repo` and `--new-repo`. |
| `--template-repo` | string | Required with `--new-repo`. | none | GitHub template repository as `owner/repo`. Mutually exclusive with `--repo`. |
| `--new-repo` | string | Required with `--template-repo`. | none | New GitHub repository as `owner/repo`. Created private from the template using the target node's `gh` authentication. Mutually exclusive with `--repo`. |
| `--root` | string | No | `public` | First-instance document root relative to its path. |
| `--php-version` | string | No | `8.5` | Must match Orbit's supported PHP version set (gateway-side static check). Node-side availability is verified while applying. |
| `--runtime-proxy-transport` | string | No | `http` | FrankenPHP app-dev transport between `orbit-caddy` and the runtime container. Accepted values: `http`, `https`. `https` opts the app into inner TLS on app-dev routes. |
| `--domain` | string | No | null | Valid first-instance production domain; selects the `production` instance name and implies production activation. Without it, the instance name is `development`. |
| `--json` | flag | No | false | Force non-interactive mode and JSON output. |
| `--stream-json` | flag | No | false | Force non-interactive mode and emit newline-delimited progress JSON. Mutually exclusive with `--json`. |

### Input Resolution

1. **Node Resolution:** Use explicit `--node`. If missing, interactive mode
   prompts for a visible active node and preselects the local
   `node:default` node when one is configured. In
   non-interactive mode, use local `node:default` if configured; otherwise
   fail.
2. **Name Validation:** Validate `name` against the slug regex and length
   limit. In interactive mode, this validation happens at the app-name prompt
   after node resolution so the operator can correct the value before repository
   input is requested.
3. **Source Resolution:** Resolve exactly one complete source branch before the
   gateway create request is sent:
   - clone: `--repo`; or
   - new from template: `--template-repo` plus `--new-repo`.
   Interactive mode prompts for the branch and its missing values. Machine
   mode fails before gateway I/O when neither branch, an incomplete branch, or
   both branches are supplied. Template and new repository values must be
   GitHub `owner/repo` identities. Repository credentials remain target-node
   state and are never prompted for or forwarded.
4. **Collision Check:** Fail if `name` is already taken in the gateway app
   registry. Project slugs are globally unique across all nodes; there is no
   per-node uniqueness namespace and no `--node`-disambiguation prompt.
   Path collisions are evaluated against concrete Orbit instances and identify
   each conflicting dotted instance selector and serving node.
5. **PHP Validation (gateway-side, static):** Validate `--php-version` against Orbit's
   supported PHP version set. An unsupported value fails before any side
   effects with `error.code=validation_failed` and `error.meta.field=php_version`.
   Node-side availability of the requested PHP runtime is verified while
   applying, not during input resolution.
6. **Runtime proxy transport validation:** Validate `--runtime-proxy-transport`
   against `http|https`. `http` stores no override and is the default. `https`
   stores PHP/FrankenPHP runtime config that makes app-dev app and workspace
   proxy routes use inner TLS.

## Input Mode Contracts

- [`5.1_project-new_input-mode_interactive.md`](5.1_project-new_input-mode_interactive.md)
- [`5.2_project-new_input-mode_non-interactive.md`](5.2_project-new_input-mode_non-interactive.md)

## Behavior Contract

### 1. Source Creation (Remote)
Apply the source branch resolved before the gateway request:

- **Clone:** clone `--repo` into the app path.
- **New from template:** create `--new-repo` as a private GitHub repository
  from `--template-repo`, then clone the new repository into the app path.

`project:new` never creates an empty app directory. Existing source is adopted with
`instance:register`.

- Instance path is derived from the project name and the target node's app root.
- Remote source creation is applied through authenticated Agent push from the
  gateway to the selected first-instance serving node over WireGuard.
- `--repo` accepts either a full Git URL or a GitHub-only `owner/repo` shorthand.
  GitHub shorthand and GitHub URLs are cloned with `gh repo clone` on the
  target node. Full Git URLs for other hosts are cloned with `git clone` as
  supplied after validation and may point at any Git host the target node
  can access.
- New-from-template mode runs `gh repo create <owner/new> --private --template
  <owner/template>` on the target node before cloning. Both repository values
  are fixed argv values, not interpolated shell fragments.
- Cloning is non-interactive on the target node and uses whatever
  credentials that node already has provisioned. GitHub activity uses the
  node's GitHub CLI authentication; non-GitHub activity uses host SSH keys,
  machine git config, deploy tokens, or other git credentials already present
  on the node. `project:new` does not prompt for, store, or forward git
  credentials, and does not perform any auth-method discovery flow. A clone
  failure surfaces as a structured source-creation error with a
  `transport=github|ssh|https` indicator so the operator can address node-side
  credentials directly.
- GitHub commands are pinned to `github.com`. Git and GitHub CLI prompting are
  disabled so the operation fails instead of blocking when credentials are
  missing on the target node. Repository references with embedded credentials,
  query strings, fragments, whitespace, or control characters fail validation
  before remote source work.
- If the target app path already contains a git checkout whose `origin` matches
  the requested repository, clone mode treats that checkout as complete.
  Template mode additionally verifies the destination repository provenance
  and private visibility before reusing the checkout. Any other existing path
  fails before gateway project configuration is written.
- If template generation succeeded but the clone did not, retry may reuse the
  remote destination only after `gh repo view` reports its
  `templateRepository.nameWithOwner` equals the requested template and its
  visibility is `PRIVATE`. An existing destination with a different or absent
  template identity, or with public visibility, fails rather than being
  silently adopted.
- Source creation happens before the gateway project record is written. If source
  creation fails, `project:new` fails with `project.source_creation_failed`, does not
  create project configuration, and the retry path is to fix the node-side source problem
  and rerun `project:new`.

### 2. Atomic project and instance write (local)

In one gateway database transaction, write:

- project identity and shared runtime policy: `name`, repository,
  `runtime`, `runtime_config`, and `php_version`; and
- one `orbit` instance named `production` when `--domain` is supplied or
  `development` otherwise. Its driver configuration owns `environment`,
  `node`, `path`, `root`, derived URL, and optional domain. The instance stores
  `adopted=false`.

Neither row exists if the transaction fails. The project stores no
placement defaults.

### 3. Registration Pipeline
Execute the convergent behavior shared with `instance:register`:
- **Runtime container:** Render and install runtime container configuration on
  the target node.
- **Proxy Routes:** Create a gateway-owned proxy route for the app.
- **Process Artifacts:** Render and install process runtime units for
  any process definitions already owned by the newly selected instance in
  gateway configuration.
  `project:new` does not invent undocumented default process definitions.
- **Apply Verification:** Verify that command-owned setup and artifact
  writes completed. This does not assert application HTTP readiness; a new app
  may still need project setup steps before it is healthy, and durable runtime
  health belongs to `doctor --family=instance`.

`project:new` does not create schedules. The Laravel scheduler (a per-minute
`php artisan schedule:run`) is added explicitly with
`schedule:add --instance=<project.instance>` after the concrete instance exists when
the operator wants it. Instances that do not run scheduled work do not need
that schedule at all.

### 4. Production Activation
If `--domain` is supplied:
- Record production domain configuration.
- Configure production runtime policy (e.g., user isolation).
- DNS and TLS application are handled by the `proxy` family; `project:new`
  triggers the request.
- If DNS or TLS prerequisites are not yet satisfied (propagation pending,
  certificate not yet issued), the command still completes successfully:
  project and production-instance configuration persist, and the inactive domain is
  reported as a non-fatal warning. Operators retry with
  `instance:register <project>.production --domain=<host>`, which is safe to call repeatedly.
  Hard activation failures unrelated to propagation (malformed domain,
  registry conflict, internal proxy route registry write failure) fail
  validation up front before any side effects and use the `error` envelope.

## Renderer Contracts

- [`6.1_project-new_output-render_human.md`](6.1_project-new_output-render_human.md)
- [`6.2_project-new_output-render_json.md`](6.2_project-new_output-render_json.md)

`--stream-json` uses the shared
[Stream JSON Frames](../../../README.md#stream-json-frames) contract.

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

- **Authorization:** Fails with `error.code=authorization_failed` before remote
  source creation or app writes when the caller lacks `project:new` on the target
  app node. The CLI may resolve its complete interactive input before the
  gateway evaluates that request.
- **Node Ineligible:** Fails if the resolved node is not an `project` node.
- **Resolution Failure:** Fails if no node can be resolved.
- **Logical collision:** Fails if the project name is already registered in the
  gateway project registry (`error.code=project.collision`, `error.meta.name`).
- **Placement collision:** Fails before the atomic registry write when the
  derived path is owned by another instance (`error.code=project.path_collision`,
  `error.meta.path`, `error.meta.existing_instances[]`). Each conflict names
  its dotted selector and serving node.
- **Transport Error:** Fails if the gateway cannot reach the node through its
  selected execution transport.
- **Source Creation Failure:** Template generation or clone failures occur before
  gateway project configuration is written. They use
  `error.code=project.source_creation_failed` with `error.meta.reason` and
  `error.meta.transport=github|ssh|https` so operators can address
  node-side credentials directly. No app row is preserved for this failure.
- **Apply Drift:** If configuration is written but registration (runtime
  container, runtime configuration, or proxy handoff) encounters retryable conditions, the
  command reports success and surfaces the drift in `success.meta.warnings[]`
  with a `next_command` handoff (e.g. `doctor --family=instance
  --instance=<project.instance> --restore` or `instance:register <project.instance>
  --domain=<host>`). Warnings name the selected dotted instance and its serving
  node. Examples include unavailable PHP images on that node
  (`instance.php_version_unavailable`) or domain activation
  (`proxy.domain_inactive`). Process runtime-unit drift is surfaced
  as process-family warnings such as `process.runtime_backend_unavailable` or
  `process.runtime_unit_missing`.

## Doctor Relationship

- **Family:** `project` (see [`instance-doctor.md`](../../instance-doctor.md)).
- **Probe:** `doctor --family=instance --instance=<name>.<development|production>` verifies
  the selected instance's registry configuration and runtime artifacts.
- **Convergence:** `doctor --family=instance --restore` repairs missing or divergent
  runtime container/runtime configuration.

## Activity Logging

Emitted through the gateway API Loggable contract when the forwarded operator
path lands. The initial gateway-local implementation slice is tracked in
`docs/porting/PORTING.md`; API activity emission remains part of the operator-forwarding
slice.

| Field | Value |
| --- | --- |
| Type | `api:POST /projects` |
| Effect | `write` |
| Subject | Created `Project` plus first `AppInstance` when the atomic registry write completes; `none` for failures before both rows exist. |
| Properties | `name` (string or null), `instance` (string or null), `serving_node` (string or null), `environment` (`development`, `production`, or null), `domain` (string or null), `repository` (boolean), `source_created` (boolean). No secrets, raw repository credentials, SSH command text, or node-side command output. |
| Description | `derived`, for example `"Created app docs and instance docs.development on app-1."` |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/App/AppWriteCommandTest.php` | CLI pre-gateway validation, stream payload forwarding, and gateway error pass-through. |
| `apps/cli/tests/Feature/Commands/App/AppNewInteractiveInputModeTest.php` | Exact zero-argument prompt order, both source branches, and slug validation with `--json`. |
| `apps/cli/tests/Feature/Commands/App/AppNewStreamCommandTest.php` | Human progress rendering and final JSON terminal-frame behavior. |
| `apps/cli/tests/Feature/InternalAppSourceCreateCommandTest.php` | Token-gated clone and private template-repository creation, source-shape validation, provenance verification, and retry reuse. |
| `apps/gateway/tests/Feature/Http/Api/AppStoreControllerTest.php` | Gateway API creation: source-plan validation, authorized POST creates source and registry, `project.source_creation_failed`, and warning payloads. |
| `apps/gateway/tests/Feature/Http/Api/AppStoreStreamControllerTest.php` | Gateway-authored progress tree and clone/template target-command options. |
| `packages/core/tests/SourceControl/GitCloneReferenceTest.php` | Shared safe clone-reference forms and rejection of credentials, query strings, fragments, whitespace, and control characters. |

Context-specific behavior and test mapping live in:

- [`2_project-new_on-client.md`](2_project-new_on-client.md)
- [`3_project-new_on-gateway-node.md`](3_project-new_on-gateway-node.md)
