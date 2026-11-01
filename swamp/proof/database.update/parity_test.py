"""The parity checker must fail when observable database contracts are broken."""

import importlib.util
import json
import subprocess
import unittest
from pathlib import Path
from unittest.mock import patch

SPEC = importlib.util.spec_from_file_location("database_update_parity", Path(__file__).with_name("parity.py"))
parity = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(parity)
OUTPUTS = {"stdout": '{"backend":"active"}\n', "exitCode": 0}


class PublicTopology:
    def __init__(self, managed=True):
        self.record = {"id": 11, "driver": "redis" if managed else "sqlite",
                       "server": "proof-cache" if managed else None,
                       "database": "3" if managed else None, "node_id": 5,
                       "path": None if managed else "/proof/after.sqlite", "host": "192.0.2.1"}
        self.operation = {"id": 7, "target_id": 11, "target_type": "database_connection",
                          "kind": "database.update", "status": "succeeded",
                          "result": {"backend": "active"}}
        self.corrupt_refusal = False
        self.ignore_node = False
        self.keep_path = False
        self.scratch_absent = True
        self.reads = 0

    def execute(self, node, argv):
        assert node == "app-dev"
        assert argv[-1] == "/tmp/orbit-database-update-8"
        return 0, {"absent": self.scratch_absent}

    def cli(self, *argv):
        if argv[0] == "operation:show":
            return 0, self.operation.copy()
        if argv[0] == "database:show":
            self.reads += 1
            return 0, {**self.record, "request_id": f"request-{self.reads}"}
        option = argv[2]
        if option == "--database=2147483647":
            if self.corrupt_refusal:
                self.record["database"] = "2147483647"
            return 1, {"id": 8, "kind": "database.update", "status": "failed",
                       "error_code": "database.index_invalid"}
        if option == "--host=192.0.2.90":
            return 1, {"error": {"code": "database.server_connection_managed"}}
        if option == "--node=":
            if not self.ignore_node:
                self.record["node_id"] = None
        elif option == "--driver=redis":
            self.record.update(driver="redis", host="192.0.2.91")
            if not self.keep_path:
                self.record["path"] = None
        else:
            raise AssertionError("Unexpected test command")
        return 0, self.operation.copy()


class UpdateParityTest(unittest.TestCase):
    def run_parity(self, topology, mode="managed", outputs=OUTPUTS):
        return parity.run(topology, mode, "proof-connection", "/proof/after.sqlite", 7, outputs)

    def test_managed_contract_checks_success_and_both_refusals(self):
        self.assertEqual(len(self.run_parity(PublicTopology())), 4)

    def test_independent_read_transport_ids_do_not_change_the_resource(self):
        topology = PublicTopology()
        self.run_parity(topology)
        self.assertEqual(topology.reads, 4)

    def test_failed_index_cannot_leave_remote_scratch(self):
        topology = PublicTopology()
        topology.scratch_absent = False
        with self.assertRaisesRegex(AssertionError, "left remote scratch"):
            self.run_parity(topology)

    def test_index_refusal_cannot_hide_a_changed_record(self):
        topology = PublicTopology()
        topology.corrupt_refusal = True
        with self.assertRaisesRegex(AssertionError, "changed the connection"):
            self.run_parity(topology)

    def test_record_only_contract_checks_clear_and_driver_normalization(self):
        self.assertEqual(len(self.run_parity(PublicTopology(False), "record")), 3)

    def test_ignored_explicit_clear_is_detected(self):
        topology = PublicTopology(False)
        topology.ignore_node = True
        with self.assertRaisesRegex(AssertionError, "not cleared"):
            self.run_parity(topology, "record")

    def test_driver_change_cannot_leave_a_sqlite_path(self):
        topology = PublicTopology(False)
        topology.keep_path = True
        with self.assertRaisesRegex(AssertionError, "retained a SQLite path"):
            self.run_parity(topology, "record")

    def test_wrong_or_failed_primary_operation_never_proves_success(self):
        for changes in ({"id": 8}, {"target_id": 12}, {"status": "failed"}, {"kind": "database.create"}):
            with self.subTest(changes=changes):
                topology = PublicTopology()
                topology.operation.update(changes)
                with self.assertRaises(AssertionError):
                    self.run_parity(topology)

    def test_nonzero_or_private_native_result_cannot_pass(self):
        for output in ({**OUTPUTS, "exitCode": 1},
                       {**OUTPUTS, "stdout": json.dumps({"backend": "active", "password": "private"})}):
            with self.subTest(output=output), self.assertRaises(AssertionError):
                self.run_parity(PublicTopology(), outputs=output)

    def test_missing_topology_executor_does_not_fall_back_to_a_live_cli(self):
        with patch.dict("os.environ", {}, clear=True), self.assertRaises(KeyError):
            parity.Topology()

    def test_transport_failure_never_echoes_raw_private_diagnostics(self):
        with patch.dict("os.environ", {"PROOF_EXEC": "bin/e2e-topology exec TASK-proof"}), \
                patch.object(subprocess, "run", return_value=subprocess.CompletedProcess([], 1, "private-value", "private-error")):
            with self.assertRaisesRegex(AssertionError, "returned no public JSON") as error:
                parity.Topology().cli("database:show", "proof")
            self.assertNotIn("private", str(error.exception))


class FixtureContractTests(unittest.TestCase):
    def test_current_fixture_contract(self):
        for path in Path(__file__).parent.glob("*.json"):
            with self.subTest(path=path):
                fixture = json.loads(path.read_text())
                self.assertEqual(fixture["topology"], ["app-dev+database"])
                self.assertEqual(fixture["request"]["operation"], "$.id")
                self.assertNotIn("secrets", fixture)
                self.assertNotIn("target_setup", fixture)
                self.assertIsNone(fixture["queue_timeout_seconds"])
                for call in [fixture["request"], *fixture["prepare"]]:
                    self.assertFalse(any("--password" in arg for arg in call["argv"]))


if __name__ == "__main__":
    unittest.main()
