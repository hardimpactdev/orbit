"""Exact-name native revocation: tolerate only pinned absent/revoked receipts."""

import importlib.util
import json
import types
import unittest
from pathlib import Path
from unittest.mock import patch


class TokenRevoke(unittest.TestCase):
    def setUp(self):
        path = Path(__file__).parent / "scripts/revoke_token.py"
        spec = importlib.util.spec_from_file_location("revoke_token", path)
        self.action = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(self.action)

    def test_revoke_and_retry_accept_only_exact_revoked_name(self):
        for kind, suffix in [("worker", ""), ("access", "-access")]:
            for already in [False, True]:
                with self.subTest(kind=kind, already=already):
                    name = "orbit-node-701" + suffix
                    output = {
                        "name": name,
                        "state": "revoked",
                        "alreadyRevoked": already,
                    }
                    response = types.SimpleNamespace(
                        returncode=0, stdout=json.dumps(output), stderr=""
                    )
                    with patch.object(
                        self.action.subprocess, "run", return_value=response
                    ) as command:
                        self.assertEqual(
                            self.action.revoke(kind, "701"),
                            {
                                "name": name,
                                "state": "revoked",
                                "already_revoked": already,
                            },
                        )
                    self.assertEqual(
                        command.call_args.args[0],
                        [
                            self.action.CLI,
                            kind,
                            "token",
                            "revoke",
                            name,
                            "--repo-dir",
                            self.action.REPOSITORY,
                            "--json",
                        ],
                    )

    def test_absent_name_is_success_only_for_pinned_exact_failure(self):
        for kind, label, suffix in [
            ("worker", "Enrollment", ""),
            ("access", "Server", "-access"),
        ]:
            name = "orbit-node-701" + suffix
            message = f"{label} token '{name}' not found. Use 'swamp {kind} token list' to see existing tokens."
            response = types.SimpleNamespace(
                returncode=1, stdout="", stderr=json.dumps({"error": message})
            )
            with patch.object(self.action.subprocess, "run", return_value=response):
                self.assertEqual(
                    self.action.revoke(kind, "701"), {"name": name, "state": "absent"}
                )

    def test_uncertain_error_or_wrong_success_receipt_fails_closed(self):
        responses = [
            (1, "", '{"error":"Permission denied"}'),
            (
                1,
                "",
                "{\"error\":\"Enrollment token 'orbit-node-702' not found. Use 'swamp worker token list' to see existing tokens.\"}",
            ),
            (1, "", '{"error":"not found"}'),
            (1, "", "not JSON"),
            (
                2,
                "",
                "{\"error\":\"Enrollment token 'orbit-node-701' not found. Use 'swamp worker token list' to see existing tokens.\"}",
            ),
            (
                0,
                '{"name":"orbit-node-702","state":"revoked","alreadyRevoked":false}',
                "",
            ),
            (
                0,
                '{"name":"orbit-node-701","state":"active","alreadyRevoked":false}',
                "",
            ),
            (0, '{"name":"orbit-node-701","state":"revoked"}', ""),
            (0, "", ""),
        ]
        for code, stdout, stderr in responses:
            with (
                self.subTest(code=code, stdout=stdout, stderr=stderr),
                patch.object(
                    self.action.subprocess,
                    "run",
                    return_value=types.SimpleNamespace(
                        returncode=code, stdout=stdout, stderr=stderr
                    ),
                ),
                self.assertRaisesRegex(RuntimeError, "node.token_revoke_unproven"),
            ):
                self.action.revoke("worker", "701")

    def test_refuses_control_node_and_invalid_identity_before_cli(self):
        for kind, node in [
            ("worker", "209"),
            ("worker", "0"),
            ("worker", "701;true"),
            ("other", "701"),
        ]:
            with (
                patch.object(self.action.subprocess, "run") as command,
                self.assertRaisesRegex(
                    ValueError, "node.token_revoke_identity_invalid"
                ),
            ):
                self.action.revoke(kind, node)
            command.assert_not_called()


if __name__ == "__main__":
    unittest.main()
