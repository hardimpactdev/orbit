"""Workload-only SQL login/grant check; credentials enter only on stdin."""

import argparse
import ipaddress
import json
import re
import subprocess
import sys
import tempfile
from pathlib import Path


def require(value, message):
    if not value:
        raise AssertionError(message)


def command(argv):
    return subprocess.run(["docker", "--host", "unix:///var/run/docker.sock", *argv],
                          capture_output=True, text=True, timeout=30, check=False)


def mysql_grants(text, username, read_only):
    target = "`" + username + "`@`%`"
    expected = {"GRANT USAGE ON *.* TO " + target,
                "GRANT " + ("SELECT" if read_only else "ALL PRIVILEGES") + r" ON `proof\_users`.* TO " + target}
    lines = text.strip().splitlines()
    return len(lines) == 2 and set(lines) == expected


def check(driver, slug, process_id, username, read_only, operation_id, password, ip, port):
    require(driver in {"mysql", "pgsql"} and re.fullmatch(r"proof-user-[a-z0-9-]{1,48}", slug), "Invalid owned server")
    require(type(process_id) is int and process_id > 0 and type(operation_id) is int and operation_id > 0,
            "Invalid proof identity")
    require(username == ("proof_reader" if read_only else "proof_writer")
            and isinstance(password, str) and re.fullmatch(r"[0-9a-f]{64}", password), "Invalid proof login")
    private = [ipaddress.ip_network(n) for n in ["10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16"]]
    require(any(ipaddress.ip_address(ip) in n for n in private) and type(port) is int and 1 <= port <= 65535,
            "Invalid private proof endpoint")
    name = f"orbit-process-{process_id}-{slug}"
    reply = command(["inspect", name])
    require(reply.returncode == 0, "Owned container missing")
    raw = json.loads(reply.stdout)[0]
    labels = raw["Config"].get("Labels") or {}
    require(labels.get("orbit.managed") == "true" and labels.get("orbit.container.kind") == "process"
            and labels.get("orbit.process.id") == str(process_id) and raw["State"]["Status"] == "running"
            and raw["Config"]["Image"] == {"mysql": "mysql:8.4", "pgsql": "postgres:18"}[driver],
            "Wrong owned container")
    bindings = raw["HostConfig"].get("PortBindings") or {}
    require(any(p.get("HostIp") == ip and p.get("HostPort") == str(port)
                for p in bindings.get("3306/tcp" if driver == "mysql" else "5432/tcp", [])),
            "Published endpoint differs")
    with tempfile.NamedTemporaryFile(mode="w", prefix="orbit-proof-user-", suffix=".env") as private:
        Path(private.name).chmod(0o600)

        def credential(value):
            private.seek(0)
            private.truncate()
            private.write(("MYSQL_PWD" if driver == "mysql" else "PGPASSWORD") + "=" + value + "\n")
            private.flush()

        def sql(statement):
            client = (["mysql", "--batch", "--raw", "--skip-column-names", "--host=" + ip, "--port=" + str(port),
                       "--user=" + username, "--database=proof_users", "--execute=" + statement] if driver == "mysql" else
                      ["psql", "-X", "-w", "-A", "-t", "-h", ip, "-p", str(port), "-U", username,
                       "-d", "proof_users", "-c", statement])
            return command(["exec", "--env-file", private.name, name, *client])

        credential(("0" if password[0] != "0" else "1") + password[1:])
        bad = sql("SELECT label FROM proof_rows ORDER BY id")
        auth_error = "Access denied for user" if driver == "mysql" else "password authentication failed"
        require(bad.returncode != 0 and auth_error in bad.stderr, "SQL endpoint did not enforce password authentication")
        credential(password)
        read = sql("SELECT label FROM proof_rows ORDER BY id")
        require(read.returncode == 0 and read.stdout.strip() == "before", "New SQL login failed")
        grants = sql("SHOW GRANTS FOR CURRENT_USER" if driver == "mysql" else
                     "SELECT rolsuper,rolcreatedb,rolcreaterole,rolreplication,rolbypassrls FROM pg_roles WHERE rolname=current_user")
        require(grants.returncode == 0 and (mysql_grants(grants.stdout, username, read_only) if driver == "mysql" else
                                           grants.stdout.strip() == "f|f|f|f|f"), "Actual SQL grants differ")
        write = sql("INSERT INTO proof_rows (id,label) VALUES (2,'user-proof')")
        refused = ("INSERT command denied to user" in write.stderr if driver == "mysql" else
                   "permission denied for table proof_rows" in write.stderr)
        require((write.returncode != 0 and refused) if read_only else write.returncode == 0,
                "SQL write permission differs")
        final = sql("SELECT label FROM proof_rows ORDER BY id")
        require(final.returncode == 0 and final.stdout.strip().splitlines() ==
                (["before"] if read_only else ["before", "user-proof"]), "User readback differs")
    require(not Path(private.name).exists(), "Client credential file remains")
    require(not Path(f"/tmp/orbit-database-user-{operation_id}").exists(), "User scratch remains")
    return {"authenticated": True, "password_enforced": True, "grants_match": True, "write_allowed": not read_only,
            "rows_match": True, "credential_file_absent": True, "scratch_absent": True}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("driver", choices=["mysql", "pgsql"])
    parser.add_argument("slug")
    parser.add_argument("process_id", type=int)
    parser.add_argument("username")
    parser.add_argument("mode", choices=["reader", "writer"])
    parser.add_argument("operation_id", type=int)
    parser.add_argument("ip")
    parser.add_argument("port", type=int)
    args = vars(parser.parse_args())
    try:
        secret = json.load(sys.stdin)
        require(isinstance(secret, dict) and set(secret) == {"user_password"}, "Invalid proof credential")
        mode = args.pop("mode")
        result = check(**args, read_only=mode == "reader", password=secret["user_password"])
    except (AssertionError, KeyError, OSError, ValueError, TypeError, subprocess.TimeoutExpired):
        print('{"error":"SQL user login check failed"}')
        return 1
    print(json.dumps(result))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
