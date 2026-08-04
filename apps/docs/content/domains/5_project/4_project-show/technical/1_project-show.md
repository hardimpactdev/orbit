# Technical Contract: `orbit project:show [project]`

[Back to public `project:show` documentation.](../project-show.md)

**Owner:** `project`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The target project is visible to the authenticated WireGuard peer through
  gateway-owned access policy.
- A non-gateway caller has `project:read` on at least one concrete Orbit
  instance's serving node. Gateway callers have implicit global visibility.

**Post-input path eligibility:**
- The resolved project must match an existing project record visible to the caller.

## Signature

```bash
orbit project:show [project] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `project` | `[project]` | When no default can resolve a target in non-interactive input mode; interactive input mode may prompt instead. | Never. | See [Default resolution](5.1_project-show_input-mode_interactive.md#default-resolution). | Must match an existing project name (slug) or project-owned hostname visible to the caller. Name match wins when a string matches both a project name and a different project's hostname. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode according to the shared invocation model in [`docs/domains/README.md`](../../../README.md#invocation-model). |

`project:show` does not accept a `--node` flag. Project slugs are globally unique in
the gateway project registry, so the positional already addresses a project uniquely.
Supplying an unknown option fails with `error.code=validation_failed`.

## Input Resolution

1. **Resolve `project_show.project`** from `[project]`, current working directory, or input
   mode.
   - If `[project]` is provided:
     - Check if it matches a project **name (slug)** visible to the caller.
     - If no name match, check if it matches a project-owned **hostname** in
       `proxy` visible to the caller.
     - If both match (a name on one project and a hostname on a different project),
       **name match wins**. Hostnames are a convenience addressing form;
       identity slugs are the canonical key.
   - If `[project]` is not provided:
     - Attempt to resolve the project from the current working directory context.
     - Interactive mode prompts if CWD resolution fails. See
       [`5.1_project-show_input-mode_interactive.md`](5.1_project-show_input-mode_interactive.md).
     - Non-interactive mode fails if CWD resolution fails. See
       [`5.2_project-show_input-mode_non-interactive.md`](5.2_project-show_input-mode_non-interactive.md).
2. **Validate result.**
   - Must resolve to exactly one visible project record.
   - The caller must be authorized to inspect the target project through
     gateway-owned access policy.
3. **Select renderer** and begin the read flow.

## Behavior Contract

### Project Registry Read Rules

1. **Lookup.** Read the project record from gateway-owned project configuration by the
   resolved name. If no visible project record matches, fail before side effects.
2. **Authorization.** Verify the caller is the gateway or is authorized to
   inspect at least one concrete Orbit instance through its serving node. If
   not authorized, fail before side effects.
3. **Result assembly.** Return the project record and the durable gateway configuration
   the project owns:
   - project registry: name, repository, shared runtime policy, and PHP
     version, with no placement defaults;
   - caller-visible instances and their concrete placement fields, ordered
     by instance name;
   - each instance's workspaces, process definitions, and
     WebSocket, analytics, and proxy-route bindings, nested only under that
     instance (registry-shaped, not live status).

   Non-gateway callers receive only Orbit instances whose serving node grants
   `project:read` and workspaces owned by those instances. External driver-backed
   instances have no Orbit serving node and are visible only to gateway callers.

   Workspace expansion is available only for active `app-dev` placements. A
   workspace row attached to `app-prod` is omitted as invalid configuration.
   An `app-prod` caller receives no workspace facts, even when a
   grant that violates current workspace policy makes an `app-dev` instance
   visible; project and instance details remain readable.

   The response has no flat `details.workspaces`, `details.processes`,
   `details.bindings` fallback and no project
   `node`, `path`, `root`, `url`, `domain`, or `environment` field.

   Default `project:show` is a registry read, not a live readiness command.

### Scope Boundaries

`project:show` must not:
- Mutate gateway configuration or node state.
- Fix drift or adopt node reality.
- SSH into any instance serving node directly from the caller.
- Run live runtime container, document-root, route, or process probes.
- Block on slow or unreachable node runtime checks.

## Renderer Contracts

Output renderer behavior is split out of the canonical command contract:

- [`6.1_project-show_output-render_human.md`](6.1_project-show_output-render_human.md):
  no-progress-tree decision, registry detail view, prose errors.
- [`6.2_project-show_output-render_json.md`](6.2_project-show_output-render_json.md):
  JSON envelope, registry-only data shape, error codes, error metadata.

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Instance not found | No visible project record matches the resolved name or hostname. | Failure |
| Not authorized | The caller is not allowed to inspect the target project. | Failure |

`project:show` exits zero whenever the registry read succeeds. Runtime drift and
unverifiable live checks are not part of this command's default read path.
Operators who need readiness or drift information should run
`doctor --family=instance`.

## Doctor Relationship

- `project:show` is a registry-backed project view. It does not inspect live instance
  reality.
- `doctor --family=instance` is the convergence interface for instance drift and owns
  repair behavior.

See [Instance Doctor](../../instance-doctor.md) for the authoritative instance-family probe,
drift, fix, and adopt contract.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed project
registry reads.

| Field | Value |
| --- | --- |
| Type | `api:GET /projects/{project}` |
| Effect | `read` |
| Subject | `Project` when the project is visible and resolved; `none` for not-found or hidden project responses. |
| Properties | No command-specific properties. The API activity middleware adds transport context such as method, path, client, and serving gateway node. |
| Description | derived |

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/App/AppShowCommandTest.php` | CLI JSON pass-through, human summary plus instance/workspace table, instance-scoped dependency labeling, and gateway error pass-through. |
| `apps/gateway/tests/Feature/Http/Api/AppShowControllerTest.php` | Gateway registry details, instance-derived authorization, placement-scoped instance/workspace payloads, URLs, and instance-level dependency posture. |

Input-mode-specific test mapping lives in:

- [`5.1_project-show_input-mode_interactive.md`](5.1_project-show_input-mode_interactive.md#test-mapping)
- [`5.2_project-show_input-mode_non-interactive.md`](5.2_project-show_input-mode_non-interactive.md#test-mapping)

Renderer-specific test mapping lives in:

- [`6.1_project-show_output-render_human.md`](6.1_project-show_output-render_human.md#test-mapping)
- [`6.2_project-show_output-render_json.md`](6.2_project-show_output-render_json.md#test-mapping)
