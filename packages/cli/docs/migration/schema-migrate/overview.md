# schema:migrate

Migrates legacy CLI database schema from `projects` table to `sites` table.

## What It Does

1. Checks if database exists
2. Detects if legacy `projects` table exists
3. Migrates data to `sites` table (rename or merge)
4. Ensures `project_id` column exists for orbit-core integration

## Options

| Option | Description |
|--------|-------------|
| `--dry-run` | Show what would be done without making changes |

## Migration Logic

| Scenario | Action |
|----------|--------|
| No database | Skip - will be initialized on first use |
| `projects` table without `path` column | Skip - this is orbit-core's projects table |
| `projects` table with `path`, no `sites` | Rename table, rename `name` to `slug` if needed |
| Both `projects` and `sites` exist | Merge data, drop old table |
| Only `sites` exists | Ensure `project_id` column exists |

## Safety

- Creates timestamped backup before migration
- Preserves existing data with `INSERT OR IGNORE`

## When to Use

- After upgrading from older Orbit CLI versions
- Called automatically by `db:migrate`

## Related Commands

- `db:migrate` - Runs this command automatically
- `config:migrate` - Migrates JSON config to database
