# php

Sets the PHP version for a specific site.

## What It Does

1. Validates the site exists
2. Validates the PHP version is supported
3. Saves the PHP version override to the database
4. Regenerates Caddyfile if site has a public folder
5. Reloads Caddy to apply changes

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `site` | Yes | The site name to configure |
| `version` | No | PHP version (8.3, 8.4, 8.5) - required unless using --reset |

## Options

| Option | Description |
|--------|-------------|
| `--reset` | Reset to default PHP version |
| `--json` | Output result as JSON |

## Valid PHP Versions

- 8.3
- 8.4
- 8.5

## Examples

```bash
# Set a site to PHP 8.4
orbit php mysite 8.4

# Reset to default version
orbit php mysite --reset
```

## Behavior

- Sites without a public folder: override saved but no Caddy reload
- Sites with public folder: Caddyfile regenerated and Caddy reloaded
- Reset removes the override from both database and legacy config

## Related Commands

- `php:config` - Configure PHP settings (memory, upload limits, etc.)
- `site:list` - List sites and their PHP versions
