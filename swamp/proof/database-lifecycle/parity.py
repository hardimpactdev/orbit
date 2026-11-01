"""F13 legacy lifecycle parity through the disposable topology's public CLI."""

import argparse
import json
import os
import shlex
import subprocess
from pathlib import Path


def require(value, message):
    if not value:
        raise AssertionError(message)


class Topology:
    def __init__(self):
        self.prefix = shlex.split(os.environ["PROOF_EXEC"])
        require(bool(self.prefix), "Missing topology executor")

    def execute(self, node, argv):
        reply = subprocess.run(
            [*self.prefix, node, "--timeout=1800", "--argv=" + json.dumps(argv)],
            capture_output=True, text=True, timeout=1830, check=False,
        )
        try:
            value = json.loads(reply.stdout)
        except ValueError:
            raise AssertionError("Topology returned no public JSON") from None
        require(isinstance(value, dict), "Topology returned a non-object")
        return reply.returncode, value

    def cli(self, *argv):
        return self.execute("operator", ["orbit", *argv, "--json"])

    def observe(self, mode, server, ip, marker, username=None):
        source = Path(__file__).with_name("observe.py").read_text()
        argv = ["sudo", "-n", "python3", "-I", "-c", source, mode, server["slug"],
                str(server["process_id"]), server["driver"], str(server["port"]), ip, marker]
        if username:
            argv += ["--username", username]
        value = success(self.execute("app-dev", argv))
        require(bool(value) and all(item is True for item in value.values()),
                "Workload state failed parity")
        return value


def success(reply, kind=None, backend=None):
    code, value = reply
    require(code == 0, "Public CLI command failed")
    if kind:
        require(value.get("kind") == kind and value.get("status") == "succeeded",
                "Wrong or unsuccessful Operation")
        require(type(value.get("id")) is int and value["id"] > 0, "Missing Operation ID")
    if backend:
        require(value.get("result") == {"backend": backend}, "Wrong public lifecycle result")
    return value


def record(reply):
    return {key: value for key, value in success(reply).items() if key != "request_id"}


def native(outputs, backend):
    require(type(outputs.get("exitCode")) is int and outputs["exitCode"] == 0,
            "Native result failed")
    require(json.loads(outputs["stdout"]) == {"backend": backend}, "Wrong native lifecycle result")


def server_record(topology, slug, driver, port, node_id):
    value = record(topology.cli("database:server:show", slug))
    require(value.get("slug") == slug and value.get("driver") == driver
            and value.get("status") == "active", "Server identity or state differs")
    require(value.get("node_id") == node_id and value.get("port") == port,
            "Server placement differs")
    require(type(value.get("process_id")) is int and value["process_id"] > 0,
            "Server has no Process")
    require(type(value.get("id")) is int and value["id"] > 0, "Server has no resource ID")
    return value


def refusal(reply, code):
    status, value = reply
    require(status != 0 and value.get("error", {}).get("code") == code,
            "Wrong admission refusal")


def sql_readback_query(driver):
    expression = "DATABASE()" if driver == "mysql" else "CURRENT_CATALOG"
    return "SELECT " + expression + " AS proof_database, CURRENT_USER AS proof_user"


def server_create(topology, operation, slug, driver, port, node_id, ip, marker):
    before = server_record(topology, slug, driver, port, node_id)
    require(operation["target_id"] == before["id"], "Create targets another server")
    require(operation["input"]["process_id"] == before["process_id"], "Create Process changed")
    topology.observe("active", before, ip, marker)
    repeated = success(topology.cli("database:server:create", slug, "--node=" + str(node_id),
                                   "--driver=" + driver, "--port=" + str(port)),
                       "database_server.create", "active")
    require(repeated["target_id"] == before["id"], "Exact create changed server identity")
    require(server_record(topology, slug, driver, port, node_id) == before,
            "Exact create changed server/Process record")
    topology.observe("active", before, ip, marker)
    refusal(topology.cli("database:server:create", slug, "--node=" + str(node_id),
                         "--driver=" + driver, "--port=" + str(port + 1)), "database.slug_conflict")
    require(server_record(topology, slug, driver, port, node_id) == before,
            "Rejected create changed server/Process record")
    return ["owned-running-server", "initialization-secret-scrubbed", "private-port-and-data-mount",
            "exact-create-retains-identities", "changed-port-refused"]


def database_create(topology, operation, slug, server_slug, driver, port, node_id, ip, marker):
    server = server_record(topology, server_slug, driver, port, node_id)
    before = record(topology.cli("database:show", slug))
    require(operation["target_id"] == server["id"] and operation["input"]["connection_id"] == before.get("id"),
            "Database create belongs to another resource")
    require(before.get("slug") == slug and before.get("driver") == driver
            and before.get("server") == server_slug, "Managed connection identity differs")
    require(before.get("node_id") == node_id and before.get("host") == ip
            and before.get("port") == port and before.get("has_password") is True,
            "Managed endpoint or generated credential missing")
    require(before.get("owner_instance_id") is None and before.get("test_database") is None,
            "Unowned fixture acquired an Instance/test database")
    if driver == "redis":
        require(before.get("database") == "0" and before.get("username") == slug,
                "Valkey index or ACL identity differs")
        topology.observe("active", server, ip, marker, username=slug)
        extra = ["hashed-restricted-valkey-acl"]
    else:
        expected_name = slug.replace("-", "_")
        require(before.get("database") == expected_name and before.get("username") == expected_name,
                "SQL database or owner name differs")
        query = success(topology.cli("database:query", slug, sql_readback_query(driver)),
                        "database.read")
        require(query.get("target_type") == "database_connection"
                and query.get("target_id") == before["id"], "Readback targets another connection")
        rows = query.get("result", {}).get("rows")
        require(isinstance(rows, list) and len(rows) == 1 and rows[0].get("proof_database") == expected_name,
                "Generated owner did not authenticate against its database")
        actual_user = rows[0].get("proof_user")
        require(isinstance(actual_user, str) and (actual_user.split("@", 1)[0] if driver == "mysql"
                                                 else actual_user) == before["username"],
                "Readback used another SQL identity")
        topology.observe("active", server, ip, marker)
        extra = ["sql-owner-authenticated-to-created-database"]
    options = ["--database=0", "--pattern=proof:*", "--allow=get"] if driver == "redis" else []
    refusal(topology.cli("database:create", slug, "--server=" + server_slug, *options),
            "database.slug_conflict")
    refusal(topology.cli("database:server:destroy", server_slug, "--force"), "database.server_in_use")
    require(record(topology.cli("database:show", slug)) == before,
            "Rejected lifecycle request changed the connection")
    require(server_record(topology, server_slug, driver, port, node_id) == server,
            "Rejected lifecycle request changed the server")
    return ["managed-connection-projected", *extra, "duplicate-slug-refused",
            "in-use-server-refused-with-records-intact"]


def server_destroy(topology, operation, slug, driver, port, node_id, ip, marker, server_id):
    require(operation["target_id"] == server_id and operation["input"]["server_id"] == server_id,
            "Destroy targets another server")
    process_id = operation["input"]["process_id"]
    require(type(process_id) is int and process_id > 0, "Destroy omitted Process identity")
    require(operation["input"]["container"] == f"orbit-process-{process_id}-{slug}",
            "Destroy admitted another container")
    refusal(topology.cli("database:server:show", slug), "http.404")
    servers = success(topology.cli("database:server:list"))["servers"]
    require(all(value["id"] != server_id and value["slug"] != slug for value in servers),
            "Destroyed server remains listed")
    topology.observe("absent", {"slug": slug, "process_id": process_id, "driver": driver,
                                "port": port}, ip, marker)
    return ["server-record-absent", "all-process-containers-absent", "named-volume-and-marker-retained"]


def run(topology, mode, slug, server, driver, port, node_id, ip, marker, server_id, operation_id, outputs):
    require(type(operation_id) is int and operation_id > 0, "Missing proof Operation")
    kind = {"create": "database.create", "server-create": "database_server.create",
            "server-destroy": "database_server.destroy"}[mode]
    backend = "absent" if mode == "server-destroy" else "active"
    operation = success(topology.cli("operation:show", str(operation_id)), kind, backend)
    require(operation["id"] == operation_id and operation.get("target_type") == "database_server",
            "Wrong proof Operation identity")
    native(outputs, backend)
    if mode == "create":
        return database_create(topology, operation, slug, server, driver, port, node_id, ip, marker)
    if mode == "server-create":
        return server_create(topology, operation, slug, driver, port, node_id, ip, marker)
    return server_destroy(topology, operation, slug, driver, port, node_id, ip, marker, server_id)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("mode", choices=["create", "server-create", "server-destroy", "mark"])
    parser.add_argument("slug")
    parser.add_argument("driver", choices=["mysql", "pgsql", "redis"])
    parser.add_argument("port", type=int)
    parser.add_argument("ip")
    parser.add_argument("--node-id", type=int, required=True)
    parser.add_argument("--server")
    parser.add_argument("--server-id", type=int)
    args = parser.parse_args()
    try:
        topology, marker = Topology(), os.environ["PROOF_RUN"]
        if args.mode == "mark":
            value = server_record(topology, args.slug, args.driver, args.port, args.node_id)
            topology.observe("mark", value, args.ip, marker)
            print(json.dumps({"marked": True}))
            return 0
        cases = run(topology, **vars(args), marker=marker,
                    operation_id=int(os.environ["PROOF_OPERATION_ID"]),
                    outputs=json.loads(Path(os.environ["PROOF_OUTPUTS"]).read_text()))
    except (AssertionError, KeyError, OSError, ValueError, TypeError, subprocess.TimeoutExpired) as error:
        detail = str(error) if isinstance(error, AssertionError) else "Parity setup or transport failed"
        print(json.dumps({"passed": 0, "failed": 1, "error": detail}))
        return 1
    print(json.dumps({"passed": len(cases), "failed": 0, "cases": cases}))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
