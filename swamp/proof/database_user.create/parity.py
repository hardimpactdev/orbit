"""Public SQL-user parity using D's generated-secret API fixture contract."""

import argparse
import json
import os
import re
import shlex
import subprocess
import tempfile
from pathlib import Path

CASES = ["exact-user-operation-native-public-result", "projected-user-grants", "generated-password-authenticates",
         "actual-sql-grants-and-write-policy", "independent-owner-readback", "private-client-and-scratch-cleanup"]


def require(value, message):
    if not value:
        raise AssertionError(message)


def read_secret(path):
    require(not path.is_symlink() and path.is_file() and path.stat().st_mode & 0o777 == 0o600,
            "Proof credential file is not private")
    value = json.loads(path.read_text())
    require(isinstance(value, dict) and set(value) == {"user_password"}
            and isinstance(value["user_password"], str) and re.fullmatch(r"[0-9a-f]{64}", value["user_password"]),
            "Invalid generated proof credential")
    return value


class Topology:
    def __init__(self):
        self.prefix = shlex.split(os.environ["PROOF_EXEC"])
        require(bool(self.prefix), "Missing topology executor")

    def execute(self, node, argv, stdin=None):
        # This is the existing topology executor's stdin schema, not a new API transport.
        with tempfile.NamedTemporaryFile(mode="w", prefix="orbit-proof-argv-", suffix=".json") as payload:
            Path(payload.name).chmod(0o600)
            json.dump({"argv": argv, "stdin": stdin}, payload)
            payload.flush()
            reply = subprocess.run([*self.prefix, node, "--timeout=1800", "--argv-file=" + payload.name],
                                   capture_output=True, text=True, timeout=1830, check=False)
        try:
            value = json.loads(reply.stdout)
        except ValueError:
            raise AssertionError("Topology returned no public JSON") from None
        require(isinstance(value, dict), "Topology returned a non-object")
        return reply.returncode, value

    def cli(self, *argv):
        return self.execute("operator", ["orbit", *argv, "--json"])

    def login(self, driver, server, process_id, username, mode, operation_id, ip, port, secret):
        body = Path(__file__).with_name("login.py").read_text()
        return self.execute("app-dev", ["sudo", "-n", "python3", "-I", "-c", body, driver, server,
                                        str(process_id), username, mode, str(operation_id), ip, str(port)], json.dumps(secret))


def success(reply):
    code, value = reply
    require(code == 0, "Public CLI or workload command failed")
    return value


def safe(value, secret):
    require(secret["user_password"] not in json.dumps(value), "Public output exposes the generated credential")
    if isinstance(value, dict):
        require(not ({"password", "payload", "private_material", "root_password"} & set(value)),
                "Public output exposes private material")
        for child in value.values():
            safe(child, secret)
    elif isinstance(value, list):
        for child in value:
            safe(child, secret)


def run(topology, driver, mode, slug, server, connection_id, server_id, node_id, port, ip, operation_id, outputs, secret):
    require(all(type(v) is int and v > 0 for v in [connection_id, server_id, node_id, operation_id]),
            "Invalid proof identity")
    reader, username = mode == "reader", "proof_reader" if mode == "reader" else "proof_writer"
    proof = success(topology.cli("operation:show", str(operation_id)))
    require(proof.get("id") == operation_id and proof.get("kind") == "database_user.create"
            and proof.get("status") == "succeeded" and proof.get("target_type") == "database_server"
            and proof.get("target_id") == server_id, "Wrong user Operation identity or terminal")
    intent = proof.get("input", {})
    expected = {"connection_id": connection_id, "driver": driver, "username": username, "database": "proof_users",
                "read_only": reader, "replication": False, "known_user": False}
    require(all(intent.get(k) == v and type(intent.get(k)) is type(v) for k, v in expected.items()),
            "Wrong user intent")
    safe(proof, secret)
    require(proof.get("result") == {"backend": "active"} and isinstance(outputs, dict)
            and type(outputs.get("exitCode")) is int and outputs["exitCode"] == 0
            and json.loads(outputs["stdout"]) == proof["result"], "Native/public user result differs")
    safe(outputs, secret)
    connection = success(topology.cli("database:show", slug))
    require(all(connection.get(k) == v for k, v in {"id": connection_id, "server": server, "driver": driver,
                "node_id": node_id, "database": "proof_users", "port": port, "host": ip}.items())
            and connection.get("has_password") is True, "Connection identity differs")
    record = success(topology.cli("database:server:show", server))
    process_id = record.get("process_id")
    require(record.get("id") == server_id and record.get("node_id") == node_id and record.get("driver") == driver
            and record.get("port") == port and record.get("status") == "active"
            and type(process_id) is int and process_id > 0
            and intent.get("container") == f"orbit-process-{process_id}-{server}", "Server identity differs")
    users = success(topology.cli("database:user:list", slug))
    expected_grants = (("SELECT" if reader else "ALL PRIVILEGES") + " ON `proof_users`.*" if driver == "mysql" else
                       ("CONNECT, USAGE, SELECT" if reader else "DATA WRITE") + ' ON "proof_users"')
    matches = [u for u in users.get("users", []) if u.get("username") == username]
    require(len(matches) == 1 and matches[0].get("database_connection_id") == connection_id
            and matches[0].get("privileges") == expected_grants and matches[0].get("replication") is False,
            "Projected user grants differ")
    for public in [connection, record, users]:
        safe(public, secret)
    login = success(topology.login(driver, server, process_id, username, mode, operation_id, ip, port, secret))
    require(login == {"authenticated": True, "password_enforced": True, "grants_match": True, "write_allowed": not reader,
                      "rows_match": True, "credential_file_absent": True, "scratch_absent": True}
            and all(type(v) is bool for v in login.values()), "Independent SQL user check differs")
    observed = success(topology.cli("database:query", slug, "SELECT id,label FROM proof_rows ORDER BY id"))
    safe(observed, secret)
    require(observed.get("kind") == "database.read" and observed.get("status") == "succeeded"
            and observed.get("target_type") == "database_connection" and observed.get("target_id") == connection_id
            and observed.get("input", {}).get("connection_id") == connection_id, "Wrong owner readback identity")
    expected_rows = [{"id": 1, "label": "before"}] + ([] if reader else [{"id": 2, "label": "user-proof"}])
    result = observed.get("result", {})
    require(type(result.get("row_count")) is int and type(result.get("truncated")) is bool
            and all(type(row.get("id")) is int for row in result.get("rows", []))
            and result == {"columns": ["id", "label"], "rows": expected_rows,
                                       "row_count": len(expected_rows), "truncated": False}, "Owner readback differs")
    return CASES


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("driver", choices=["mysql", "pgsql"])
    parser.add_argument("mode", choices=["reader", "writer"])
    parser.add_argument("slug")
    parser.add_argument("server")
    for name in ["connection-id", "server-id", "node-id", "port"]:
        parser.add_argument("--" + name, type=int, required=True)
    parser.add_argument("--ip", required=True)
    try:
        run(Topology(), **vars(parser.parse_args()), operation_id=int(os.environ["PROOF_OPERATION_ID"]),
            outputs=json.loads(Path(os.environ["PROOF_OUTPUTS"]).read_text()),
            secret=read_secret(Path(os.environ["PROOF_SECRETS"])))
    except (AssertionError, KeyError, OSError, ValueError, TypeError, subprocess.TimeoutExpired):
        print('{"error":"SQL user parity failed"}')
        return 1
    print(json.dumps({"passed": CASES}))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
