"""The parent proof must refuse an unrelated failure before workload checks."""

import json
import os
import runpy
import subprocess
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

HERE = Path(__file__).parent


class RefusalEvidence(unittest.TestCase):
    def test_an_unrelated_failed_step_cannot_satisfy_the_account_refusal(self):
        with tempfile.TemporaryDirectory() as root:
            outputs = Path(root) / "outputs.json"
            outputs.write_text(
                json.dumps({"stdout": '{"error_code":"ssh.disconnected"}'})
            )
            with (
                patch.dict(os.environ, {"PROOF_OUTPUTS": str(outputs)}),
                patch("subprocess.run") as execute,
            ):
                with self.assertRaises(AssertionError):
                    runpy.run_path(str(HERE / "parity.py"), run_name="__main__")
                execute.assert_not_called()

    def test_exact_refusal_checks_workload_markers_through_topology_exec(self):
        checks = {
            "noncanonical-nss-home-present": True,
            "certificate-scope-preserved": True,
            "unrelated-scope-preserved": True,
        }
        with tempfile.TemporaryDirectory() as root:
            outputs = Path(root) / "outputs.json"
            outputs.write_text(
                json.dumps({"stdout": '{"error_code":"certificate.account_invalid"}'})
            )
            response = subprocess.CompletedProcess(
                [], 0, json.dumps({"exit_code": 0, "stdout": json.dumps(checks)}), ""
            )
            with (
                patch.dict(
                    os.environ,
                    {
                        "PROOF_OUTPUTS": str(outputs),
                        "PROOF_EXEC": "bin/e2e-topology exec fixture",
                    },
                ),
                patch("subprocess.run", return_value=response) as execute,
            ):
                runpy.run_path(str(HERE / "parity.py"), run_name="__main__")
                self.assertEqual(
                    execute.call_args.args[0][:4],
                    ["bin/e2e-topology", "exec", "fixture", "app-dev"],
                )


if __name__ == "__main__":
    unittest.main()
