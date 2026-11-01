"""Legacy Database update checks through the disposable topology's public CLI."""

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
        require(bool(self.prefix), "F13 topology executor is missing")

    def execute(self, node, argv):
        result = subprocess.run(
            [*self.prefix, node, "--argv=" + json.dumps(argv)],
            capture_output=True, text=True, timeout=180, check=False,
        )
        try:
            value = json.loads(result.stdout)
        except ValueError:
            raise AssertionError("Topology command returned no public JSON") from None
        require(isinstance(value, dict), "Topology command returned a non-object")
        # Do not echo raw command output or stderr: either can hold credentials.
        return result.returncode, value

    def cli(self, *argv):
        return self.execute("operator", ["orbit", *argv, "--json"])


def success(reply, kind=None):
    code, value = reply
    require(code == 0, "Public CLI request failed")
    if kind:
        require(value.get("kind") == kind, "Wrong Operation kind")
        require(value.get("status") == "succeeded", "Operation did not succeed")
        require(value.get("result") == {"backend": "active"}, "Wrong update result")
    return value


def managed_parity(topology, slug):
    def connection():
        value = success(topology.cli("database:show", slug))
        # SDK transport metadata changes on each independent read.
        return {key: item for key, item in value.items() if key != "request_id"}

    before = connection()
    require(before.get("driver") == "redis", "Managed fixture is not Valkey")
    require(before.get("database") == "3", "Valid proposed index was not projected")
    require(bool(before.get("server")), "Fixture has no managed server")
    # Valkey's database count is an int; its largest possible index is smaller.
    code, failed = topology.cli("database:update", slug, "--database=2147483647")
    require(code != 0, "Out-of-range index was accepted")
    require(failed.get("kind") == "database.update", "Index check was not admitted")
    require(failed.get("status") == "failed", "Index refusal was not terminal")
    require(failed.get("error_code") == "database.index_invalid", "Wrong index refusal")
    require(connection() == before,
            "Failed index validation changed the connection")
    failed_id = failed.get("id")
    require(type(failed_id) is int and failed_id > 0, "Failed update has no Operation id")
    inspection = success(topology.execute("app-dev", [
        "python3", "-c",
        "import json,pathlib,sys; print(json.dumps({'absent':not pathlib.Path(sys.argv[1]).exists()}))",
        "/tmp/orbit-database-update-" + str(failed_id),
    ]))
    require(inspection == {"absent": True}, "Failed index validation left remote scratch")
    code, refused = topology.cli("database:update", slug, "--host=192.0.2.90")
    require(code != 0, "Managed endpoint change was accepted")
    require(refused.get("error", {}).get("code") == "database.server_connection_managed",
            "Wrong managed-identity refusal")
    require(connection() == before,
            "Rejected identity change changed the connection")
    return ["managed-index-projected", "out-of-range-keeps-record",
            "failed-index-scratch-removed", "managed-identity-refused"]


def record_parity(topology, slug, path):
    record = success(topology.cli("database:show", slug))
    require(record.get("driver") == "sqlite", "Record fixture is not SQLite")
    require(record.get("path") == path, "Record-only path was not projected")
    require(record.get("server") is None, "Record-only fixture became managed")
    # Explicit empty --node clears association; it must not erase another patch.
    success(topology.cli("database:update", slug, "--node="), "database.update")
    cleared = success(topology.cli("database:show", slug))
    require(cleared.get("node_id") is None, "Explicit empty Node was not cleared")
    require(cleared.get("path") == path, "Node patch erased the admitted path")
    success(topology.cli("database:update", slug, "--driver=redis", "--host=192.0.2.91"),
            "database.update")
    changed = success(topology.cli("database:show", slug))
    require(changed.get("driver") == "redis", "Driver patch was not projected")
    require(changed.get("path") is None, "Driver change retained a SQLite path")
    require(changed.get("host") == "192.0.2.91", "Driver change lost its host")
    return ["record-path-projected", "explicit-node-clear", "driver-clears-inapplicable-fields"]


def run(topology, mode, slug, path, operation_id, outputs):
    require(type(operation_id) is int and operation_id > 0, "Missing proof Operation")
    operation = success(topology.cli("operation:show", str(operation_id)), "database.update")
    require(operation.get("id") == operation_id, "Wrong proof Operation id")
    require(operation.get("target_type") == "database_connection", "Wrong update target")
    connection = success(topology.cli("database:show", slug))
    require(operation.get("target_id") == connection.get("id"), "Operation belongs to another connection")
    require(outputs.get("exitCode") == 0, "Native result did not succeed")
    require(json.loads(outputs["stdout"]) == {"backend": "active"}, "Native result was not active")
    return (managed_parity(topology, slug) if mode == "managed"
            else record_parity(topology, slug, path))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("mode", choices=["managed", "record"])
    parser.add_argument("slug")
    parser.add_argument("--path")
    args = parser.parse_args()
    try:
        cases = run(Topology(), args.mode, args.slug, args.path,
                    int(os.environ["PROOF_OPERATION_ID"]),
                    json.loads(Path(os.environ["PROOF_OUTPUTS"]).read_text()))
    except (AssertionError, KeyError, OSError, ValueError, subprocess.TimeoutExpired) as error:
        # Assertion text is fixed and public. Transport/decoder exceptions can echo data.
        detail = str(error) if isinstance(error, AssertionError) else "F13 parity setup or transport failed"
        print(json.dumps({"passed": 0, "failed": 1, "error": detail}))
        return 1
    print(json.dumps({"passed": len(cases), "failed": 0, "cases": cases}))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
