# php:config

Gets or sets PHP configuration settings (php.ini and php-fpm pool settings).

## What It Does

1. Identifies the PHP version (specified or latest installed)
2. Either displays current settings or applies updates
3. Restarts PHP-FPM after changes

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `version` | No | PHP version to configure (defaults to latest installed) |

## Options

| Option | Description |
|--------|-------------|
| `--get` | Show current settings |
| `--upload-max-filesize` | Set upload_max_filesize |
| `--post-max-size` | Set post_max_size |
| `--memory-limit` | Set memory_limit |
| `--max-execution-time` | Set max_execution_time |
| `--max-children` | Set pm.max_children |
| `--start-servers` | Set pm.start_servers |
| `--min-spare-servers` | Set pm.min_spare_servers |
| `--max-spare-servers` | Set pm.max_spare_servers |
| `--json` | Output as JSON |

## Settings Categories

### php.ini Settings
- `upload_max_filesize` - Maximum file upload size
- `post_max_size` - Maximum POST request size
- `memory_limit` - PHP memory limit
- `max_execution_time` - Script timeout in seconds

### PHP-FPM Pool Settings
- `pm.max_children` - Maximum worker processes
- `pm.start_servers` - Workers to start initially
- `pm.min_spare_servers` - Minimum idle workers
- `pm.max_spare_servers` - Maximum idle workers

## Examples

```bash
# Show settings for latest PHP
orbit php:config

# Show settings for PHP 8.3
orbit php:config 8.3 --get

# Set memory limit
orbit php:config 8.4 --memory-limit=512M

# Set multiple values
orbit php:config --upload-max-filesize=100M --post-max-size=100M
```

## Related Commands

- `php` - Set PHP version per site
