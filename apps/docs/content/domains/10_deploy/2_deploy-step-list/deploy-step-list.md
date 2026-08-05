# `orbit deploy:step-list [instance]`

[Back to Deploy commands.](../README.md)

List deployment pipeline steps for a concrete production instance.

Use `deploy:step-list` to inspect the ordered deployment policy stored on the
gateway, including multiline step scripts.

## Usage

```bash
orbit deploy:step-list [instance] [--json]
```

## Examples

```bash
orbit deploy:step-list docs.production
orbit deploy:step-list docs.production --json
```

## Arguments and options

- `instance`: dotted production instance selector. A bare app name or domain is
  valid only when the app has exactly one instance.
- `--json`: Output JSON.

## What Happens

Run `deploy:step-list` when you need to inspect the ordered deployment policy
for one instance. It reads deployment step definitions owned by that
instance from gateway state. It does not inspect node state, execute steps, or
read deployment run history.

## Output

Pass `--json` to receive machine-readable output; omit it to see an ordered step table with the command body in the table.
Multiline scripts keep their stored line breaks, and shell chains joined with
`&&` are split across display lines for readability.

JSON output returns deploy step entities with app and count metadata.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The caller has `deploy:read` on the instance's owning Orbit node, or on the
  gateway when the instance is external.

## Related

- [`orbit deploy:step-add`](../1_deploy-step-add/deploy-step-add.md)
- [`orbit deploy:step-remove`](../3_deploy-step-remove/deploy-step-remove.md)
- [`orbit deploy:run`](../4_deploy-run/deploy-run.md)

## Technical Contract

See [`deploy:step-list` technical contract](technical/1_deploy-step-list.md).
