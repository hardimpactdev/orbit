# Deploy Commands

Deploy commands manage production app deployment policy and history. The command
family owns the `deploy:*` command prefix.

Deployments are an operator workflow, not a standalone state family. Deployment
step definitions, deployment runs, run logs, and latest deployment status are
app-owned gateway state. App doctor may use deployment policy and latest run
state when evaluating production app health.

## State Ownership

The deploy command domain does not own a state family. Deployment policy,
deployment history, run logs, and latest deployment status are app-owned
gateway state.

[`doctor --family=app`](../5_app/app-doctor.md) owns deployment pipeline
validation and latest deployment health. A failed or stale latest deployment is
reported as app health, not as deploy-family drift, and is not fixable or
adoptable by doctor.

## Domain Rules

These rules define what the deploy command family owns and how it behaves.

- The deploy command family owns the `deploy:*` command prefix.
- Deployment policy and history belong to production apps.
- The gateway is the source of truth for deployment step definitions, step
  metadata, run history, and latest deployment status.
- Deployment commands apply only to production apps.
- Deployment steps are arbitrary shell commands. Orbit does not assume every
  deployment is a zero-downtime release flow.
- Deployment steps execute from the app source path tracked by the gateway on
  the app's owning node. PHP, Composer, and Artisan deployment commands run on
  the host PHP toolchain (matched to the app's PHP version); the app's
  FrankenPHP container serves the deployed source.
- Release-aware deployment steps may create versioned release directories and
  switch the active `live_path`, but the active runtime mount must stay inside
  the app source or release boundary. Symlinks for `live_path`, document root,
  storage, and database paths must resolve inside that boundary before the
  production runtime service is rendered.
- Retention is optional deploy-step metadata for steps that create or prune
  versioned releases. It is not global app policy and not a standalone state
  family.
- Deployment runs execute configured steps on the app's owning node through the
  gateway.
- Deployment reads use gateway policy and durable history. They do not inspect
  live node state.
- Deployment health is part of production app health and belongs to
  `doctor --family=app`.

## Deploy Step JSON Entity

Deploy JSON renderers that return one step entity embed this shape under
`success.data.step`, or directly under `success.data.steps[]` for list items.

```json
{
  "id": 12,
  "app": "docs",
  "title": "Pull latest",
  "command": "git pull origin main",
  "order": 1,
  "timeout_seconds": 600,
  "retention": null
}
```

| Field | Type | Meaning |
| --- | --- | --- |
| `id` | integer | Gateway-assigned deployment step identifier. |
| `app` | string | Production app that owns the step. |
| `title` | string | Human label for the step. |
| `command` | string | Shell command executed during deployment. |
| `order` | integer | Step order within the app deployment pipeline. |
| `timeout_seconds` | integer | Maximum step runtime before Orbit marks the step failed. |
| `retention` | integer \| null | Optional retention metadata for release-aware steps. |

## Deploy Run JSON Entity

Deploy JSON renderers that return one run entity embed this shape under
`success.data.run` or `error.data.run`, or directly under
`success.data.runs[]` for history items.

```json
{
  "id": 42,
  "app": "docs",
  "status": "completed",
  "exit_code": 0,
  "started_at": "2026-05-02T09:00:00Z",
  "finished_at": "2026-05-02T09:01:30Z",
  "steps": [
    {
      "id": 12,
      "title": "Pull latest",
      "status": "completed",
      "exit_code": 0
    }
  ]
}
```

| Field | Type | Meaning |
| --- | --- | --- |
| `id` | integer | Gateway-assigned deployment run identifier. |
| `app` | string | Production app that owns the run. |
| `status` | string | `running`, `completed`, `failed`, or `cancelled`. |
| `exit_code` | integer \| null | Final process exit code when the run has finished. |
| `started_at` | string | ISO-8601 run start timestamp. |
| `finished_at` | string \| null | ISO-8601 finish timestamp when complete. |
| `steps[]` | array | Per-step run summaries. |

## Commands

Use these commands to manage deployment steps, run deployments, and inspect deployment history.

1. [`orbit deploy:step-add`](1_deploy-step-add/deploy-step-add.md)
2. [`orbit deploy:step-list`](2_deploy-step-list/deploy-step-list.md)
3. [`orbit deploy:step-remove`](3_deploy-step-remove/deploy-step-remove.md)
4. [`orbit deploy:run`](4_deploy-run/deploy-run.md)
5. [`orbit deploy:history`](5_deploy-history/deploy-history.md)
6. [`orbit deploy:log`](6_deploy-log/deploy-log.md)

## Related

- [`orbit app:*`](../5_app/README.md)
- [`doctor --family=app`](../5_app/app-doctor.md)
