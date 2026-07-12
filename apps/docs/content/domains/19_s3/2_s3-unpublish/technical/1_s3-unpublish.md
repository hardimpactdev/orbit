# Technical Contract: `orbit s3:unpublish [host] [--node=<node>] [--force] [--json|--stream-json]`

[Back to public `s3:unpublish` documentation.](../s3-unpublish.md)

**Owner:** `s3`.

**Effects:** `write, destructive, stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The caller identity is authorized by the gateway for `tool:reconfigure` on
  the selected active s3 node.
- An active router exists.

## Signature

```bash
orbit s3:unpublish [host] [--node=<node>] [--force] [--json|--stream-json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `host` | `argument` | Required in non-interactive mode. | Never. | None. | Published public S3 hostname. |
| `node` | `--node` | Optional. | Never. | The only visible active s3 node when exactly one exists. | Visible active node with the `s3` role. |
| `force` | `--force` | Required in non-interactive mode. | Never. | `false` | Explicit destructive consent. |
| `json` | `--json` | Optional. | Never. | `false` | Selects the JSON renderer. |
| `stream-json` | `--stream-json` | Optional. | Never. | `false` | Selects the stream JSON renderer and non-interactive input mode. Mutually exclusive with `--json`; does not imply destructive consent. |

## Input Mode Contracts

- [Interactive input mode](5.1_s3-unpublish_input-mode_interactive.md)
- [Non-interactive input mode](5.2_s3-unpublish_input-mode_non-interactive.md)

## Behavior Contract

### Removal Rules

- Resolve the selected active s3 node.
- Validate that an active router exists.
- Resolve the selected host from the public S3 hosts recorded on the selected
  `seaweedfs` tool row and S3-owned proxy routes.
- Remove the S3-owned public host route from gateway proxy configuration.
- Remove the public host from the selected `seaweedfs` tool row configuration.
- Apply ingress and router route convergence to stop the public host from
  forwarding to the S3 service.

Removing a host that is already absent from the selected S3 node is idempotent
and returns `success.meta.action=unpublished` with
`success.meta.already_absent=true`.

### Destructive Consent Rules

- Interactive mode requires an explicit confirmation prompt before removal.
- Non-interactive mode requires `--force`.
- `--json` and `--stream-json` do not imply destructive consent.

### Scope Boundaries

`s3:unpublish` must not remove `s3.orbit`, delete the S3 backend pool, rotate
or delete SeaweedFS credentials, delete buckets or objects, remove the s3 role, or
purge the s3 role data path.

## Renderer Contracts

- [Human renderer](6.1_s3-unpublish_output-render_human.md)
- [JSON renderer](6.2_s3-unpublish_output-render_json.md)

`--stream-json` uses the shared
[Stream JSON Frames](../../../README.md#stream-json-frames) contract.

## Failure Semantics

Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Active S3 node required | No visible active s3 node exists, or `--node` does not select one. | `error.code=validation_failed`, `error.meta.field=node`, `error.meta.required_role=s3` |
| Active router required | No active router exists. | `error.code=validation_failed`, `error.meta.field=router`, `error.meta.required_role=router` |
| Destructive consent missing | Non-interactive input omitted `--force`, or the interactive confirmation was rejected. | `error.code=validation_failed`, `error.meta.field=force`, `error.meta.reason=destructive_consent_required` |
| Owned route denied | The selected host resolves to a non-S3 route. | `error.code=proxy.owned_route_denied` |
| Cleanup failed | Gateway configuration was updated, but ingress or router route cleanup failed. | `error.code=s3.unpublish_failed` |

## Doctor Relationship

`s3:unpublish` removes S3 publication intent and applies route cleanup for the
command. [`doctor --family=proxy`](../../../8_proxy/proxy-doctor.md) owns route
drift verification and repair after partial cleanup. [`doctor --family=tool`](../../../3_tool/tool-doctor.md)
owns SeaweedFS tool-row drift. [`doctor --family=node`](../../../1_node/node-doctor.md)
owns s3 role assignment readiness.

## Activity Logging

The gateway API emits an activity entry for successful and failed removal
requests.

| Field | Value |
| --- | --- |
| Type | `api:DELETE /s3/public-hosts/{host}` |
| Effect | `destructive` |
| Subject | The selected s3 node. |
| Properties | `host` and `node`. |
| Description | `derived` |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Commands/S3/S3UnpublishCommandTest.php` | Input validation, active s3/router prerequisites, authorization, destructive consent, idempotent absent behavior, owned-route denial, and cleanup failure handoff. |
| `apps/gateway/tests/Unit/Services/S3/S3RouteRegistrarTest.php` | In-memory removal from S3 public hosts, SeaweedFS config, ingress routes, and router relay intent. |
| `apps/e2e/tests/Feature/Commands/S3IngressRouteTest.php` | Prepared-topology public S3 publication and route placement coverage. Dedicated public host removal E2E assertions are still pending. |
