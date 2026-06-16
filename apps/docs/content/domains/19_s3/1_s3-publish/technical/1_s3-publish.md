# Technical Contract: `orbit s3:publish [host] [--node=<node>] [--json]`

[Back to public `s3:publish` documentation.](../s3-publish.md)

**Owner:** `s3`.

**Effects:** `write, stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The caller identity is authorized by the gateway for `tool:reconfigure` on
  the selected active s3 node.
- An active router exists.
- An active ingress exists.

## Signature

```bash
orbit s3:publish [host] [--node=<node>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `host` | `argument` | Required in non-interactive mode. | Never. | None. | Public DNS hostname, not a URL, not a wildcard, and not already owned by a non-S3 route. |
| `node` | `--node` | Optional. | Never. | The only visible active s3 node when exactly one exists. | Visible active node with the `s3` role. |
| `json` | `--json` | Optional. | Never. | `false` | Selects the JSON renderer. |

## Input Mode Contracts

- [Interactive input mode](5.1_s3-publish_input-mode_interactive.md)
- [Non-interactive input mode](5.2_s3-publish_input-mode_non-interactive.md)

## Behavior Contract

### Publication Rules

- Resolve the selected active s3 node.
- Validate that an active router exists.
- Validate that an active ingress exists.
- Ensure the selected s3 node has a `seaweedfs` tool row with service-level
  credentials.
- Ensure the router-owned private service route for `https://s3.orbit` exists.
- Ensure the S3 backend pool owned by router contains the selected SeaweedFS
  backend, such as `http://storage-1.s3.orbit:8333`.
- Create or update the S3 public host proxy route on ingress. The ingress route
  forwards to router and must not target the s3 node directly.
- Record the public host in the selected `seaweedfs` tool row configuration.
- Preserve request `Host`, forwarded-proto metadata, and upload-safe proxy
  behavior for large S3 uploads.

Publishing an already-published host for the same selected node is idempotent
and returns `success.meta.action=published` with
`success.meta.already_published=true`.

### Scope Boundaries

`s3:publish` must not create buckets, manage bucket policy, create per-app
credentials, rotate credentials, expose the SeaweedFS console, create wildcard DNS
or TLS, or render role-local Docker Compose.

## Renderer Contracts

- [Human renderer](6.1_s3-publish_output-render_human.md)
- [JSON renderer](6.2_s3-publish_output-render_json.md)

## Failure Semantics

Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Active S3 node required | No visible active s3 node exists, or `--node` does not select one. | `error.code=validation_failed`, `error.meta.field=node`, `error.meta.required_role=s3` |
| Active router required | No active router exists. | `error.code=validation_failed`, `error.meta.field=router`, `error.meta.required_role=router` |
| Active ingress required | No active ingress exists. | `error.code=validation_failed`, `error.meta.field=ingress`, `error.meta.required_role=ingress` |
| Domain conflict | The selected host is owned by a non-S3 proxy route. | `error.code=proxy.domain_conflict` |
| Apply failed | Gateway configuration was written, but ingress or router route apply failed. | `error.code=s3.publish_failed` |

## Doctor Relationship

`s3:publish` changes S3 publication intent stored on the gateway and applies
route artifacts for the command. [`doctor --family=proxy`](../../../8_proxy/proxy-doctor.md)
owns route drift verification and repair after partial apply. [`doctor --family=tool`](../../../3_tool/tool-doctor.md)
owns SeaweedFS tool-row and container drift. [`doctor --family=node`](../../../1_node/node-doctor.md)
owns s3 role assignment readiness.

## Activity Logging

The gateway API emits an activity entry for successful and failed publication
requests.

| Field | Value |
| --- | --- |
| Type | `api:POST /s3/public-hosts` |
| Effect | `write` |
| Subject | The selected s3 node. |
| Properties | `host` and `node`. |
| Description | `derived` |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Commands/S3/S3PublishCommandTest.php` | Input validation, active s3/router/ingress prerequisites, authorization, idempotent publication, proxy route ownership, and apply failure handoff. |
| `apps/gateway/tests/Unit/Services/S3/S3RouteRegistrarTest.php` | In-memory route convergence for public S3 hosts, `s3.orbit`, backend pools, and ingress-to-router placement. |
| `apps/e2e/tests/Feature/Commands/S3IngressRouteTest.php` | Prepared-topology public S3 ingress publication, credentials endpoint metadata, router backend pool shape, and SeaweedFS WireGuard-only bind posture. |
