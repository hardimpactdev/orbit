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

No positional input is required. Human interactive mode resolves an app by
selecting a row from the data list. JSON mode returns the inventory directly
without prompting.

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `app` | Laravel Prompts data-list row key | Human interactive output has at least one visible app. | `--json`. | First row is highlighted. | Must be one returned visible app name. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

The command has no node input. Apps are gateway records; concrete node
placement is selected and inspected through instance and workspace
commands.

## Visibility Behavior

Visibility is resolved at the gateway from concrete instance placement and
the app access policy that the gateway owns. An app is returned at most
once.

- Gateway callers receive every app.
- A non-gateway caller receives an app when at least one Orbit instance
  resolves to a serving node on which that caller has `app:read`.
- An app with multiple visible instances is still returned once.
- Workspace counts remain placement-scoped. For a caller outside the gateway,
  the count includes only workspaces whose concrete `app-dev` instance resolves
  to a serving node in the caller's visible app-node set. `app-prod` instances
  never contribute a workspace count.
- An authorized caller whose visible set is empty receives an empty list
  (`success.data.apps=[]` in JSON, `No apps found.` in human output) with
  exit zero.
- A caller whose identity is not authorized to read the app registry at
  all receives `error.code=authorization_failed`.
- Hidden apps are omitted entirely.

## Input Resolution

1. Select the output renderer.
2. Query the gateway for visible app registry configuration.
3. In human interactive mode, render the data list and resolve its selected app
   row key.
4. Open the selected app through the existing `app:show` command.

## Behavior Contract

### App Registry Listing Rules

1. **Query gateway registry.** Read visible app registry configuration scoped to the
   current consuming node's access policy. No host probing is performed.
2. **Resolve app visibility.** Include each app once when the
   caller can inspect at least one concrete Orbit instance. Do not filter or
   sort by the app's default node metadata.
3. **Sort results.** Apps are sorted by app name (ascending,
   case-insensitive). Every output renderer uses this single ordering.
4. **Build compact summaries.** Put `repository`, aggregate dependency-audit
   posture, `instance_count`, and `workspace_count` directly on each app
   row. Gateway callers count every visible instance and every workspace on an
   `app-dev` instance. For callers outside the gateway, count only placements
   they are authorized to see. Do not attach instance/workspace rows or logical
   default-node fields.
5. **Render output.** JSON returns the compact app summaries. Human interactive
   output renders `Laravel\Prompts\datatable` with `Name`, `Repository`,
   `Instances`, and `Workspaces` columns, then opens the selected app's
   `app:show` placement drill-down. Non-interactive human mode fails before
   gateway I/O and directs the caller to `--json`.

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
apply. Cancelling the human data-list selection returns
`validation_failed` with `error.meta.field=app`.
Requesting human output without an interactive terminal also returns
`validation_failed` with `error.meta.field=app` and directs the caller to
`--json`.

## Doctor Relationship

- `app:list` reports configuration. `doctor --family=instance` verifies reality.
- `app:list` does not expose `--doctor`; live verification belongs to
  `doctor --family=instance`.
- See [`instance-doctor.md`](../../instance-doctor.md) for the authoritative instance-family
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
| `apps/cli/tests/Feature/Commands/App/AppListCommandTest.php` | CLI command contract: global request without node resolution, Laravel Prompts datatable headers and selection, selected-app drill-down, gateway-unavailable failure, and WireGuard-specific failure mapping. |
| `apps/gateway/tests/Feature/Http/Api/AppListControllerTest.php` | Gateway app list API: instance-derived authorization, app uniqueness, compact summary fields, placement-scoped counts, absence of placement rows, and empty result shape. |

Renderer-specific test mapping lives in:

- [`6.1_app-list_output-render_human.md`](6.1_app-list_output-render_human.md#test-mapping)
- [`6.2_app-list_output-render_json.md`](6.2_app-list_output-render_json.md#test-mapping)
