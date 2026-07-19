# Technical Contract: `orbit app:instance list|show|add|remove [app]`

[Back to public `app:instance` documentation.](../app-instance.md)

**Owner:** `app`.

**Effects:** `read` for `list` and `show`; `write` for `add` and `remove`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The target app exists in gateway configuration.

**Post-input path eligibility:**
- `list` returns only Orbit instances whose serving nodes grant `app:read`;
  external-driver instances are gateway-only.
- `show` requires `app:read` on the selected Orbit instance's serving node;
  an external-driver instance is gateway-only.
- `add --driver=orbit` requires `app:write` on the explicitly selected target
  node before effects. There is no logical-app default node.
- `add --driver=laravel-cloud` is gateway-only.
- `remove` requires `app:write` on the selected Orbit instance's serving
  node; removing an external-driver instance is gateway-only.

## Signature

```bash
orbit app:instance [action] [app] [--app=<app>] [--node=<node>] [--instance=<name>] [--driver=orbit|laravel-cloud] [--path=<path>] [--root=<root>] [--domain=<domain>] [--cloud-app=<app>] [--cloud-environment=<environment>] [--cloud-application-id=<id>] [--cloud-application-name=<name>] [--cloud-environment-id=<id>] [--cloud-environment-name=<name>] [--cloud-organization-id=<id>] [--cloud-organization-name=<name>] [--php-extension=<extension>] [--force] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Default | Validation |
| --- | --- | --- | --- | --- |
| `action` | `{action}` | Always. | None. | Must be `list`, `show`, `add`, or `remove`. |
| `app` | `[app]` or `--app` | Always. | None. | Selects one existing app. If both are supplied they must match. |
| `instance` | `--instance` | `show`, `add`, `remove`. | None. | Unique within the selected app. |
| `driver` | `--driver` | Optional for `add`. | `orbit`. | Must be `orbit` or `laravel-cloud`. |
| `force` | `--force` | Required for non-interactive `remove`. | `false`. | Explicit destructive consent. |
| `json` | `--json` | Optional. | `false`. | Selects the JSON renderer and non-interactive input mode. |

Driver-specific fields are accepted only by `add`.

Laravel Cloud adapter flows may also send gateway API discovery metadata:
`cloud_default_environment_id` and `cloud_environments[]` with `id` and `name`
fields. These are adapter inputs, not primary manual CLI flags.

## State Model

Gateway-owned `app_instances` rows belong to one app. Each row stores:

| Field | Meaning |
| --- | --- |
| `name` | Instance name unique within the app. |
| `driver` | Instance driver. |
| `driver_config` | Spatie Laravel Data object for the selected driver. |
| `runtime_requirements` | Required PHP extensions and future runtime requirements. |
| `deploy_warmup_paths` | HTTP paths warmed after a successful deployment of this instance. |
| `latest_deployment_status` | Latest deployment status for this instance. |
| `latest_deployment_run_id` | Latest deployment run owned by this instance. |

## API Surface

| Method | Path | Permission | Action |
| --- | --- | --- | --- |
| `GET` | `/api/apps/{app}/instances` | `app:read` | List instances. |
| `POST` | `/api/apps/{app}/instances` | `app:write` | Add an instance. |
| `GET` | `/api/apps/{app}/instances/{instance}` | `app:read` | Show one instance. |
| `DELETE` | `/api/apps/{app}/instances/{instance}` | `app:write` | Remove one instance. |

## Behavior Contract

### Instance Rules

1. **App ownership.** Every instance belongs to one app and cannot be moved
   between apps.
2. **Driver ownership.** Every instance has exactly one driver.
3. **Driver config DTOs.** Driver config is serialized through Laravel Data; do
   not store anonymous arrays as the durable contract.
4. **Extension tracking.** Required PHP extensions are normalized and returned in
   the instance runtime payload.
5. **Destructive remove.** Removing an instance requires `--force` or
   `destructive_consent=true`.
6. **Deployment ownership.** Deployment policy, steps, warmup paths, runs,
   history, logs, and latest status belong to the concrete instance. Logical
   app rows do not carry deployment state.
7. **Placement ownership.** Every `orbit` instance supplies its own node, path,
   root, and optional domain. Instance creation never inherits placement from
   the logical app.

### Laravel Cloud Environment Selection

When adding a `laravel-cloud` instance, an explicit `--cloud-environment`,
`--cloud-environment-id`, or `--cloud-environment-name` wins. When no
environment is supplied but the adapter has discovered existing Cloud
environments, Orbit reuses one in this order:

1. The environment matching `cloud_default_environment_id`.
2. An existing environment named `main`.
3. The only existing environment, when exactly one exists.

If multiple existing environments remain possible, Orbit returns
`validation_failed` with `error.meta.reason=ambiguous_cloud_environment` and the
candidate list. Orbit must not create a new Laravel Cloud environment unless the
operator or agent explicitly requested creation.

## Renderer Contracts

- [Human renderer](6.1_app-instance_output-render_human.md)
- [JSON renderer](6.2_app-instance_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| App not found | No app record matches `app`. | `error.code=app.not_found`. |
| Instance not found | No instance record matches `instance` for the app. | `error.code=app_instance.not_found`. |
| Ambiguous Cloud environment | Laravel Cloud discovery returned multiple candidates and no default or `main` environment could be selected. | `error.code=validation_failed`, `error.meta.field=cloud_environment`, `error.meta.reason=ambiguous_cloud_environment`. |
| Instance authorization denied | The caller lacks the action's permission on the selected or target serving node. | `error.code=authorization_failed` with `missing_permission`, `serving_node`, and `app_instance` when an instance already exists. |
| External instance authorization denied | A non-gateway caller selects a Laravel Cloud instance or tries to add one. | `error.code=authorization_failed` with `reason=gateway_only_external_instance`. |

## Doctor Relationship

[`app-doctor.md`](../../app-doctor.md) reads app-instance runtime requirements.
For Orbit PHP instances with required extensions, `doctor --family=app` reports
missing or unverifiable FrankenPHP extensions through
`app.runtime_extension_missing` and `app.runtime_extensions_unverifiable`.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/App/AppInstanceCommandTest.php` | CLI validation, app selector behavior, gateway forwarding, and destructive consent. |
| `apps/gateway/tests/Feature/AppInstanceControllerTest.php` | API CRUD, driver config validation, runtime metadata, and Laravel Cloud compatibility payloads. |
| `apps/gateway/tests/Feature/AppInstanceModelTest.php` | Model relationships, uniqueness, and Laravel Data driver config casting. |
