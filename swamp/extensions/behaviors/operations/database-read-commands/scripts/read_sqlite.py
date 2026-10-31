"""One bounded SQLite read under the admitted account; no workflow control."""

import contextlib
import json
import os
import pwd
import re
import signal
import sqlite3
import stat
import sys
import time
from pathlib import Path
from urllib.parse import quote


class Refused(Exception):
    pass


def require(ok):
    if not ok:
        raise Refused()


def redact(value):
    if isinstance(value, list):
        return [redact(item) for item in value]
    if isinstance(value, dict):
        return {redact(key): redact(item) for key, item in value.items()}
    if not isinstance(value, str):
        return value
    value = re.sub(
        r"-----BEGIN [A-Z0-9 ]+-----[\s\S]*?(?:-----END [A-Z0-9 ]+-----|$)",
        "[REDACTED]",
        value,
    )
    value = re.sub(
        r"\b([a-z][a-z0-9+.-]*://)[^/@\s]+@",
        r"\1[REDACTED]@",
        value,
        flags=re.IGNORECASE,
    )
    value = re.sub(
        r"\b((?:Proxy-)?Authorization)\s*:\s*[^\s'\"]+(?:\s+[^\s'\"]+)?",
        r"\1: [REDACTED]",
        value,
        flags=re.IGNORECASE,
    )
    value = re.sub(
        r"\bBearer\s+(?:\"[^\"]*\"|'[^']*'|[A-Za-z0-9][A-Za-z0-9._\-+/=]{7,})",
        "Bearer [REDACTED]",
        value,
        flags=re.IGNORECASE,
    )
    value = re.sub(
        r"\b(?:gh[pousr]_[A-Za-z0-9_]{16,}|github_pat_[A-Za-z0-9_]{20,})\b",
        "[REDACTED]",
        value,
    )
    return re.sub(
        r"\b((?:[A-Za-z][A-Za-z0-9]*[_-])*(?:TOKENS?|SECRETS?|PASSWORDS?|PASSWD|PASSPHRASE|CREDENTIALS?|BEARER|APIKEY|APPKEY|AUTHTOKEN|ACCESSTOKEN|PRIVATEKEY)(?:[_-](?:HASH|BASE))?)\s*([=:])\s*(?:\"[^\"]*\"|'[^']*'|[^\s&#,}]+)",
        r"\1\2[REDACTED]",
        value,
        flags=re.IGNORECASE,
    )


def read(intent, material):
    mode = intent["mode"]
    require(
        intent["driver"] == "sqlite"
        and mode in ("query", "tables", "schema", "describe")
    )
    require(set(material) == {"sql", "dsn", "username", "password"})
    require(
        material["dsn"] == ""
        and material["username"] is None
        and material["password"] == ""
    )
    sql = material["sql"]
    require(isinstance(sql, str) and len(sql.encode()) <= 16384 and "\0" not in sql)
    owner = intent["owner"]
    require(isinstance(owner, str) and re.fullmatch(r"[a-z_][a-z0-9_-]{0,31}", owner))
    require(os.geteuid() != 0 and pwd.getpwuid(os.geteuid()).pw_name == owner)
    selected = intent["path"]
    require(
        isinstance(selected, str)
        and selected.startswith("/")
        and len(selected) <= 1024
        and not re.search(r"[\x00\r\n]|(?:^|/)\.{1,2}(?:/|$)|//", selected)
        and not selected.endswith("/")
    )
    path = Path(selected)
    require(path.resolve(strict=True) == path and stat.S_ISREG(path.lstat().st_mode))
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    os.close(descriptor)
    with contextlib.closing(
        sqlite3.connect("file:" + quote(str(path), safe="/") + "?mode=ro", uri=True)
    ) as db:
        db.execute("PRAGMA query_only=ON")
        db.execute("PRAGMA busy_timeout=10000")
        db.setlimit(sqlite3.SQLITE_LIMIT_ATTACHED, 0)
        deadline = time.monotonic() + 30
        db.set_progress_handler(lambda: time.monotonic() >= deadline, 1000)
        pragmas = {
            "collation_list",
            "compile_options",
            "database_list",
            "foreign_key_list",
            "function_list",
            "index_info",
            "index_list",
            "index_xinfo",
            "module_list",
            "pragma_list",
            "table_info",
            "table_list",
            "table_xinfo",
        }

        def authorize(action, one, two, unused_db, unused_trigger):
            if action == sqlite3.SQLITE_PRAGMA:
                return (
                    sqlite3.SQLITE_OK
                    if (one or "").lower() in pragmas
                    else sqlite3.SQLITE_DENY
                )
            if action == sqlite3.SQLITE_FUNCTION and (two or "").lower() in (
                "load_extension",
                "writefile",
                "readfile",
            ):
                return sqlite3.SQLITE_DENY
            return (
                sqlite3.SQLITE_OK
                if action
                in (
                    sqlite3.SQLITE_SELECT,
                    sqlite3.SQLITE_READ,
                    sqlite3.SQLITE_FUNCTION,
                    sqlite3.SQLITE_RECURSIVE,
                )
                else sqlite3.SQLITE_DENY
            )

        db.set_authorizer(authorize)

        def query(statement):
            cursor = db.execute(statement)
            columns = [str(column[0]) for column in cursor.description or []]
            if len(columns) > 500:
                raise ValueError()
            rows = []
            truncated = False
            for record in cursor:
                row = {
                    name: (value.decode("utf-8") if isinstance(value, bytes) else value)
                    for name, value in zip(columns, record)
                }
                if (
                    len(rows) >= 500
                    or len(
                        json.dumps([columns, rows, row], ensure_ascii=False).encode()
                    )
                    > 11000
                ):
                    truncated = True
                    break
                rows.append(row)
            cursor.close()
            return {
                "columns": columns,
                "rows": rows,
                "row_count": len(rows),
                "truncated": truncated,
            }

        def names():
            return [
                row["name"]
                for row in query(
                    "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
                )["rows"]
            ]

        def columns(table):
            require(
                isinstance(table, str)
                and table
                and len(table) <= 255
                and "\0" not in table
            )
            result = query('PRAGMA table_info("' + table.replace('"', '""') + '")')[
                "rows"
            ]
            if not result:
                return None
            return [
                {
                    "name": row["name"],
                    "type": row["type"],
                    "nullable": not bool(row["notnull"]),
                    "default": row["dflt_value"],
                    "primary": bool(row["pk"]),
                }
                for row in result
            ]

        if mode == "query":
            return query(sql)
        if mode == "tables":
            return {"tables": names()}
        if mode == "describe":
            value = columns(intent["table"])
            return (
                {"error_code": "database.table_missing"}
                if value is None
                else {"table": intent["table"], "columns": value}
            )
        return {
            "tables": [{"name": name, "columns": columns(name)} for name in names()]
        }


def main():
    def timeout(unused_signal, unused_frame):
        raise TimeoutError()

    signal.signal(signal.SIGALRM, timeout)
    signal.alarm(30)
    try:
        intent = json.loads(sys.argv[1])
        material = json.loads(sys.stdin.buffer.read(65537))
        result = redact(read(intent, material))
        output = json.dumps(result, ensure_ascii=False, allow_nan=False)
        if len(output.encode()) > 15000:
            raise ValueError()
    except (Refused, PermissionError, FileNotFoundError):
        result = {"error_code": "database.sqlite_unreadable"}
        output = json.dumps(result)
    except (KeyError, OSError, sqlite3.Error, TypeError, ValueError, TimeoutError):
        result = {"error_code": "database.query_failed"}
        output = json.dumps(result)
    print(output)
    sys.exit(1 if "error_code" in result else 0)


if __name__ == "__main__":
    main()
