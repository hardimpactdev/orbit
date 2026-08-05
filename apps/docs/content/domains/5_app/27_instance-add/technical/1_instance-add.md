# Technical Contract: `orbit instance:add`

[Back to public `instance:add` documentation.](../instance-add.md)

**Owner:** `instance`.

**Effects:** `write`.

**Prerequisites:**
- The target app exists.
- The caller can reach the gateway and authorize the selected placement.

## Signature

```bash
orbit instance:add [instance] [--node=<node>] [--driver=orbit|laravel-cloud] [--path=<path>] [--root=<root>] [--domain=<domain>] [--cloud-app=<app>] [--cloud-environment=<environment>] [--cloud-application-id=<id>] [--cloud-application-name=<name>] [--cloud-environment-id=<id>] [--cloud-environment-name=<name>] [--cloud-organization-id=<id>] [--cloud-organization-name=<name>] [--php-extension=<extension>] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `instance` | `[instance]` | Always. | Never. | None. | Dotted `app.instance` selector. |
| `driver` | `--driver` | Optional. | Never. | `orbit`. | `orbit` or `laravel-cloud`. |
| `node` | `--node` | Orbit driver. | Laravel Cloud driver. | None. | Eligible app-role node. |
| `path` | `--path` | Optional for Orbit driver. | Laravel Cloud driver. | Driver default. | Absolute source path. |
| `root` | `--root` | Optional for Orbit driver. | Laravel Cloud driver. | `public`. | Relative document root. |
| `domain` | `--domain` | Optional. | Never. | None. | Valid hostname. |
| `cloud fields` | `--cloud-*` | Laravel Cloud driver as required by discovery. | Orbit driver. | None. | Consistent Cloud identifiers or names. |
| `php extensions` | `--php-extension` | Optional. | Never. | Empty list. | Repeatable normalized extension names. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer. |

## State Model

The new `Instance` belongs to one `Project` and stores its driver, typed
driver configuration, runtime requirements, and instance-owned defaults.

## API Surface

| Method | Path | Permission | Action |
| --- | --- | --- | --- |
| `POST` | `/api/apps/{app}/instances` | `instance:write` | Add one instance. |

## Behavior Contract

### Instance Creation Rules

1. Reject duplicate instance names within the app.
2. Require explicit Orbit node placement; do not inherit an app node.
3. Serialize driver config through Laravel Data.
4. Normalize repeatable PHP extension requirements.
5. Reuse an unambiguous Laravel Cloud environment; never create one without explicit intent.
6. Leave the app and sibling instances unchanged.

## Renderer Contracts

- [Human renderer](6.1_instance-add_output-render_human.md)
- [JSON renderer](6.2_instance-add_output-render_json.md)

## Failure Semantics

Invalid or duplicate input returns `validation_failed`. Missing apps return
`app.not_found`. Authorization failures identify `instance:write` and the
selected serving node when applicable.

## Doctor Relationship

[`instance-doctor.md`](../../instance-doctor.md) verifies the resulting Orbit
placement and required runtime extensions.

## Activity Logging

| Field | Value |
| --- | --- |
| Type | `api:POST /apps/{app}/instances` |
| Effect | `write` |
| Subject | Target `Project`. |
| Properties | The request and API path identify the created instance and driver. |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/App/AppInstanceCommandTest.php` | CLI add validation and forwarding. |
| `apps/gateway/tests/Feature/InstanceControllerTest.php` | Driver validation, authorization, creation, and payload shape. |
