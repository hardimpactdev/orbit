# Technical Contract: `orbit app:list`

[Back to public `app:list` documentation.](../app-list.md)

**Owner:** `app`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The current node identity is authorized to read visible app registry configuration.

## Signature

```bash
orbit app:list [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

No required inputs exist; the command takes no positional arguments and all
options are optional.

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

The command has no node input. Logical apps are gateway records; concrete node
placement is selected and inspected through app-instance and workspace
commands.

## Visibility Behavior

Visibility is resolved at the gateway from concrete app-instance placement and
the app access policy that the gateway owns. A logical app is returned at most
once.

- Gateway callers receive every logical app.
- A non-gateway caller receives a logical app when at least one Orbit instance
  resolves to a serving node on which that caller has `app:read`.
- A logical app with multiple visible instances is still returned once.
- Workspaces remain placement-scoped. Non-gateway callers receive only
  workspaces whose concrete app instance resolves to a serving node included in
  the caller's visible app-node set.
- An authorized caller whose visible set is empty receives an empty list
  (`success.data.apps=[]` in JSON, `No apps found.` in human output) with
  exit zero.
- A caller whose identity is not authorized to read the app registry at
  all receives `error.code=authorization_failed`.
- Hidden apps are omitted entirely.

## Input Resolution

1. Select the output renderer.
2. Query the gateway for visible logical app registry configuration.

## Behavior Contract

### App Registry Listing Rules

1. **Query gateway registry.** Read visible app registry configuration scoped to the
   current consuming node's access policy. No host probing is performed.
2. **Resolve logical-app visibility.** Include each logical app once when the
   caller can inspect at least one concrete Orbit instance. Do not filter or
   sort by the logical app's default node metadata.
3. **Sort results.** Apps are sorted by app name (ascending,
   case-insensitive). Every output renderer uses this single ordering.
4. **Count visible placements.** Each app list result has a parallel inventory
   entry with the number of placement-visible instances and workspaces. Gateway
   callers count every instance and workspace. For a non-gateway caller, the
   count includes Orbit instances on authorized serving nodes and workspaces
   owned by those instances.
5. **Attach visible workspaces.** Each app list result retains the app's
   placement-visible workspaces, sorted by workspace name (ascending,
   case-insensitive), for machine compatibility. Workspaces are registry
   configuration rows; no live workspace probing is performed.
6. **Render output.** Return the filtered app list through the selected output
   renderer.

### Scope Boundaries

`app:list` must not:
- SSH into nodes.
- Probe host reachability or health.
- Modify gateway configuration or node artifacts.
- Touch downstream family state.
- Resolve the configured default node or caller node as a list filter.

## Renderer Contracts

- [Human renderer](6.1_app-list_output-render_human.md)
- [JSON renderer](6.2_app-list_output-render_json.md)

## Failure Semantics

Standard failures defined in [Common Failures](../../../README.md#common-failures)
apply. `app:list` has no command-specific input failures.

## Doctor Relationship

- `app:list` reports configuration. `doctor --family=app` verifies reality.
- `app:list` does not expose `--doctor`; live verification belongs to
  `doctor --family=app`.
- See [`app-doctor.md`](../../app-doctor.md) for the authoritative app-family
  probe, drift, fix, and adopt contract.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed
registry reads.

| Field | Value |
| --- | --- |
| Type | `api:GET /apps` |
| Effect | `read` |
| Subject | `none` |
| Properties | No command-specific properties. The API activity middleware adds transport context such as method, path, client, and serving gateway node. |
| Description | derived |

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/App/AppListCommandTest.php` | CLI command contract: global request without node resolution, DataList inventory rendering, absence of workspace child rows, gateway-unavailable failure, and WireGuard-specific failure mapping. |
| `apps/gateway/tests/Feature/Http/Api/AppListControllerTest.php` | Gateway app list API: instance-derived authorization, logical-app uniqueness, placement-scoped instance/workspace counts, placement-scoped workspace payload, and empty result shape. |

Renderer-specific test mapping lives in:

- [`6.1_app-list_output-render_human.md`](6.1_app-list_output-render_human.md#test-mapping)
- [`6.2_app-list_output-render_json.md`](6.2_app-list_output-render_json.md#test-mapping)
