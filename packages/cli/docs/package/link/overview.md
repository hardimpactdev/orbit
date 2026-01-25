# package:link

Links a local Composer package to an application for development.

## What It Does

1. Validates the package project exists at `~/projects/{package}`
2. Validates the app project exists at `~/projects/{app}`
3. Verifies the package has a composer.json
4. Runs `composer link` to symlink the package for development

## Arguments

| Argument | Description |
|----------|-------------|
| `package` | The package project name (directory in ~/projects/) |
| `app` | The app project name to link the package to |

## Options

| Option | Description |
|--------|-------------|
| `--json` | Output result as JSON |

## How It Works

Uses the `composer-link` plugin to create a symlink from the app's vendor directory to the local package source. This allows real-time development without publishing the package.

## Example

```bash
# Link orbit-core to orbit-web for development
orbit package:link orbit-core orbit-web
```

## Prerequisites

- Both projects must exist in `~/projects/`
- The package must have a valid composer.json
- The `composer-link` plugin must be installed in the app

## Related Commands

- `package:unlink` - Remove the link
- `package:linked` - List linked packages
