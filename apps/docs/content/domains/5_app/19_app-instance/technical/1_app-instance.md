# Technical Contract: `orbit app:instance list|show|add|remove [app]`

[Back to public `app:instance` documentation.](../app-instance.md)

**Owner:** `app`.

**Effects:** `read` for `list` and `show`; `write` for `add` and `remove`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The authenticated peer has `app:read` on the app's default owning node for
  reads.
- The authenticated peer has `app:write` on the app's default owning node for
  writes.
- The target app exists in gateway configuration.

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

## State Model

Gateway-owned `app_instances` rows belong to one app. Each row stores:

| Field | Meaning |
| --- | --- |
| `name` | Instance name unique within the app. |
| `driver` | Instance driver. |
| `driver_config` | Spatie Laravel Data object for the selected driver. |
| `runtime_requirements` | Required PHP extensions and future runtime requirements. |
| `latest_deployment_status` | Reserved for instance-scoped deployment status. |
| `latest_deployment_run_id` | Reserved for instance-scoped deployment history. |

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

## Renderer Contracts

- [Human renderer](6.1_app-instance_output-render_human.md)
- [JSON renderer](6.2_app-instance_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| App not found | No app record matches `app`. | `error.code=app.not_found`. |
| Instance not found | No instance record matches `instance` for the app. | `error.code=app_instance.not_found`. |

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
