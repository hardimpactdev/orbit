"""Authenticate once against the final TCP server. Native resume owns another attempt."""

from server_common import Refused, client, main, valkey


def ready(intent, material):
    if intent["driver"] == "redis":
        if valkey(intent, material, "PING") != "PONG":
            raise Refused("database.server_not_ready")
    else:
        argv = (
            [
                "psql",
                "--no-psqlrc",
                "--host=127.0.0.1",
                "--username=postgres",
                "--dbname=postgres",
                "--no-align",
                "--tuples-only",
                "--quiet",
                "--set=ON_ERROR_STOP=1",
            ]
            if intent["driver"] == "pgsql"
            else [
                "mysql",
                "--protocol=TCP",
                "--host=127.0.0.1",
                "--user=root",
                "--batch",
                "--skip-column-names",
            ]
        )
        reply = client(intent, material, argv, "SELECT 1;\n")
        if reply.returncode or reply.stdout.strip() != "1":
            raise Refused("database.server_not_ready")
    return {"ready": True}


if __name__ == "__main__":
    main(ready)
