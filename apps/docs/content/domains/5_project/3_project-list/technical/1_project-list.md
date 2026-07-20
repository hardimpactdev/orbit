# Technical Contract: `orbit project:list`

[Back to public `project:list` documentation.](../project-list.md)

**Owner:** `project`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The current node identity is authorized to read visible project registry configuration.

## Signature

```bash
orbit project:list [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

No positional input is required. Human interactive mode resolves an app by
selecting a row from the data list. JSON mode returns the inventory directly
without prompting.

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `project` | Laravel Prompts data-list row key | Human interactive output has at least one visible app. | `--json`. | First row is highlighted. | Must be one returned visible project name. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

The command has no node input. Projects are gateway records; concrete node
placement is selected and inspected through instance and workspace
commands.

## Visibility Behavior

Visibility is resolved at the gateway from concrete instance placement and
the project access policy that the gateway owns. A project is returned at most
once.

- Gateway callers receive every project.
- A non-gateway caller receives a project when at least one Orbit instance
  resolves to a serving node on which that caller has `project:read`.
- A project with multiple visible instances is still returned once.
- Workspace counts remain placement-scoped. For a caller outside the gateway,
  the count includes only workspaces whose concrete `app-dev` instance resolves
  to a serving node in the caller's visible app-node set. `app-prod` instances
  never contribute a workspace count.
- An authorized caller whose visible set is empty receives an empty list
  (`success.data.projects=[]` in JSON, `No projects found.` in human output) with
  exit zero.
- A caller whose identity is not authorized to read the project registry at
  all receives `error.code=authorization_failed`.
- Hidden projects are omitted entirely.

## Input Resolution

1. Select the output renderer.
2. Query the gateway for visible project registry configuration.
3. In human interactive mode, render the data list and resolve its selected app
   row key.
4. Open the selected app through the existing `project:show` command.

## Behavior Contract

### App Registry Listing Rules

1. **Query gateway registry.** Read visible project registry configuration scoped to the
   current consuming node's access policy. No host probing is performed.
2. **Resolve project visibility.** Include each project once when the
   caller can inspect at least one concrete Orbit instance. Do not filter or
   sort by the project's default node metadata.
3. **Sort results.** Apps are sorted by project name (ascending,
   case-insensitive). Every output renderer uses this single ordering.
4. **Build compact summaries.** Put `repository`, aggregate dependency-audit
   posture, `instance_count`, and `workspace_count` directly on each project
   row. Gateway callers count every visible instance and every workspace on an
   `app-dev` instance. For callers outside the gateway, count only placements
   they are authorized to see. Do not attach instance/workspace rows or logical
   default-node fields.
5. **Render output.** JSON returns the compact project summaries. Human interactive
   output renders `Laravel\Prompts\datatable` with `Name`, `Repository`,
   `Instances`, and `Workspaces` columns, then opens the selected app's
   `project:show` placement drill-down. Non-interactive human mode fails before
   gateway I/O and directs the caller to `--json`.

### Scope Boundaries

`project:list` must not:
- SSH into nodes.
- Probe host reachability or health.
- Modify gateway configuration or node artifacts.
- Touch downstream family state.
- Resolve the configured default node or caller node as a list filter.

## Renderer Contracts

- [Human renderer](6.1_project-list_output-render_human.md)
- [JSON renderer](6.2_project-list_output-render_json.md)

## Failure Semantics

Standard failures defined in [Common Failures](../../../README.md#common-failures)
apply. Cancelling the human data-list selection returns
`validation_failed` with `error.meta.field=project`.
Requesting human output without an interactive terminal also returns
`validation_failed` with `error.meta.field=project` and directs the caller to
`--json`.

## Doctor Relationship

- `project:list` reports configuration. `doctor --family=instance` verifies reality.
- `project:list` does not expose `--doctor`; live verification belongs to
  `doctor --family=instance`.
- See [`instance-doctor.md`](../../instance-doctor.md) for the authoritative app-family
  probe, drift, fix, and adopt contract.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed
registry reads.

| Field | Value |
| --- | --- |
| Type | `api:GET /projects` |
| Effect | `read` |
| Subject | `none` |
| Properties | No command-specific properties. The API activity middleware adds transport context such as method, path, client, and serving gateway node. |
| Description | derived |

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/App/AppListCommandTest.php` | CLI command contract: global request without node resolution, Laravel Prompts datatable headers and selection, selected-app drill-down, gateway-unavailable failure, and WireGuard-specific failure mapping. |
| `apps/gateway/tests/Feature/Http/Api/AppListControllerTest.php` | Gateway app list API: instance-derived authorization, project uniqueness, compact summary fields, placement-scoped counts, absence of placement rows, and empty result shape. |

Renderer-specific test mapping lives in:

- [`6.1_project-list_output-render_human.md`](6.1_project-list_output-render_human.md#test-mapping)
- [`6.2_project-list_output-render_json.md`](6.2_project-list_output-render_json.md#test-mapping)
