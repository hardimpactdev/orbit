# package:unlink

Removes a local package link from an application.

## What It Does

1. Validates the app project exists at `~/projects/{app}`
2. Runs `composer unlink` to remove the symlink
3. Restores the package from Packagist (or original source)

## Arguments

| Argument | Description |
|----------|-------------|
| `package` | The package name (vendor/package format) |
| `app` | The app project name to unlink from |

## Options

| Option | Description |
|--------|-------------|
| `--json` | Output result as JSON |

## Example

```bash
# Unlink orbit-core from orbit-web
orbit package:unlink hardimpactdev/orbit-core orbit-web
```

## Note

The package argument should be the Composer package name (e.g., `vendor/package`), not the project directory name.

## Related Commands

- `package:link` - Create a link
- `package:linked` - List linked packages
