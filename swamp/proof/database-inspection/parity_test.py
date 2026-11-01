"""Checker regressions and local SQLite fixtures; no infrastructure or live Operations."""

import copy
import importlib.util
import json
import sqlite3
import subprocess
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import yaml


def load(name):
    spec = importlib.util.spec_from_file_location(name, Path(__file__).with_name(name + ".py"))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


parity, fixture = load("parity"), load("sqlite_fixture")
CID, NODE, OPERATION = 17, 7, 27
SLUG, SERVER, IP = "proof-read-mysql-run", "proof-server-run", "10.44.0.7"
PATH = "/home/orbit/proof-database-inspection-run/proof.sqlite"
COLUMNS = [{"name": "id", "type": "integer", "primary": True, "nullable": False, "default": None},
           {"name": "label", "type": "varchar(64)", "primary": False, "nullable": False, "default": None}]


def result(rows):
    return {"columns": ["id", "label"], "rows": rows, "row_count": len(rows), "truncated": False}


class FakeTopology(parity.Topology):
    def __init__(self, mode="read", driver="mysql"):
        self.mode, self.driver = mode, driver
        self.rows = copy.deepcopy(parity.READ_ROWS if mode == "read" else parity.WRITE_ROWS)
        self.original = self.op("database." + mode,
                                result(self.rows) if mode == "read" else
                                {"backend": "active", "row_count": 1, "truncated": False}, OPERATION)
        self.connection = {"id": CID, "slug": SLUG, "driver": driver, "node_id": NODE,
                           "server": SERVER, "host": IP, "port": 33190, "has_password": True,
                           "path": PATH if driver == "sqlite" else None}
        self.columns, self.calls, self.bounded = copy.deepcopy(COLUMNS), [], 500
        self.private_extra, self.changed_by_refusal, self.escaped = False, False, False
        self.wrong_supplementary_target, self.fake_independent = False, False
        self.bad_missing, self.bad_schema = False, False

    def op(self, kind, value, identity=37, status="succeeded"):
        return {"id": identity, "kind": kind, "target_type": "database_connection", "target_id": CID,
                "status": status, "input": {"connection_id": CID, "driver": self.driver,
                                             "connection_node_id": NODE}, "result": value,
                **({"error_code": value["error_code"]} if status == "failed" else {})}

    def execute(self, node, argv):
        self.calls.append((node, argv))
        if node == "app-dev":
            return 0, {"rows": [] if self.fake_independent else self.rows,
                       "integrity": True, "escape_absent": not self.escaped}
        command, *args = argv[1:-1]
        if command == "operation:show":
            return 0, self.original
        if command == "database:show":
            return 0, self.connection
        if command == "database:query":
            sql = args[1]
            if sql.startswith("DELETE") or ";" in sql:
                if self.changed_by_refusal:
                    self.rows = []
                return 1, {"error": {"code": "database.sql_multiple_statements" if ";" in sql else "database.write_required"}}
            if sql.startswith(("ATTACH", "VACUUM")):
                return 1, self.op("database.write", {"error_code": "database.query_failed"}, status="failed")
            value = ({"columns": ["n"], "rows": [{"n": n} for n in range(1, self.bounded + 1)],
                      "row_count": self.bounded, "truncated": True} if sql == parity.BOUNDED else result(self.rows))
        elif command == "database:tables":
            value = {"tables": ["proof_rows"]}
        elif command == "database:schema":
            value = {"tables": [] if self.bad_schema else [{"name": "proof_rows", "columns": self.columns}]}
        elif command == "database:describe":
            if args[1] == "proof_missing":
                return 1, self.op("database.read", {"error_code": "database.query_failed" if self.bad_missing else "database.table_missing"}, status="failed")
            value = {"table": "proof_rows", "columns": self.columns}
        else:
            raise AssertionError("Unexpected checker command")
        op = self.op("database.read", value)
        if self.private_extra:
            op["input"]["sql"] = "private"
        if self.wrong_supplementary_target:
            op["target_id"] = CID + 1
        return 0, op


def check(topology, outputs=None):
    return parity.run(topology, topology.mode, topology.driver, SLUG, CID, NODE,
                      PATH if topology.driver == "sqlite" else None, SERVER, 33190, IP, "run", OPERATION,
                      outputs if outputs is not None else {"exitCode": 0, "stdout": json.dumps(topology.original["result"])})


class CheckerTests(unittest.TestCase):
    def test_six_prepared_variants_and_named_cases(self):
        for mode in ["read", "write"]:
            for driver in ["mysql", "pgsql", "sqlite"]:
                with self.subTest(mode=mode, driver=driver):
                    topology = FakeTopology(mode, driver)
                    cases = check(topology)
                    self.assertIn("stacked-sql-refused-with-data-intact", cases)
                    self.assertEqual("independent-sqlite-file-readback" in cases, driver == "sqlite")

    def test_wrong_proof_identity_or_terminal_fails(self):
        for key, value in [("id", 28), ("kind", "database.clone"), ("status", "running"),
                           ("target_id", 99), ("target_type", "database_server")]:
            topology = FakeTopology()
            topology.original[key] = value
            with self.subTest(key=key), self.assertRaises(AssertionError):
                check(topology)

    def test_wrong_native_result_or_type_fails(self):
        for outputs in [{"exitCode": 1, "stdout": '{}'}, {"exitCode": False, "stdout": '{}'},
                        {"exitCode": 0, "stdout": '{"password":"private"}'}]:
            with self.subTest(outputs=outputs), self.assertRaises(AssertionError):
                check(FakeTopology(), outputs)

    def test_boolean_counts_and_integer_truncation_are_not_typed_results(self):
        for mode, key, value in [("write", "row_count", True), ("read", "truncated", 0)]:
            topology = FakeTopology(mode)
            topology.original["result"][key] = value
            with self.subTest(mode=mode, key=key), self.assertRaises(AssertionError):
                check(topology)

    def test_wrong_connection_identity_endpoint_or_file_fails(self):
        for driver, key, value in [("mysql", "id", 99), ("mysql", "node_id", 99),
                                   ("mysql", "server", "other"), ("mysql", "has_password", False),
                                   ("mysql", "host", "192.0.2.1"), ("sqlite", "path", "/other.sqlite")]:
            topology = FakeTopology(driver=driver)
            topology.connection[key] = value
            with self.subTest(driver=driver, key=key), self.assertRaises(AssertionError):
                check(topology)

    def test_refusal_must_leave_existing_rows(self):
        topology = FakeTopology()
        topology.changed_by_refusal = True
        with self.assertRaisesRegex(AssertionError, "readback"):
            check(topology)

    def test_supplementary_operation_must_target_same_connection(self):
        topology = FakeTopology()
        topology.wrong_supplementary_target = True
        with self.assertRaisesRegex(AssertionError, "another Connection"):
            check(topology)

    def test_private_material_is_rejected_recursively(self):
        topology = FakeTopology()
        topology.private_extra = True
        with self.assertRaisesRegex(AssertionError, "private material"):
            check(topology)
        with self.assertRaises(AssertionError):
            parity.private_free({"nested": [{"dsn": "private"}]})

    def test_row_cap_schema_missing_table_and_primary_metadata_are_checked(self):
        for field, value in [("bounded", 499), ("bad_schema", True), ("bad_missing", True)]:
            topology = FakeTopology()
            setattr(topology, field, value)
            with self.subTest(field=field), self.assertRaises(AssertionError):
                check(topology)
        topology = FakeTopology()
        topology.columns[0]["primary"] = False
        with self.assertRaises(AssertionError):
            check(topology)

    def test_failed_sqlite_escape_cannot_leave_a_file(self):
        topology = FakeTopology("write", "sqlite")
        topology.escaped = True
        with self.assertRaisesRegex(AssertionError, "SQLite"):
            check(topology)

    def test_sqlite_contents_must_be_independent_of_public_result(self):
        topology = FakeTopology("write", "sqlite")
        topology.fake_independent = True
        with self.assertRaisesRegex(AssertionError, "SQLite"):
            check(topology)

    def test_queries_pass_the_actual_product_read_policy(self):
        root = Path(__file__).resolve().parents[3]
        path = root / "swamp/extensions/behaviors/operations/database-read-commands/scripts/read_policy.php"
        for driver in ["mysql", "pgsql"]:
            for sql in [parity.READ, parity.BOUNDED]:
                reply = subprocess.run(["php", "-r", 'require $argv[1]; assertReadSql($argv[2], $argv[3]);',
                                        str(path), sql, driver], capture_output=True, timeout=10, check=False)
                with self.subTest(driver=driver, sql=sql):
                    self.assertEqual(reply.returncode, 0)

    def test_transport_failure_does_not_echo_private_diagnostics(self):
        with patch.dict("os.environ", {"PROOF_EXEC": "bin/e2e-topology exec TASK-proof"}), \
                patch.object(subprocess, "run", return_value=subprocess.CompletedProcess([], 1, "private", "private")):
            with self.assertRaisesRegex(AssertionError, "no public JSON") as error:
                parity.Topology().cli("database:show", SLUG)
            self.assertNotIn("private", str(error.exception))

    def test_guest_timeout_does_not_use_the_sixty_second_default(self):
        with patch.dict("os.environ", {"PROOF_EXEC": "bin/e2e-topology exec TASK-proof"}), \
                patch.object(subprocess, "run", return_value=subprocess.CompletedProcess([], 0, "{}", "")) as call:
            parity.Topology().cli("database:show", SLUG)
            self.assertIn("--timeout=1800", call.call_args.args[0])
            self.assertEqual(call.call_args.kwargs["timeout"], 1830)


class SqliteFixtureTests(unittest.TestCase):
    def test_real_read_fixture_is_private_and_refuses_reinitialization(self):
        with tempfile.TemporaryDirectory() as home:
            path = fixture.owned_path(str(Path(home, "proof-database-inspection-run/proof.sqlite")), "run", Path(home))
            fixture.initialize(path, "read")
            self.assertEqual(fixture.observe(path), {"rows": parity.READ_ROWS, "integrity": True, "escape_absent": True})
            self.assertEqual(path.stat().st_mode & 0o777, 0o600)
            with self.assertRaises(FileExistsError):
                fixture.initialize(path, "read")

    def test_real_write_fixture_observes_insert_and_escape_file(self):
        with tempfile.TemporaryDirectory() as home:
            path = fixture.owned_path(str(Path(home, "proof-database-inspection-run/proof.sqlite")), "run", Path(home))
            fixture.initialize(path, "write")
            with sqlite3.connect(path) as database:
                database.execute(parity.INSERT)
            self.assertEqual(fixture.observe(path)["rows"], parity.WRITE_ROWS)
            path.with_name("escape.sqlite").write_bytes(b"unexpected")
            self.assertFalse(fixture.observe(path)["escape_absent"])

    def test_source47_and_traversal_cannot_be_selected(self):
        for value in ["/home/nckrtl/sandbox/db-native-canary-20261031-a/proof.sqlite",
                      "/home/orbit/proof-database-inspection-run/../proof.sqlite"]:
            with self.subTest(value=value), self.assertRaises(ValueError):
                fixture.owned_path(value, "run")


class FixtureContractTests(unittest.TestCase):
    def test_all_six_fixtures_match_the_current_source_contract(self):
        root = Path(__file__).resolve().parents[2]
        flows = {flow["name"]: flow for path in (root / "workflows").glob("*.yaml")
                 if isinstance(flow := yaml.safe_load(path.read_text()), dict)}
        paths = [path for kind in ["database.read", "database.write"] for path in (root / "proof" / kind).glob("*.json")]
        self.assertEqual(len(paths), 6)
        for path in paths:
            with self.subTest(path=path):
                value = json.loads(path.read_text())
                self.assertEqual(value["kind"], path.parent.name)
                self.assertEqual(value["topology"], ["app-dev+database"])
                self.assertEqual(value["request"]["operation"], "$.id")
                flow = flows[value["workflow"]]
                self.assertEqual(value["queue_timeout_seconds"], flow.get("queueTimeout"))
                self.assertFalse(set(value) & {"secrets", "target_setup"})
                for call in [value["request"], *value["prepare"]]:
                    self.assertEqual(call["argv"][0], "orbit")
                    self.assertIn("--json", call["argv"])
                    self.assertFalse(any("--password" in argument for argument in call["argv"]))
                for hook in value.get("before_request", []):
                    self.assertTrue((root.parent / hook["argv"][2]).is_file())
                for selected in [*value["phases"], value["expect"]["outputs"]]:
                    job = next(job for job in flow["jobs"] if job["name"] == selected["job"])
                    self.assertIn(selected["step"], [step["name"] for step in job["steps"]])


if __name__ == "__main__":
    unittest.main()
