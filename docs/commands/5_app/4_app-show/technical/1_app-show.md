# Technical Contract: `orbit app:show [app]`

[Back to public `app:show` documentation.](../app-show.md)

**Owner:** `app`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The local caller role can be resolved according to the foundation
  [local node role setting](../../../../BLUEPRINT.md#local-node-role-setting)
  contract.
- The target app is visible to the current node identity through gateway-owned
  access policy.

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
| `app` | `[app]` | When no default or local context can resolve a target in non-interactive input mode; interactive input mode may prompt instead. | Never. | See [Default resolution](5.1_app-show_input-mode_interactive.md#default-resolution). | Must match an existing app name (slug) or app hostname visible to the caller. Name match wins when a string matches both an app name and a different app's hostname. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode according to the shared invocation model in [`docs/commands/README.md`](../../../README.md#invocation-model). |

`app:show` does not accept a `--node` flag. App slugs are globally unique in
the gateway app registry, so the positional already addresses an app uniquely.
Supplying an unknown option fails with `error.code=validation_failed`.

## Caller Role Behavior

`app:show` behavior is access-policy-driven, not role-driven. App-node callers
may inspect apps they are authorized to see through gateway-owned access
policy. No role-specific companion contracts are needed.

| Caller role | Behavior |
| --- | --- |
| `control` | Forwards the request to the gateway over HTTPS through WireGuard when configured. |
| `gateway` | Executes locally on the gateway. |
| `app` | Forwards the request to the gateway over HTTPS through WireGuard. May inspect apps visible through access policy. |
| `unknown` | Invalid local context. Used only when `general.local_node_role` contains an unsupported value or cannot be read. Fail before prompts or side effects with a local context error. Missing `general.local_node_role` does not produce `unknown`; it defaults to `control`. |

## Input Resolution

1. **Resolve caller role** from the local node role setting.
   - If `general.local_node_role` is unset or `null`, resolve as `control`.
   - If unsupported or unreadable, resolve as `unknown` and fail before prompts
     or side effects with a local context error.
2. **Resolve `app_show.app`** from `[app]`, current working directory, or input
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
3. **Validate result.**
   - Must resolve to exactly one visible app record.
   - The caller must be authorized to inspect the target app through
     gateway-owned access policy.
4. **Select renderer** and begin the read flow.

## Behavior Contract

### App Registry Read Rules

1. **Lookup.** Read the app record from gateway-owned app intent by the
   resolved name. If no visible app record matches, fail before side effects.
2. **Authorization.** Verify the caller is authorized to inspect the target
   app through gateway-owned access policy. If not authorized, fail before
   side effects.
3. **Result assembly.** Return the app record and the durable gateway intent
   the app owns:
   - app registry: name, environment, owning app node, repository, app path,
     document root, PHP version, primary domain;
   - agent IDE configuration: effective adapter and resolution source;
   - related intent owned by the app: workspaces, processes, and app-owned
     proxy routes (registry-shaped, not live status).

   Default `app:show` is a registry read, not a live readiness command.

### Scope Boundaries

`app:show` must not:
- Mutate gateway intent or node state.
- Fix drift or adopt node reality.
- SSH into the owning app node directly from the caller.
- Run live PHP-FPM, document-root, route, or process probes.
- Block on slow or unreachable node runtime checks.

## Renderer Contracts

Output renderer behavior is split out of the canonical command contract:

- [`6.1_app-show_output-render_human.md`](6.1_app-show_output-render_human.md):
  no-progress-tree decision, registry detail view, prose errors.
- [`6.2_app-show_output-render_json.md`](6.2_app-show_output-render_json.md):
  JSON envelope, registry-only data shape, error codes, error metadata.

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| App not found | No visible app record matches the resolved name or hostname. | Failure |
| Not authorized | The caller is not allowed to inspect the target app. | Failure |
| Local context invalid | `general.local_node_role` is unreadable or unsupported. | Failure |
| Gateway unavailable | A control or app caller has no configured gateway or cannot reach the gateway API. | Failure |

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
| `tests/Feature/Commands/Apps/AppShowCommandTest.php` | Command contract: input resolution (name vs hostname tiebreak), CWD-default fallback chain, caller-role resolution, app lookup, authorization check, registry-only read behavior, no live probe invocation, read-only guarantee, and failure semantics. |

Input-mode-specific test mapping lives in:

- [`5.1_app-show_input-mode_interactive.md`](5.1_app-show_input-mode_interactive.md#test-mapping)
- [`5.2_app-show_input-mode_non-interactive.md`](5.2_app-show_input-mode_non-interactive.md#test-mapping)

Renderer-specific test mapping lives in:

- [`6.1_app-show_output-render_human.md`](6.1_app-show_output-render_human.md#test-mapping)
- [`6.2_app-show_output-render_json.md`](6.2_app-show_output-render_json.md#test-mapping)
