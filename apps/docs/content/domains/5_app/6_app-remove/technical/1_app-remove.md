# Technical Contract: `orbit app:remove [app]`

[Back to public `app:remove` documentation.](../app-remove.md)

**Owner:** `app`.

**Effects:** `destructive`, `write`, `stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The target app exists.
- The caller has `app:remove` on every affected Orbit instance's serving node.

## Signature

```bash
orbit app:remove [app] [--force] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Default | Validation |
| --- | --- | --- | --- | --- |
| `app` | `[app]` | Always. | None. | Exact app slug. Hostnames and dotted instance selectors are forbidden. |
| `force` | `--force` | Every non-interactive invocation, including `--json`. | `false`. | Explicit approval for the complete app cascade. |
| `json` | `--json` | Optional. | `false`. | Selects JSON and non-interactive input; never implies destructive consent. |

## Input Resolution

1. Resolve `app` by exact logical slug.
2. Enumerate every instance and its driver, serving node, and dependent
   artifacts. Freeze this inventory for authorization, consent, execution, and
   output.
3. Authorize `app:remove` on every distinct serving node represented by the
   affected Orbit instances. External-driver instances use the documented
   gateway-only authority path.
4. Stop before consent or side effects if any required authorization fails.
5. Obtain interactive `app_remove.confirm` consent, or require `--force` in
   non-interactive mode.

The command has no parent-only mode. A dotted selector or hostname fails
before inventory or effects; callers use `instance remove` for one
placement.

## Input Mode Contracts

- [Interactive input](5.1_app-remove_input-mode_interactive.md)
- [Non-interactive input](5.2_app-remove_input-mode_non-interactive.md)

## Behavior Contract

### Preflight and authorization

Orbit resolves the app, captures the complete instance/dependent
inventory, and authorizes the complete serving-node set. Authorization is
all-or-nothing and precedes destructive consent so the prompt never offers an
operation the caller cannot perform.

### Destructive consent

The interactive confirmation explicitly names every affected instance and
shows aggregate dependent counts. `--force` approves that same complete
cascade. Consent can never select only the logical record or a subset of
instances.

### Execution

After consent:

1. In one gateway transaction, remove the app, every frozen instance,
   and all gateway-owned proxy-route, schedule, workspace, process, and other
   dependent rows. A transaction failure rolls back the complete cascade.
2. For each frozen Orbit instance, remove its runtime container, managed runtime
   configuration, residual managed artifacts, and eligible app path through
   Agent push to that instance's serving node.
3. Return aggregate cleanup totals plus one cleanup result per instance.

An app path is removed only when Orbit created that concrete instance path,
the instance was not adopted from pre-existing source, and no retained
instance shares it. Workspace worktrees below an eligible removed path are
removed with it.

The atomic gateway transaction is the point of no return. A transaction failure
returns a command failure with no cascade rows removed and no node cleanup.
Cleanup that fails after the transaction commits is successful removal with a
structured drift warning; later instance cleanup continues.

## Failure Semantics

Standard [Common Failures](../../../README.md#common-failures) apply.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Invalid selector | `app` is a hostname or dotted instance selector. | `validation_failed` with `meta.field=app` and `meta.reason=logical_slug_required`. |
| Instance not found | No app matches the exact slug. | `app.not_found`. |
| Authorization denied | Any serving node denies `app:remove`. | `authorization_failed` with complete serving-node and unauthorized-node metadata; no consent or effects. |
| Consent missing | Non-interactive mode lacks `--force`. | `validation_failed` with `meta.field=force` and `meta.reason=destructive_consent_required`. |
| Confirmation declined | The operator declines `app_remove.confirm`. | `validation_failed` with `meta.field=force` and `meta.reason=cancelled`; no effects. |
| Gateway removal failed | Gateway configuration removal fails before the affected scope is deleted. | `app.removal_failed`. |

Partial cleanup after gateway removal is returned as success with one warning
per affected family in `success.meta.warnings[]`. Each warning includes
`code`, `family`, `message`, `instance`, `serving_node`, and
`next_command`. The exit code remains `0`.

## Doctor Relationship

Remaining app artifacts are diagnosed by
[`doctor --family=instance`](../../instance-doctor.md). Proxy, workspace, process, and
other family artifacts remain owned by their respective family doctors.

## Activity Logging

| Field | Value |
| --- | --- |
| Type | `api:DELETE /apps/{app}` |
| Effect | `destructive` |
| Subject | Resolved logical `App`, or `none` when resolution/authorization fails before a subject is available. |
| Properties | Logical `app`, `instance_names`, `instance_count`, `serving_nodes`, and aggregate dependent counts. Never raw node output or secrets. |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/App/AppWriteCommandTest.php` | Missing `--force`, forced JSON removal, the current generic interactive confirmation, human progress, drift warnings, and gateway error prose. |
| `apps/gateway/tests/Feature/Http/Api/AppRemoveControllerTest.php` | Cascade deletion, concrete-instance cleanup, the `success.meta.warnings[]` payload for unresolved placement, destructive-consent validation, and denial on one selected app node. |

Coverage gaps remain for the approved contract. No mapped CLI test asserts that
the confirmation names every affected instance. No mapped gateway test proves
that removal stops when the caller lacks access to a second serving node. The
current gateway fixture has multiple instances and succeeds after granting
access to only one of its two serving nodes. Authorization on every affected
node is therefore still a runtime and test gap.
