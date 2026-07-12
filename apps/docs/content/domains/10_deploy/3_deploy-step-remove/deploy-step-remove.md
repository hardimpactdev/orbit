# `orbit deploy:step-remove [app] [step]`

[Back to Deploy commands.](../README.md)

Remove a deployment pipeline step from a concrete production app instance.

Use `deploy:step-remove` to remove a deployment task from the app-instance-owned
deployment policy. Removing a step does not mutate past deployment
history.

## Usage

```bash
orbit deploy:step-remove [app] [step] [--force] [--json]
```

## Examples

```bash
orbit deploy:step-remove docs.production 12
orbit deploy:step-remove docs.production "Run migrations" --force
```

## Arguments and options

- `app`: dotted production app-instance selector. A bare app name or domain is
  valid only when the app has exactly one instance.
- `step`: step id or exact title.
- `--force`: Skip destructive confirmation.
- `--json`: Output JSON.

## What Happens

Run `deploy:step-remove` to remove a task from one app instance's deployment
pipeline. It resolves the production app instance and step, requires
destructive consent, and removes the step from that instance's gateway policy.
It does not remove deployment run history or logs.

## Output

Run without `--json` to see confirmation of the removed step.

JSON output returns the removed step entity with removal metadata.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The caller has `deploy:step` on the instance's owning Orbit node, or on the
  gateway when the instance is external.
- Destructive consent is required: confirmation in interactive mode or
  `--force` in non-interactive mode.

## Related

- [`orbit deploy:step-list`](../2_deploy-step-list/deploy-step-list.md)
- [`orbit deploy:step-add`](../1_deploy-step-add/deploy-step-add.md)
- [`doctor --family=app`](../../5_app/app-doctor.md)

## Technical Contract

See [`deploy:step-remove` technical contract](technical/1_deploy-step-remove.md).
