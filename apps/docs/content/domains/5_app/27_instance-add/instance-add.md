# `orbit instance:add`

[Back to app and instance commands.](../README.md)

Add one concrete instance to an existing app.

## Usage

```bash
orbit instance:add [instance] [--node=<node>] [--driver=orbit|laravel-cloud] [--path=<path>] [--root=<root>] [--domain=<domain>] [--cloud-app=<app>] [--cloud-environment=<environment>] [--cloud-application-id=<id>] [--cloud-application-name=<name>] [--cloud-environment-id=<id>] [--cloud-environment-name=<name>] [--cloud-organization-id=<id>] [--cloud-organization-name=<name>] [--php-extension=<extension>] [--json]
```

## Examples

```bash
orbit instance:add billing.development --node=app-dev-1 --path=/home/orbit/apps/billing
orbit instance:add billing.production-cloud --driver=laravel-cloud --cloud-app=app_123 --cloud-environment=env_123
```

## Arguments and options

- `instance`: Required dotted `app.instance` selector.
- `--driver`: `orbit` or `laravel-cloud`; defaults to `orbit`.
- `--node`, `--path`, `--root`, `--domain`: Orbit placement fields.
- `--cloud-*`: Laravel Cloud application, environment, and organization identifiers.
- `--php-extension`: Repeatable required PHP extension.
- `--json`: Emit the shared machine-readable envelope.

## What Happens

Orbit validates the driver-specific placement, authorizes the target, and
creates one instance row without changing the app or sibling instances. The new
instance copies the app PHP creation template as its own concrete version, so a
later change to the app default never moves it. Use
[`php:use --instance`](../../14_php/2_php-use/php-use.md) to give it a different
version.

## Output

Human output confirms the created instance. The
[JSON renderer contract](technical/6.2_instance-add_output-render_json.md)
defines the instance and Cloud compatibility payload.

## Requirements

- The app already exists.
- Orbit placements target an eligible `app-dev` or `app-prod` node.
- External-driver mutation uses the gateway-only authority path.

## Related Commands

Creating a new Orbit instance on a node with the persisted active `app-dev`
role copies the app's ordered development setup defaults into independent
instance setup rows. App-prod, Laravel Cloud, and existing-instance updates do
not copy defaults. Later default edits affect future instances only.

- [`app:new`](../1_app-new/app-new.md)
- [`instance:show`](../26_instance-show/instance-show.md)
- [`instance:remove`](../28_instance-remove/instance-remove.md)

## Technical Contract

See [`instance:add` technical contract](technical/1_instance-add.md).
