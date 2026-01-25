# config:migrate

Migrates site overrides from config.json to SQLite database.

## What It Does

1. Reads site overrides from `~/.config/orbit/config.json`
2. For each site with PHP version override:
   - Finds the project path
   - Saves to SQLite database
3. Clears sites from config.json after migration

## Options

| Option | Description |
|--------|-------------|
| `--json` | Output result as JSON |

## What Gets Migrated

- PHP version overrides per site
- Site paths (auto-detected if not stored)

## Example Output

```
Migrated: mysite -> PHP 8.4
Migrated: another-site -> PHP 8.3
Cleared sites from config.json

Migration complete: 2 sites migrated
```

## JSON Output

```json
{
  "success": true,
  "data": {
    "migrated": 2,
    "errors": []
  }
}
```

## When to Use

- After upgrading from config.json-based site storage
- Called during `orbit init` upgrade process

## Related Commands

- `schema:migrate` - Migrate database tables
- `db:migrate` - Run all migrations
