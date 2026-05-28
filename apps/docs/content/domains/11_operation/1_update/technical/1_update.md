# Technical Contract: `orbit update`

[Back to public `update` documentation.](../update.md)

**Owner:** `operation`.

**Effects:** `write`, `local-only`, `stream`.

**Prerequisites:**
- The local Orbit checkout is writable.
- The checkout has a configured Git remote.
- The host Orbit launcher can start the current checkout.
- Docker and the `orbit-runtime` runtime are available for dependency
  installation and migrations.

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
- Install Composer dependencies inside `orbit-runtime` after the source update
  succeeds.
- Run Orbit migrations inside `orbit-runtime` after dependencies are installed.
- Return success only when every local update step succeeds.

### Local Migration Rules

- Apply migrations inside `orbit-runtime` with non-interactive production-safe
  semantics.
- When the local checkout is a gateway installation, migrations may update the gateway database schema.
- Migrations must not create or mutate fleet configuration beyond normal schema/data migrations owned by the application version.

### Privilege, Version Source, And Rollback Rules

- Run every step as the current OS user. `update` must not prompt for `sudo`,
  escalate privileges, or rewrite host ownership to make the checkout writable.
- Treat the current Git checkout as the version source. The source step uses the
  current branch's configured remote and upstream with fast-forward-only
  semantics; `update` does not select arbitrary release tags, channels, or
  versions.
- Do not perform automatic rollback. If dependency installation or migrations
  fail after the source pull succeeds, report the failed step and leave already
  completed local changes in place so the operator can repair and rerun the
  update or revert manually.
- Do not hide partial local state behind a success result. Any failed step
  returns failure and identifies the failed step.

### Scope Boundaries

`update` must not:
- Update other nodes.
- SSH to the gateway or nodes.
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
| Runtime unavailable | Docker or `orbit-runtime` cannot be found or executed. | Failure |
| Dependency install failed | Composer dependency installation inside `orbit-runtime` fails. | Failure |
| Migration failed | Local Orbit migrations inside `orbit-runtime` fail. | Failure |

## Doctor Relationship

- `update` changes the local Orbit installation.
- It does not verify fleet drift or runtime readiness.
- After updating a gateway or node, run the `doctor --family=<family>`
  command for the family whose artifacts or readiness need verification.

## Activity Logging

`orbit update` is a caller-local CLI command. It does not call the gateway API
and does not emit a gateway activity entry. Local update attempts are reflected
only in command output and exit status.

## Test Mapping

Primary CLI test owners:

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Operation/UpdateCommandTest.php` | Local update contract: step ordering, JSON success and error envelopes, human progress tree, failure prose, and checkout-unavailable handling. |
| `apps/cli/tests/Feature/Services/Updates/LocalCheckoutUpdaterTest.php` | Step command invocation against the resolved checkout path, fast-forward pull semantics, orbit-runtime dependency install, and migration execution. |
| `apps/cli/tests/Feature/Services/Updates/LocalUpdateWorkflowTest.php` | Ordered workflow orchestration, checkout-unavailable detection, and failed-step metadata. |
| `apps/cli/tests/Feature/Services/Updates/CheckoutPathResolverTest.php` | Checkout path resolution from the CLI application root. |

Legacy gateway coverage retained for bridged and historical local-update behavior:

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Commands/UpdateCommandTest.php` | Gateway bootstrap implementation for local update command execution. |
| `apps/gateway/tests/Feature/Commands/Operations/UpdateCommandTest.php` | Gateway-local update contract, renderer selection, and failure handling. |
| `apps/gateway/tests/E2E/UpdateTest.php` | Integrated local update behavior inside the gateway topology. |
