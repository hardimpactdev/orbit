# `orbit deploy:step-list [app]`

[Back to Deploy commands.](../README.md)

List deployment pipeline steps for a production app.

Use `deploy:step-list` to inspect the ordered deployment policy stored on the
gateway, including multiline step scripts.

## Usage

```bash
orbit deploy:step-list [app] [--json]
```

## Examples

```bash
orbit deploy:step-list docs
orbit deploy:step-list docs --json
```

## Arguments and options

- `app`: production app name or domain.
- `--json`: Output JSON.

## What Happens

Run `deploy:step-list` when you need to inspect the ordered deployment policy for an app. `deploy:step-list` reads deployment step definitions from gateway app configuration. It does not inspect node state, execute steps, or read deployment run history.

## Output

Pass `--json` to receive machine-readable output; omit it to see an ordered step table with the command body in the table.
Multiline scripts keep their stored line breaks, and shell chains joined with
`&&` are split across display lines for readability.

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
