# `orbit deploy:step-list [app]`

[Back to Deploy commands.](../README.md)

List deployment pipeline steps for a production app.

Use `deploy:step-list` to inspect the ordered deployment policy stored on the
gateway.

## Usage

```bash
orbit deploy:step-list [app] [--json]
```

## Examples

```bash
orbit deploy:step-list docs
orbit deploy:step-list docs --json
```

## Arguments And Options

- `app`: production app name or domain.
- `--json`: Output JSON.

## What Happens

`deploy:step-list` reads deployment step definitions from gateway app intent. It
does not inspect node state, execute steps, or read deployment run history.

## Output

Human output renders an ordered step table.

JSON output returns deploy step entities with app and count metadata.

## Requirements

- The CLI caller can reach the Orbit gateway, or the command runs on the
  gateway.
- The caller is authorized to inspect deployment policy for the production app.

## Related

- [`orbit deploy:step-add`](../1_deploy-step-add/deploy-step-add.md)
- [`orbit deploy:step-remove`](../3_deploy-step-remove/deploy-step-remove.md)
- [`orbit deploy:run`](../4_deploy-run/deploy-run.md)

## Technical Contract

See [`deploy:step-list` technical contract](technical/1_deploy-step-list.md).
