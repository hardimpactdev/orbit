"""Public CLI legacy parity for database reads and writes in a disposable topology."""

import argparse
import json
import os
import shlex
import subprocess
from pathlib import Path

READ = "SELECT id,label FROM proof_rows ORDER BY id"
INSERT = "INSERT INTO proof_rows (id,label) VALUES (1,'write-proof')"
BOUNDED = ("WITH RECURSIVE seq AS (SELECT 1 AS n UNION ALL SELECT n+1 FROM seq WHERE n<505) "
           "SELECT n FROM seq ORDER BY n")
READ_ROWS = [{"id": 1, "label": "proof-one"}, {"id": 2, "label": "proof-two"},
             {"id": 3, "label": "proof-three"}]
WRITE_ROWS = [{"id": 1, "label": "write-proof"}]


def require(value, message):
    if not value:
        raise AssertionError(message)


class Topology:
    def __init__(self):
        self.prefix = shlex.split(os.environ["PROOF_EXEC"])
        require(bool(self.prefix), "Missing topology executor")

    def execute(self, node, argv):
        reply = subprocess.run([*self.prefix, node, "--timeout=1800", "--argv=" + json.dumps(argv)],
                               capture_output=True, text=True, timeout=1830, check=False)
        try:
            value = json.loads(reply.stdout)
        except ValueError:
            raise AssertionError("Topology returned no public JSON") from None
        require(isinstance(value, dict), "Topology returned a non-object")
        return reply.returncode, value

    def cli(self, *argv):
        return self.execute("operator", ["orbit", *argv, "--json"])

    def sqlite(self, action, mode, path, run):
        body = Path(__file__).with_name("sqlite_fixture.py").read_text()
        return success(self.execute("app-dev", ["python3", "-I", "-c", body, action, mode, path, run]))


def success(reply):
    code, value = reply
    require(code == 0, "Public CLI or workload command failed")
    return value


def private_free(value):
    if isinstance(value, dict):
        require(not ({"sql", "password", "dsn", "private_material"} & set(value)),
                "Public input exposes private material")
        for child in value.values():
            private_free(child)
    elif isinstance(value, list):
        for child in value:
            private_free(child)


def operation(reply, kind, connection_id, driver, status="succeeded"):
    code, value = reply
    require((code == 0) == (status == "succeeded"), "Wrong Operation exit status")
    require(value.get("kind") == kind and value.get("status") == status, "Wrong Operation kind or terminal")
    require(type(value.get("id")) is int and value["id"] > 0, "Missing Operation ID")
    require(value.get("target_type") == "database_connection" and value.get("target_id") == connection_id,
            "Operation targets another Connection")
    require(value.get("input", {}).get("connection_id") == connection_id
            and value["input"].get("driver") == driver, "Wrong admitted Connection/driver")
    private_free(value["input"])
    return value


def query(topology, slug, connection_id, driver, sql=READ):
    value = operation(topology.cli("database:query", slug, sql), "database.read", connection_id, driver)
    require(sql not in json.dumps(value["input"]), "Public input exposes SQL")
    return value["result"]


def rows(result, expected):
    require(isinstance(result, dict), "Read result is not an object")
    require(type(result.get("row_count")) is int and type(result.get("truncated")) is bool,
            "Wrong read count/truncation types")
    require(isinstance(result.get("rows"), list) and all(isinstance(row, dict) and type(row.get("id")) is int
            and isinstance(row.get("label"), str) for row in result["rows"]), "Wrong read row types")
    require(result == {"columns": ["id", "label"], "rows": expected,
                       "row_count": len(expected), "truncated": False}, "Wrong ordered readback")


def refused(reply, expected):
    code, value = reply
    require(code != 0 and value.get("error", {}).get("code") == expected, "Wrong admission refusal")


def unchanged_after_refusals(topology, slug, connection_id, driver, expected):
    refused(topology.cli("database:query", slug, "DELETE FROM proof_rows"), "database.write_required")
    refused(topology.cli("database:query", slug, "SELECT 1; DELETE FROM proof_rows"),
            "database.sql_multiple_statements")
    rows(query(topology, slug, connection_id, driver), expected)
    return ["unflagged-write-refused", "stacked-sql-refused-with-data-intact"]


def read_parity(topology, slug, connection_id, driver):
    tables = operation(topology.cli("database:tables", slug), "database.read", connection_id, driver)["result"]
    require(tables == {"tables": ["proof_rows"]}, "Wrong table inventory")
    described = operation(topology.cli("database:describe", slug, "proof_rows"), "database.read", connection_id, driver)["result"]
    columns = described.get("columns")
    require(described.get("table") == "proof_rows" and isinstance(columns, list)
            and [column.get("name") for column in columns] == ["id", "label"], "Wrong column inventory")
    require(columns[0].get("primary") is True and columns[1].get("nullable") is False,
            "Wrong primary/nullability metadata")
    schema = operation(topology.cli("database:schema", slug), "database.read", connection_id, driver)["result"]
    require(schema == {"tables": [{"name": "proof_rows", "columns": columns}]}, "Schema/describe disagree")
    missing = operation(topology.cli("database:describe", slug, "proof_missing"), "database.read",
                        connection_id, driver, "failed")
    require(missing.get("error_code") == "database.table_missing"
            and missing.get("result") == {"error_code": "database.table_missing"}, "Wrong missing-table refusal")
    bounded = query(topology, slug, connection_id, driver, BOUNDED)
    require(isinstance(bounded, dict) and isinstance(bounded.get("rows"), list),
            "Wrong bounded-read shape")
    require(type(bounded.get("row_count")) is int and bounded.get("truncated") is True
            and all(isinstance(row, dict) and type(row.get("n")) is int for row in bounded["rows"]),
            "Wrong bounded-read result types")
    require(bounded == {"columns": ["n"], "rows": [{"n": n} for n in range(1, 501)],
                        "row_count": 500, "truncated": True}, "Read row cap/truncation differs")
    return ["tables-schema-describe-agree", "missing-table-refused", "bounded-read-declares-truncation"]


def sqlite_checks(topology, mode, path, run, expected, slug, connection_id):
    cases = []
    if mode == "write":
        escaped = str(Path(path).with_name("escape.sqlite"))
        for sql in ["ATTACH DATABASE '" + escaped + "' AS escaped", "VACUUM INTO '" + escaped + "'"]:
            failed = operation(topology.cli("database:query", slug, sql, "--write"),
                               "database.write", connection_id, "sqlite", "failed")
            require(failed.get("error_code") == "database.query_failed"
                    and failed.get("result") == {"error_code": "database.query_failed"},
                    "Wrong SQLite escape refusal")
            observed = topology.sqlite("observe", mode, path, run)
            require(observed == {"rows": expected, "integrity": True, "escape_absent": True},
                    "Failed SQLite escape changed workload files")
        cases.append("sqlite-attach-vacuum-escape-refused")
    observed = topology.sqlite("observe", mode, path, run)
    require(observed == {"rows": expected, "integrity": True, "escape_absent": True},
            "Independent SQLite contents/integrity/escape state differs")
    return [*cases, "independent-sqlite-file-readback"]


def run(topology, mode, driver, slug, connection_id, node_id, path, server, port, ip, run_id, operation_id, outputs):
    require(all(type(value) is int and value > 0 for value in [connection_id, node_id, operation_id]),
            "Missing proof resource identity")
    kind, expected = ("database.read", READ_ROWS) if mode == "read" else ("database.write", WRITE_ROWS)
    proof = operation(topology.cli("operation:show", str(operation_id)), kind, connection_id, driver)
    require(proof["id"] == operation_id, "Wrong proof Operation ID")
    require(proof["input"].get("connection_node_id") == node_id, "Wrong admitted workload Node")
    require((READ if mode == "read" else INSERT) not in json.dumps(proof["input"]),
            "Public proof input exposes SQL")
    require(isinstance(outputs, dict), "Native output is not an object")
    require(type(outputs.get("exitCode")) is int and outputs["exitCode"] == 0, "Native result failed")
    native = json.loads(outputs["stdout"])
    require(isinstance(native, dict), "Native result is not an object")
    require(native == proof.get("result"), "Native/public results differ")
    connection = success(topology.cli("database:show", slug))
    require(connection.get("id") == connection_id and connection.get("slug") == slug
            and connection.get("driver") == driver and connection.get("node_id") == node_id,
            "Wrong projected Connection identity")
    require(connection.get("path") == path if driver == "sqlite" else
            connection.get("server") == server and connection.get("port") == port
            and connection.get("host") == ip and connection.get("has_password") is True,
            "Wrong Connection file/managed endpoint")
    if mode == "read":
        rows(native, expected)
    else:
        require(type(native.get("row_count")) is int and type(native.get("truncated")) is bool,
                "Wrong write count/truncation types")
        require(native == {"backend": "active", "row_count": 1, "truncated": False},
                "Wrong public write row count")
    rows(query(topology, slug, connection_id, driver), expected)
    cases = ["exact-proof-identity-native-public-result", "independent-cli-readback"]
    cases += unchanged_after_refusals(topology, slug, connection_id, driver, expected)
    if mode == "read":
        cases += read_parity(topology, slug, connection_id, driver)
    if driver == "sqlite":
        cases += sqlite_checks(topology, mode, path, run_id, expected, slug, connection_id)
    return cases


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("mode", choices=["read", "write", "initialize-read", "initialize-write"])
    parser.add_argument("driver", choices=["mysql", "pgsql", "sqlite"])
    parser.add_argument("slug")
    parser.add_argument("--connection-id", type=int)
    parser.add_argument("--node-id", type=int)
    parser.add_argument("--path")
    parser.add_argument("--server")
    parser.add_argument("--port", type=int)
    parser.add_argument("--ip")
    args = parser.parse_args()
    try:
        topology, run_id = Topology(), os.environ["PROOF_RUN"]
        if args.mode.startswith("initialize-"):
            require(args.driver == "sqlite", "Initialize is only for the SQLite workload fixture")
            value = topology.sqlite("initialize", args.mode.removeprefix("initialize-"), args.path, run_id)
            require(value == {"initialized": True}, "SQLite initialization failed")
            print(json.dumps(value))
            return 0
        cases = run(topology, **vars(args), run_id=run_id, operation_id=int(os.environ["PROOF_OPERATION_ID"]),
                    outputs=json.loads(Path(os.environ["PROOF_OUTPUTS"]).read_text()))
    except (AssertionError, KeyError, OSError, TypeError, ValueError, subprocess.TimeoutExpired) as error:
        detail = str(error) if isinstance(error, AssertionError) else "Parity setup or transport failed"
        print(json.dumps({"passed": 0, "failed": 1, "error": detail}))
        return 1
    print(json.dumps({"passed": len(cases), "failed": 0, "cases": cases}))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
