# Technical Contract: `orbit update`

[Back to public `update` documentation.](../update.md)

**Owner:** `operation`.

**Effects:** `write`, `local-only`, `stream`.

**Prerequisites:**
- The local Orbit checkout is writable.
- The checkout has a configured Git remote.
- Composer is available on the caller machine.
- The local PHP runtime can run Orbit migrations.

## Signature

```bash
orbit update [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

No required inputs exist; the command takes no positional arguments and all
options are optional.

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Authorization By Caller Role

`update` is local-only. It does not call the gateway and does not consult the gateway for authorization. Any machine with a writable Orbit checkout may run it; the gateway plays no role in this command. When invoked on the gateway host, local migrations may update the gateway database schema. That is still local application update behavior, not a fleet configuration write.

## Input Resolution

1. Select the output renderer.
2. Validate the local checkout and runtime prerequisites.
3. Start the local update sequence.

No input-mode-specific contracts are required. The command has no required
fields and does not prompt.

## Behavior Contract

### Local Checkout Update Rules

- Run the update against the current Orbit checkout only.
- Pull the configured Git remote using fast-forward-only semantics. A divergent
  local branch fails rather than creating a merge commit.
- Install Composer dependencies after the source update succeeds.
- Run Orbit migrations after dependencies are installed.
- Return success only when every local update step succeeds.

### Local Migration Rules

- Apply migrations with non-interactive production-safe semantics.
- When the local checkout is a gateway installation, migrations may update the gateway database schema.
- Migrations must not create or mutate fleet configuration beyond normal schema/data migrations owned by the application version.

### Scope Boundaries

`update` must not:
- Update other nodes.
- SSH to the gateway or app nodes.
- Query or mutate gateway fleet configuration as a command behavior.
- Repair node, app, workspace, process, proxy route, schedule, tool, or
  firewall drift.
- Replace `doctor` verification after an update.

## Renderer Contracts

- [Human renderer](6.1_update_output-render_human.md)
- [JSON renderer](6.2_update_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Local checkout unavailable | The command cannot access or update the local Orbit checkout. | Failure |
| Git update failed | The source pull fails, including non-fast-forward divergence. | Failure |
| Composer unavailable | Composer cannot be found or executed. | Failure |
| Dependency install failed | Composer dependency installation fails. | Failure |
| Migration failed | Local Orbit migrations fail. | Failure |

## Doctor Relationship

- `update` changes the local Orbit installation.
- It does not verify fleet drift or runtime readiness.
- After updating a gateway or app node, run the `doctor --family=<family>`
  command for the family whose artifacts or readiness need verification.

## Activity Logging

The local CLI command emits an activity entry for successful and failed local
checkout update attempts. Activity logging is best-effort and must not change
the documented command result.

| Field | Value |
| --- | --- |
| Type | `update` |
| Effect | `write` |
| Subject | `none`; the command updates the caller-local Orbit checkout, not a gateway-owned registry entity. |
| Properties | `scope=local`, `target=local`, `status` (`completed` or `failed`), and `failed_step` when a step fails. No process output, Git output, Composer output, migration output, environment values, or secrets. |
| Description | derived |

## Test Mapping

Primary existing test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/UpdateCommandTest.php` | Bootstrap implementation coverage for local update command execution. Must be expanded to cover renderer selection, JSON output, fast-forward pull failure, Composer failure, migration failure, and no remote side effects. |

Required split contract tests:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Operations/UpdateCommandTest.php` | Local update contract: no required inputs, no prompts, local-only side-effect boundary, update step ordering, success status, and handled failure status. |
| `tests/Feature/Commands/Operations/UpdateJsonRendererTest.php` | JSON renderer selection, success envelope, error envelope, `error.code` values, and failure metadata. |
| `tests/Feature/Commands/Operations/UpdateHumanRendererTest.php` | Human renderer progress tree, success output, and failure output. |
