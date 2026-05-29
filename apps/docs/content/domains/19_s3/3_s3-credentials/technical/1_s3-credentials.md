# Technical Contract: `orbit s3:credentials [--node=<node>] [--json]`

[Back to public `s3:credentials` documentation.](../s3-credentials.md)

**Owner:** `s3`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The caller identity is authorized by the gateway for `tool:credentials` on
  the selected active s3 node.
- An active router exists.

## Signature

```bash
orbit s3:credentials [--node=<node>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `node` | `--node` | Optional. | Never. | The only visible active s3 node when exactly one exists. | Visible active node with the `s3` role. |
| `json` | `--json` | Optional. | Never. | `false` | Selects the JSON renderer. |

## Input Mode Contracts

- [Interactive input mode](5.1_s3-credentials_input-mode_interactive.md)
- [Non-interactive input mode](5.2_s3-credentials_input-mode_non-interactive.md)

## Behavior Contract

### Credential Rules

- Resolve the selected active s3 node.
- Validate that an active router exists.
- Read service-level credentials from the selected node's `rustfs` tool row.
- Return private endpoint metadata for `https://s3.orbit`.
- Return public endpoint metadata for published S3 hosts recorded on the
  selected `rustfs` tool row.
- Return the router-owned backend pool as non-secret diagnostic metadata.

### Scope Boundaries

`s3:credentials` must not probe live RustFS health, rotate credentials, create
buckets, create per-app credentials, change proxy routes, or mutate gateway
state.

## Renderer Contracts

- [Human renderer](6.1_s3-credentials_output-render_human.md)
- [JSON renderer](6.2_s3-credentials_output-render_json.md)

## Failure Semantics

Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Active S3 node required | No visible active s3 node exists, or `--node` does not select one. | `error.code=validation_failed`, `error.meta.field=node`, `error.meta.required_role=s3` |
| Active router required | No active router exists. | `error.code=validation_failed`, `error.meta.field=router`, `error.meta.required_role=router` |
| Credentials missing | The selected `rustfs` tool row has no service-level credentials. | `error.code=s3.credentials_missing` |

## Doctor Relationship

`s3:credentials` reads gateway-owned credential and endpoint state only.
[`doctor --family=tool`](../../../3_tool/tool-doctor.md) owns RustFS
credential drift and repair. [`doctor --family=proxy`](../../../8_proxy/proxy-doctor.md)
owns route and backend-pool drift. [`doctor --family=node`](../../../1_node/node-doctor.md)
owns s3 role assignment readiness.

## Activity Logging

The gateway API emits an activity entry for successful and failed credential
reads. Secret values must not be written to activity properties.

| Field | Value |
| --- | --- |
| Type | `api:GET /s3/credentials` |
| Effect | `read` |
| Subject | The selected s3 node. |
| Properties | `node` only. |
| Description | `derived` |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Commands/S3/S3CredentialsCommandTest.php` | Active s3/router prerequisites, authorization, credential payload, no mutation, missing credential failure, and gateway-unavailable behavior. |
| `apps/e2e/tests/Feature/Commands/S3PrivateRouteTest.php` | Planned private S3 endpoint and credentials coverage once the S3 role runtime exists. |
