"""One replayable replacement copy; a failed import starts from empty owned targets."""

from create_common import identifier, sql
from create_sql import authenticate, probe_databases, probe_user
from mysql_clone_common import main, source_exists
from server_common import DOCKER, SOCKET, Refused, command, credentials


def apply(i, m, source):
    # Re-prove ownership at the destructive boundary, including native resume.
    source_exists(i, m, source)
    probe_user(i, m, {})
    probe_databases(i, m, {})
    authenticate(i, m, {})
    sql(
        i,
        m,
        "\n".join(
            "DROP DATABASE IF EXISTS "
            + identifier(n)
            + "; CREATE DATABASE "
            + identifier(n)
            + ";"
            for n in i["databases"]
        ),
    )
    with credentials(i, m) as path:
        result = command(
            [
                DOCKER,
                SOCKET,
                "exec",
                "-i",
                "--env-file",
                path,
                i["container"],
                "/bin/bash",
                "-o",
                "pipefail",
                "-c",
                'mysqldump --user=root --single-transaction --routines --triggers --no-tablespaces --set-gtid-purged=OFF "$1" | mysql --user=root "$2"',
                "orbit-clone",
                source,
                i["databases"][0],
            ]
        )
    if result.returncode:
        raise Refused("database.server_admin_failed")
    return {"backend": "active"}


if __name__ == "__main__":
    main(apply)
