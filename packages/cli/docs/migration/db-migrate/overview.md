# db:migrate

Runs database migrations for Orbit CLI.

## What It Does

1. Optionally shows migration status or runs fresh migration
2. Runs `schema:migrate` for legacy table migration
3. Runs Laravel migrations
4. Optionally seeds the database

## Options

| Option | Description |
|--------|-------------|
| `--status` | Show migration status only |
| `--fresh` | Drop all tables and re-run all migrations |
| `--seed` | Seed the database after migrations |

## Workflow

```
db:migrate
  ├── schema:migrate (legacy table migration)
  └── migrate (Laravel migrations)
      └── db:seed (if --seed)
```

## Examples

```bash
# Run migrations
orbit db:migrate

# Check migration status
orbit db:migrate --status

# Fresh install with seed
orbit db:migrate --fresh --seed
```

## When to Use

- After upgrading Orbit CLI
- After `orbit init` to ensure database is current
- To reset database with `--fresh`

## Related Commands

- `schema:migrate` - Legacy table migration (called automatically)
- `config:migrate` - Migrate JSON config to database
