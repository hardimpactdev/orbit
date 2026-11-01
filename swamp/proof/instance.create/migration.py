import os
import sqlite3
from pathlib import Path

if os.environ.get("DB_CONNECTION") != "sqlite":
    raise RuntimeError("database-connection-not-ready-before-setup")
root = Path.cwd().resolve()
path = Path(os.environ["DB_DATABASE"])
if not path.is_absolute() or not path.resolve().is_relative_to(root):
    raise RuntimeError("database-outside-owned-checkout")
if not path.is_file() or path.is_symlink():
    raise RuntimeError("database-not-created-before-setup")
# Read-write mode must never create a missing database as a side effect.
with sqlite3.connect(path.as_uri() + "?mode=rw", uri=True) as connection:
    if connection.execute("SELECT marker FROM orbit_create_seed").fetchall() != [("owned-source",)]:
        raise RuntimeError("database-copy-not-ready-before-setup")
    connection.execute("CREATE TABLE IF NOT EXISTS orbit_create_migrations (name TEXT PRIMARY KEY)")
    connection.execute("INSERT OR IGNORE INTO orbit_create_migrations VALUES (?)", ("create-before-setup",))
    if connection.execute("SELECT name FROM orbit_create_migrations").fetchall() != [("create-before-setup",)]:
        raise RuntimeError("migration-not-applied")
print("migration-ok")
