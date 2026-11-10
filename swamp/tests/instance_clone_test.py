"""Check Node-side clone command diagnostics without Incus or private output."""

import contextlib
import io
import json
import runpy
import subprocess
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

SCRIPT = Path(__file__).parents[1] / "scripts/instance_clone.py"


class CloneTest(unittest.TestCase):
    def invoke(self, stderr, phase="sessions", fail="incus"):
        with tempfile.TemporaryDirectory() as folder:
            container = {
                "name": "instance-proof",
                "project": "orbit-instances",
                "mirror": "/private/mirror",
                "branch": "proof",
                "php": None,
            }

            def command(argv, **_kwargs):
                if argv[0] != fail:
                    return subprocess.CompletedProcess(argv, 0, "abc123\n", "")
                return subprocess.CompletedProcess(argv, 7, "PRIVATE-STDOUT", stderr)

            output, error = io.StringIO(), io.StringIO()
            with (
                patch(
                    "sys.argv",
                    [
                        str(SCRIPT),
                        json.dumps(container),
                        "guest",
                        "agent",
                        folder,
                        phase,
                    ],
                ),
                patch("sys.stdin", io.StringIO("PRIVATE-INPUT")),
                patch("subprocess.run", command),
                contextlib.redirect_stdout(output),
                contextlib.redirect_stderr(error),
                self.assertRaises(SystemExit) as exit_status,
            ):
                runpy.run_path(str(SCRIPT), run_name="__main__")
            self.assertEqual(exit_status.exception.code, 1)
            self.assertEqual(output.getvalue(), "")
            self.assertNotIn("PRIVATE", error.getvalue())
            return error.getvalue()

    def test_preserves_only_the_guest_command_and_exit_code(self):
        self.assertEqual(
            self.invoke("instance.container_sessions_failed: command php exited 17\n"),
            "instance.container_sessions_failed: command php exited 17\n",
        )

    def test_rejects_private_or_mismatched_guest_diagnostics(self):
        for stderr in [
            "PRIVATE-STDERR",
            "instance.container_sessions_failed: command php exited 17\nPRIVATE-STDERR",
            "instance.container_agent_failed: command php exited 17",
            "instance.container_sessions_failed: command php PRIVATE-ARGV exited 17",
        ]:
            with self.subTest(stderr=stderr):
                self.assertEqual(
                    self.invoke(stderr),
                    "instance.container_sessions_failed: command incus exited 7\n",
                )

    def test_node_command_failure_names_executable_and_exit_without_output(self):
        self.assertEqual(
            self.invoke("PRIVATE-STDERR", phase="t3", fail="git"),
            "instance.container_t3_failed: command git exited 7\n",
        )


if __name__ == "__main__":
    unittest.main()
