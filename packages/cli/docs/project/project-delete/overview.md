# project:delete overview

Deletes a project including its local files and database, with support for database-driven lookups and duplicate slug handling.

## Process

1. Resolves project location using database or filesystem scan:
   - If `--id` provided: looks up project by ID (unique, unambiguous)
   - If slug provided: queries database for matching projects
   - If multiple projects share the same slug: prompts user to choose
   - If no database entry: falls back to filesystem scan via ProjectScanner
2. Confirms deletion (unless `--force` is provided)
3. Drops the PostgreSQL database (reads DB_DATABASE from .env, or uses slug as fallback)
4. Deletes the local directory (with sudo fallback for container-created files)
5. Removes the project record from the database
6. Regenerates and reloads Caddy configuration

## Project Path Storage

Projects are stored in the database with their paths during `project:list` or `project:scan` operations. This enables:

- Fast lookups without filesystem scanning
- Handling of moved projects (rescan if stored path is invalid)
- Support for projects with duplicate slugs in different paths
- Automatic cleanup of orphan database entries when projects are removed from disk

## Failure and recovery paths

- If `--id` is provided but project not found: exits with error
- If slug not found in database: attempts filesystem scan as fallback
- If directory deletion fails (permissions): falls back to sudo
- Database cleanup happens regardless of file deletion success
- Non-PostgreSQL databases (MySQL, SQLite) are skipped with a warning

## Inputs and options

| Option | Description |
|--------|-------------|
| `slug` | Project slug to delete (positional argument) |
| `--slug` | Project slug to delete (alternative to positional) |
| `--id` | Project ID from database (for unambiguous deletion) |
| `--force` | Skip confirmation prompts |
| `--keep-db` | Do not drop the PostgreSQL database |
| `--json` | Output as JSON |

## Usage examples

```bash
# Delete by slug (interactive confirmation)
orbit project:delete my-project

# Delete by slug with force
orbit project:delete my-project --force

# Delete by database ID (useful when multiple projects share a slug)
orbit project:delete --id=42 --force

# Keep the database, only delete files
orbit project:delete my-project --force --keep-db

# JSON output for scripting
orbit project:delete my-project --force --json
```

## Key integrations

- DatabaseService (project path storage, ID lookups, duplicate handling)
- ProjectScanner (filesystem fallback, path validation)
- CaddyfileGenerator (config regeneration)
- Docker PostgreSQL (database drop)
- ReverbBroadcaster (WebSocket status updates)
