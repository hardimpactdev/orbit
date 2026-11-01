"""Create or inspect only the current disposable topology's SQLite fixture."""

import json
import re
import sqlite3
import sys
from pathlib import Path
from urllib.parse import quote

ROWS = [(1, "proof-one"), (2, "proof-two"), (3, "proof-three")]
CREATE = "CREATE TABLE proof_rows (id integer PRIMARY KEY, label varchar(64) NOT NULL)"


def owned_path(path, run, home=Path("/home/orbit")):
    if not re.fullmatch(r"[a-zA-Z0-9_-]{1,80}", run):
        raise ValueError("Invalid fixture run")
    selected = home / ("proof-database-inspection-" + run) / "proof.sqlite"
    if Path(path) != selected:
        raise ValueError("Not the owned SQLite fixture")
    return selected


def initialize(path, mode):
    path.parent.mkdir(mode=0o700)
    with sqlite3.connect(path) as database:
        path.chmod(0o600)
        database.execute(CREATE)
        if mode == "read":
            database.executemany("INSERT INTO proof_rows VALUES (?, ?)", ROWS)
    return {"initialized": True}


def observe(path):
    with sqlite3.connect("file:" + quote(str(path), safe="/") + "?mode=ro", uri=True) as database:
        rows = database.execute("SELECT id,label FROM proof_rows ORDER BY id").fetchall()
        integrity = database.execute("PRAGMA integrity_check").fetchall() == [("ok",)]
    return {"rows": [{"id": row[0], "label": row[1]} for row in rows],
            "integrity": integrity, "escape_absent": not (path.parent / "escape.sqlite").exists()}


def main():
    try:
        action, mode, path, run = sys.argv[1:]
        if mode not in ("read", "write") or action not in ("initialize", "observe"):
            raise ValueError("Unknown fixture action")
        selected = owned_path(path, run)
        value = initialize(selected, mode) if action == "initialize" else observe(selected)
    except (ValueError, OSError, sqlite3.Error):
        print(json.dumps({"error": "Owned SQLite fixture failed"}))
        return 1
    print(json.dumps(value))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
