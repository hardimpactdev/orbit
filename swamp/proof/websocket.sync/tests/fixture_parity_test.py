"""Exercise F13 observations through the real topology exec response shape."""

import importlib.util
import json
import subprocess
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

SPEC = importlib.util.spec_from_file_location(
    "parity", Path(__file__).with_name("parity.py")
)
parity = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(parity)


class TopologyObservationTests(unittest.TestCase):
    def test_cli_uses_operator_and_decodes_nested_exec_stdout(self):
        reply = {
            "state": "executed",
            "exit_code": 0,
            "stdout": '{"apps":[]}',
            "stderr": "",
        }
        with (
            patch.dict("os.environ", {"PROOF_EXEC": "bin/e2e-topology exec TASK-123"}),
            patch.object(
                parity.subprocess,
                "run",
                return_value=subprocess.CompletedProcess([], 0, json.dumps(reply)),
            ) as run,
        ):
            self.assertEqual({"apps": []}, parity.cli("websocket:app:list"))
        argv = run.call_args.args[0]
        self.assertEqual(["bin/e2e-topology", "exec", "TASK-123", "operator"], argv[:4])
        self.assertEqual(
            ["orbit", "websocket:app:list", "--json"],
            json.loads(argv[4].removeprefix("--argv=")),
        )

    def test_guest_failure_is_never_treated_as_public_success(self):
        reply = {
            "state": "executed",
            "exit_code": 1,
            "stdout": '{"apps":[]}',
            "stderr": "",
        }
        with (
            patch.dict("os.environ", {"PROOF_EXEC": "bin/e2e-topology exec TASK-123"}),
            patch.object(
                parity.subprocess,
                "run",
                return_value=subprocess.CompletedProcess([], 0, json.dumps(reply)),
            ),
            self.assertRaises(AssertionError),
        ):
            parity.cli("websocket:app:list")

    def observations(self, kind):
        process_id = "28a932e4-a020-4f9e-b3a5-19af5dd21361"
        registry_id = "a518821d-6b9d-49df-bdcc-9353c1170135"
        field = "runtime_" + process_id.replace("-", "_")
        operation = {
            "kind": kind,
            "status": "succeeded",
            "result": {"outcome": "succeeded", "data": {"backend": "applied"}},
            "input": {
                "registry_operation_id": registry_id,
                "epoch": "public-epoch",
                "revision": 4,
                "native_registry": {
                    "start_tag": registry_id,
                    "registry_operation_id": registry_id,
                    "epoch": "public-epoch",
                    "revision": 4,
                    "missing_node_ids": [],
                    "app_id": None if kind == "websocket.sync" else "subject-public-id",
                    "runtimes": [
                        {
                            "process_id": process_id,
                            "node_id": 12,
                            "port": 8790,
                            "credential_field": field,
                            "ssh": {
                                "control_node_id": 209,
                                "identity_file": "/run/credentials/identity",
                                "target": {
                                    "host": "10.0.0.12",
                                    "host_keys": ["ssh-ed25519 public-pin"],
                                },
                            },
                        }
                    ],
                },
                "execution_secrets": {
                    field: {
                        "vault": "orbit-control-inputs",
                        "key": "websocket-registry-" + process_id,
                    }
                },
            },
        }
        return operation

    def run_parity(self, kind, operation, native_outputs=None):
        def execute(node, argv):
            if node == "app-prod":
                self.assertEqual("python3", argv[0])
                probe_input = json.loads(argv[-1])
                self.assertEqual(
                    kind != "websocket.app_destroy", probe_input["present"]
                )
                return "managed-runtime-handshake: PASS"
            self.assertEqual("operator", node)
            if argv[1] == "operation:show":
                return json.dumps(operation)
            if argv[1] == "websocket:app:list":
                return json.dumps(
                    {"apps": [] if kind.endswith("destroy") else [{"name": "subject"}]}
                )
            return json.dumps(
                {
                    "key": "other-key" if argv[2] == "other" else "subject-key",
                    "app_id": "subject-public-id",
                }
            )

        with (
            tempfile.NamedTemporaryFile(mode="w+", suffix=".json") as selected,
            patch.dict(
                "os.environ",
                {
                    "PROOF_OPERATION_ID": "41",
                    "PROOF_RUN": "disposable",
                    "PROOF_OUTPUTS": selected.name,
                },
            ),
            patch.object(parity, "guest", side_effect=execute),
            patch(
                "sys.argv",
                [
                    "parity.py",
                    kind,
                    "subject",
                    "other",
                    "other-key",
                    "subject-key",
                    "12",
                    "10.0.0.12",
                ],
            ),
            patch("builtins.print"),
        ):
            json.dump(
                native_outputs
                if native_outputs is not None
                else {
                    "accepted": True,
                    "stdout": json.dumps(
                        {"outcome": "succeeded", "data": {"backend": "applied"}}
                    ),
                },
                selected,
            )
            selected.flush()
            parity.main()

    def test_public_observations_reach_managed_app_prod(self):
        for kind in (
            "websocket.sync",
            "websocket.app_create",
            "websocket.app_destroy",
        ):
            with self.subTest(kind=kind):
                self.run_parity(kind, self.observations(kind))

    def test_rotation_requires_the_pre_request_observer_result(self):
        with (
            patch.object(
                parity.subprocess,
                "run",
                side_effect=subprocess.CalledProcessError(1, "observer"),
            ),
            self.assertRaises(subprocess.CalledProcessError),
        ):
            self.run_parity(
                "websocket.app_rotate", self.observations("websocket.app_rotate")
            )

    def test_wrapped_outputs_cannot_replace_the_selected_native_object(self):
        with self.assertRaisesRegex(AssertionError, "Selected native outputs"):
            self.run_parity(
                "websocket.sync",
                self.observations("websocket.sync"),
                {"data": {"accepted": True, "stdout": "{}"}},
            )

    def test_foreign_runtime_refused_before_handshake(self):
        operation = self.observations("websocket.sync")
        operation["input"]["native_registry"]["runtimes"][0]["node_id"] = 999
        with self.assertRaises(AssertionError):
            self.run_parity("websocket.sync", operation)

    def test_credential_values_cannot_replace_names_only_descriptors(self):
        operation = self.observations("websocket.sync")
        operation["input"]["execution_secrets"] = {"value": "private-canary"}
        with self.assertRaises(AssertionError):
            self.run_parity("websocket.sync", operation)


if __name__ == "__main__":
    unittest.main()
