"""Reject false terminal proofs and stale proof-fixture contracts."""

import contextlib
import copy
import hashlib
import importlib.util
import io
import json
import pathlib
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parent
SPEC = importlib.util.spec_from_file_location("role_parity", ROOT / "parity.py")
PARITY = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(PARITY)


class RoleParity(unittest.TestCase):
    def setUp(self):
        self.intent = {
            "node_id": 7,
            "assignment_id": 9,
            "role": "database",
            "created": False,
            "footprint": {"artifacts": [{"name": "agent", "required": True}]},
        }
        self.result = {
            "node_id": 7,
            "assignment_id": 9,
            "role": "database",
            "created": False,
            "role_proven": True,
            "runtime_proven": True,
            "certificates_proven": True,
            "publication_proven": True,
            "footprint": {
                "footprint_proven": True,
                "artifacts": [{"name": "agent", "status": "applied"}],
            },
            "cli": {"outcome": "cli_present"},
            "follow_up": None,
        }
        self.operation = {
            "kind": "node.role_converge",
            "status": "succeeded",
            "input": {"native_role": self.intent},
            "result": self.result,
        }

    def test_valid_receipt_preserves_real_assignment(self):
        self.assertEqual(
            PARITY.public_receipt(self.operation, "database", self.result), self.intent
        )

    def test_nonterminal_and_failed_operations_are_not_proofs(self):
        for status in ["running", "failed", "cancelled", "interrupted"]:
            with (
                self.subTest(status=status),
                self.assertRaisesRegex(ValueError, "operation-not-succeeded"),
            ):
                PARITY.public_receipt(
                    {**self.operation, "status": status}, "database", self.result
                )

    def test_wrong_assignment_and_fabricated_creation_are_rejected(self):
        for key, value in [("assignment_id", 10), ("created", True), ("node_id", 8)]:
            with (
                self.subTest(key=key),
                self.assertRaisesRegex(ValueError, "projection-identity-"),
            ):
                operation = {**self.operation, "result": {**self.result, key: value}}
                PARITY.public_receipt(operation, "database", operation["result"])

    def test_native_output_must_match_gateway_terminal_identity(self):
        with self.assertRaisesRegex(ValueError, "native-projection-assignment_id"):
            PARITY.public_receipt(
                self.operation, "database", {**self.result, "assignment_id": 11}
            )

    def test_every_phase_fact_and_required_artifact_must_be_proven(self):
        for key in [
            "role_proven",
            "runtime_proven",
            "certificates_proven",
            "publication_proven",
        ]:
            with (
                self.subTest(key=key),
                self.assertRaisesRegex(ValueError, "missing-proof-"),
            ):
                result = {**self.result, key: False}
                PARITY.public_receipt(
                    {**self.operation, "result": result}, "database", result
                )
        result = {
            **self.result,
            "footprint": {"footprint_proven": True, "artifacts": []},
        }
        with self.assertRaisesRegex(ValueError, "required-footprint-not-applied"):
            PARITY.public_receipt(
                {**self.operation, "result": result}, "database", result
            )

    def test_cli_or_footprint_followup_is_not_a_clean_proof(self):
        for patch_result in [
            {"follow_up": "role-cli:cli.foreign_binary"},
            {"cli": {"error_code": "cli.foreign_binary"}},
            {"footprint": {"footprint_proven": False}},
        ]:
            result = {**self.result, **patch_result}
            with self.subTest(patch=patch_result), self.assertRaises(ValueError):
                PARITY.public_receipt(
                    {**self.operation, "result": result}, "database", result
                )

    def test_topology_transport_passes_exact_argv_without_a_shell(self):
        command = ["orbit", "node:role:list", "7", "--json"]
        with patch.object(
            PARITY.subprocess,
            "run",
            return_value=subprocess.CompletedProcess([], 0, '{"assignments":[]}', ""),
        ) as run:
            self.assertEqual(
                PARITY.topology_command(
                    ["bin/e2e-topology", "exec", "TASK-7"], "operator", command
                ),
                {"assignments": []},
            )
        args = run.call_args.args[0]
        self.assertEqual(args[:4], ["bin/e2e-topology", "exec", "TASK-7", "operator"])
        self.assertEqual(json.loads(args[-1].removeprefix("--argv=")), command)
        self.assertNotIn("shell", run.call_args.kwargs)

    def test_variants_use_real_cli_and_topology_gateway_signing(self):
        for role in ["database", "ingress", "app-prod", "websocket"]:
            fixture = json.loads((ROOT / (role + ".json")).read_text())
            with self.subTest(role=role):
                self.assertEqual(fixture["kind"], "node.role_converge")
                self.assertEqual(fixture["topology"], ["app-prod"])
                self.assertEqual(
                    fixture["request"]["argv"],
                    [
                        "orbit",
                        "node:role:add",
                        "${topology.app-prod.node_id}",
                        role,
                        "--converge",
                        "--json",
                    ],
                )
                self.assertEqual(fixture["request"]["operation"], "$.id")
                self.assertEqual(
                    fixture["envelope"]["native_role"]["assignment_id"],
                    "${match.positive_int}",
                )
                self.assertEqual(
                    fixture["prepare"][0]["capture"], {"target_node_id": "$.id"}
                )
                self.assertEqual(
                    fixture["before_request"][0]["argv"],
                    ["python3", "-I", "prepare.py", role, "${capture.target_node_id}"],
                )
                encoded = json.dumps(fixture)
                for retired in [
                    "proof.signer",
                    "proof.targets",
                    "proof.target",
                    "proof.control",
                    "target_setup",
                    '"secrets"',
                ]:
                    self.assertNotIn(retired, encoded)
        websocket = json.loads((ROOT / "websocket.json").read_text())
        self.assertEqual(
            websocket["envelope"]["native_role"]["certificates"]["leaves"][0]["domain"],
            "reverb.orbit",
        )
        self.assertNotIn("signer", json.dumps(websocket))

    def test_native_and_projected_footprints_must_agree(self):
        native = copy.deepcopy(self.result)
        native["footprint"]["artifacts"] = []
        with self.assertRaisesRegex(ValueError, "native-projection-footprint"):
            PARITY.public_receipt(self.operation, "database", native)

    def test_websocket_target_uses_after_publication(self):
        intent = {
            "role": "websocket",
            "node_id": 7,
            "publications": [
                {
                    "node_id": 7,
                    "before": {"caddy": {"content": "prior"}},
                    "after": {"caddy": {"content": "new-reverb-site"}},
                }
            ],
        }
        self.assertEqual(PARITY.target_publication(intent), "new-reverb-site")
        intent["publications"] = []
        with self.assertRaisesRegex(ValueError, "missing-websocket-target-publication"):
            PARITY.target_publication(intent)

    def test_websocket_stale_caddy_or_wrong_admitted_root_fails_actual_host_check(self):
        for refusal in ["caddy-catalog-differs", "admitted-root-differs"]:
            with (
                self.subTest(refusal=refusal),
                tempfile.TemporaryDirectory() as directory,
            ):
                root = Path(directory)

                def publish(path, data, root=root):
                    target = root / path.lstrip("/")
                    target.parent.mkdir(parents=True, exist_ok=True)
                    target.write_text(data)
                    return target

                publish("/usr/local/bin/orbit-agent", "agent")
                publish("/etc/orbit/agent/config.toml", "public")
                publish("/etc/orbit/agent/ca.pem", "public-root")
                publish(
                    "/etc/caddy/Caddyfile",
                    "stale"
                    if refusal == "caddy-catalog-differs"
                    else "new-reverb-site",
                )
                environment = publish("/srv/releases/candidate/.env", "not read")
                environment.chmod(0o600)
                (root / "srv/reverb").symlink_to(root / "srv/releases/candidate")
                publish("/etc/caddy/role/root-ca.pem", "different-root")
                descriptor = {
                    "intent": {
                        "role": "websocket",
                        "agent_sha256": hashlib.sha256(b"agent").hexdigest(),
                        "runtime": {
                            "install_path": "/srv/reverb",
                            "ref": "fixture-ref",
                            "port": 8790,
                        },
                    },
                    "caddy_content": "new-reverb-site",
                    "certificate": {
                        "root": "/etc/caddy/role/root-ca.pem",
                        "leaf": "/etc/caddy/role/websocket.pem",
                        "root_hash": hashlib.sha256(b"admitted-root").hexdigest(),
                    },
                }

                def command(argv, **kwargs):
                    return subprocess.CompletedProcess(
                        argv, 0, "fixture-ref" if "rev-parse" in argv else "", ""
                    )

                with (
                    patch.object(sys, "argv", ["host-check", json.dumps(descriptor)]),
                    patch.object(
                        pathlib,
                        "Path",
                        lambda value, root=root: root / str(value).lstrip("/"),
                    ),
                    patch.object(subprocess, "run", command),
                    patch(
                        "socket.create_connection",
                        return_value=contextlib.nullcontext(),
                    ),
                    contextlib.redirect_stdout(io.StringIO()),
                    self.assertRaisesRegex(ValueError, refusal),
                ):
                    exec(compile(PARITY.HOST_CHECK, "<proof-host-check>", "exec"), {})  # noqa: S102 - execute the shipped read-only proof in a disposable filesystem.


if __name__ == "__main__":
    unittest.main()
