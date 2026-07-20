# Technical Contract: `orbit deploy:step-add [instance] [deploy_command] [--title=<title>] [--order=<number>] [--timeout=<seconds>] [--retention=<count>] [--json]`

[Back to public `deploy-step-add` documentation.](../deploy-step-add.md)

**Owner:** `deploy`.

**Effects:** `write`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The current node identity has `deploy:step` at the selected instance's grant
  boundary: its owning Orbit node, or the gateway for an external instance.

## Signature

```bash
orbit deploy:step-add [instance] [deploy_command] [--title=<title>] [--order=<number>] [--timeout=<seconds>] [--retention=<count>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `instance` | `argument` | `Required in non-interactive mode.` | `Never.` | `None.` | Visible production instance selector. A bare project is valid only when it has exactly one instance. |
| `deploy_command` | `argument` | `Required in non-interactive mode.` | `Never.` | `None.` | Non-empty shell command or multiline shell script string. |
| `title` | `--title` | `Optional.` | `Never.` | Command-derived title. | Non-empty display label. |
| `order` | `--order` | `Optional.` | `Never.` | Next pipeline position. | Positive integer insertion order. |
| `timeout` | `--timeout` | `Optional.` | `Never.` | `600`. | Positive integer seconds. |
| `retention` | `--retention` | `Optional.` | `Never.` | `null` | Positive integer release-retention metadata. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | Selects the JSON renderer. |

## Input Mode Contracts

- [Interactive input mode](5.1_deploy-step-add_input-mode_interactive.md)
- [Non-interactive input mode](5.2_deploy-step-add_input-mode_non-interactive.md)

## Behavior Contract

### Deployment Policy Rules

- Resolves one concrete production instance through gateway configuration.
- Fails before side effects unless the selected instance belongs to a production project.
- Writes one deploy-step definition owned by the selected instance.
- Stores the step command exactly as provided. Context placeholders are not
  resolved during policy writes.
- Inserts the step at the selected order. When another step already has that
  order or a later order, the gateway increments those step orders by one and
  returns the reordered deployment pipeline.
- Stores the step timeout in seconds. `deploy:run` marks the step failed if
  execution exceeds this timeout.
- Stores optional retention metadata only on the step that declares it.
- Allows `{{ key }}` placeholders for deploy-run context values. Unknown or
  non-scalar placeholders are preserved and may fail later during execution.

### Execution Boundary Rules

- Does not execute the deployment step.
- Does not create deployment run history.
- Does not inspect live node state or app source state.
- Does not create process definitions, schedules, proxy routes, or global
  deployment retention policy.

## Renderer Contracts

- [Human renderer](6.1_deploy-step-add_output-render_human.md)
- [JSON renderer](6.2_deploy-step-add_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Instance not found | No visible instance matches the selector. | `error.code=instance.not_found` |
| Production project required | The app exists but is not a production project. | `error.code=deploy.production_project_required` |
| Instance required | A bare project has more than one instance. | `error.code=validation_failed`, `error.meta.reason=instance_required` |

## Doctor Relationship

Deployment policy is instance-owned gateway state. `deploy:step-add` does not own a
doctor family. [`instance-doctor.md`](../../../5_project/instance-doctor.md) may use deployment
policy when reporting `app.deployment_pipeline_invalid`.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Deploy/DeployWriteCommandsTest.php` | CLI `deploy:step-add` POST payload forwarding, local validation before gateway contact for missing command and invalid timeout, JSON success envelope, and human success summaries. |
| `apps/gateway/tests/Feature/Http/Api/DeployControllerTest.php` | Gateway deployment-step listing, `deploy:step` grant denial before side effects, and authorized step creation for app-dev callers. |
| `apps/gateway/tests/Feature/Actions/Deploy/DeployStepActionsTest.php` | Gateway order insertion, retention persistence, and order compaction after step removal. |

Coverage gaps until focused tests land: production-app eligibility, `instance.not_found`, exhaustive documented `error.code` values, order insertion through the CLI surface, timeout and retention gateway validation beyond local CLI checks, and instance-doctor handoff behavior.
