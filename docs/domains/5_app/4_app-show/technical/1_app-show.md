# Technical Contract: `orbit app:show [app]`

[Back to public `app:show` documentation.](../app-show.md)

**Owner:** `app`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The target app is visible to the authenticated WireGuard peer through
  gateway-owned access policy.

**Post-input path eligibility:**
- The resolved app must match an existing app record visible to the caller.

## Signature

```bash
orbit app:show [app] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `app` | `[app]` | When no default can resolve a target in non-interactive input mode; interactive input mode may prompt instead. | Never. | See [Default resolution](5.1_app-show_input-mode_interactive.md#default-resolution). | Must match an existing app name (slug) or app hostname visible to the caller. Name match wins when a string matches both an app name and a different app's hostname. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode according to the shared invocation model in [`docs/domains/README.md`](../../../README.md#invocation-model). |

`app:show` does not accept a `--node` flag. App slugs are globally unique in
the gateway app registry, so the positional already addresses an app uniquely.
Supplying an unknown option fails with `error.code=validation_failed`.

## Input Resolution

1. **Resolve `app_show.app`** from `[app]`, current working directory, or input
   mode.
   - If `[app]` is provided:
     - Check if it matches an app **name (slug)** visible to the caller.
     - If no name match, check if it matches an app **hostname** in
       `proxy` owned by an app visible to the caller.
     - If both match (a name on one app and a hostname on a different app),
       **name match wins**. Hostnames are a convenience addressing form;
       identity slugs are the canonical key.
   - If `[app]` is not provided:
     - Attempt to resolve the app from the current working directory context.
     - Interactive mode prompts if CWD resolution fails. See
       [`5.1_app-show_input-mode_interactive.md`](5.1_app-show_input-mode_interactive.md).
     - Non-interactive mode fails if CWD resolution fails. See
       [`5.2_app-show_input-mode_non-interactive.md`](5.2_app-show_input-mode_non-interactive.md).
2. **Validate result.**
   - Must resolve to exactly one visible app record.
   - The caller must be authorized to inspect the target app through
     gateway-owned access policy.
3. **Select renderer** and begin the read flow.

## Behavior Contract

### App Registry Read Rules

1. **Lookup.** Read the app record from gateway-owned app configuration by the
   resolved name. If no visible app record matches, fail before side effects.
2. **Authorization.** Verify the caller is authorized to inspect the target
   app through gateway-owned access policy. If not authorized, fail before
   side effects.
3. **Result assembly.** Return the app record and the durable gateway configuration
   the app owns:
   - app registry: name, environment, owning node, repository, app path,
     document root, PHP version, primary domain;
   - agent IDE configuration: effective adapter and resolution source;
   - related configuration owned by the app: workspaces, processes, and app-owned
     proxy routes (registry-shaped, not live status).

   Default `app:show` is a registry read, not a live readiness command.

### Scope Boundaries

`app:show` must not:
- Mutate gateway configuration or node state.
- Fix drift or adopt node reality.
- SSH into the owning node directly from the caller.
- Run live runtime container, document-root, route, or process probes.
- Block on slow or unreachable node runtime checks.

## Renderer Contracts

Output renderer behavior is split out of the canonical command contract:

- [`6.1_app-show_output-render_human.md`](6.1_app-show_output-render_human.md):
  no-progress-tree decision, registry detail view, prose errors.
- [`6.2_app-show_output-render_json.md`](6.2_app-show_output-render_json.md):
  JSON envelope, registry-only data shape, error codes, error metadata.

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| App not found | No visible app record matches the resolved name or hostname. | Failure |
| Not authorized | The caller is not allowed to inspect the target app. | Failure |

`app:show` exits zero whenever the registry read succeeds. Runtime drift and
unverifiable live checks are not part of this command's default read path.
Operators who need readiness or drift information should run
`doctor --family=app`.

## Doctor Relationship

- `app:show` is a registry-backed app view. It does not inspect live app
  reality.
- `doctor --family=app` is the convergence interface for app drift and owns
  repair behavior.

See [App Doctor](../../app-doctor.md) for the authoritative app-family probe,
drift, fix, and adopt contract.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed app
registry reads.

| Field | Value |
| --- | --- |
| Type | `api:GET /apps/{app}` |
| Effect | `read` |
| Subject | `App` when the app is visible and resolved; `none` for not-found or hidden app responses. |
| Properties | No command-specific properties. The API activity middleware adds transport context such as method, path, client, and serving gateway node. |
| Description | derived |

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Apps/AppShowCommandTest.php` | Command contract: input resolution (name vs hostname tiebreak), CWD-default fallback chain, app lookup, authorization check, registry-only read behavior, no live probe invocation, read-only guarantee, and failure semantics. |

Input-mode-specific test mapping lives in:

- [`5.1_app-show_input-mode_interactive.md`](5.1_app-show_input-mode_interactive.md#test-mapping)
- [`5.2_app-show_input-mode_non-interactive.md`](5.2_app-show_input-mode_non-interactive.md#test-mapping)

Renderer-specific test mapping lives in:

- [`6.1_app-show_output-render_human.md`](6.1_app-show_output-render_human.md#test-mapping)
- [`6.2_app-show_output-render_json.md`](6.2_app-show_output-render_json.md#test-mapping)
