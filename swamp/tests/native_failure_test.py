"""Shared F1 wrapper: preserve command semantics, retain only safe stderr tail."""

import os
import subprocess
import sys
import unittest
from pathlib import Path

WRAPPER = (
    Path(__file__).resolve().parents[1]
    / "extensions/behaviors/operations/_lib/native_failure.py"
)


class NativeFailureWrapper(unittest.TestCase):
    def run_script(self, script, *, environment=None, stdin=None):
        command = [sys.executable, "-B", str(WRAPPER)]
        if stdin is not None:
            command.append("--stdin")
        command.extend(["--", sys.executable, "-c", script])
        return subprocess.run(
            command,
            input=stdin,
            env={**os.environ, **(environment or {})},
            capture_output=True,
            check=False,
        )

    def test_failed_command_preserves_exit_code_and_only_safe_stderr(self):
        result = self.run_script(
            'import sys; print(\'{"ready":false}\'); sys.stderr.write("fatal: unavailable"); sys.exit(7)'
        )
        self.assertEqual(result.returncode, 7)
        self.assertEqual(result.stdout, b"")
        self.assertEqual(result.stderr, b"fatal: unavailable")

    def test_redacts_native_vault_environment_before_utf8_tail(self):
        script = 'import json,os,sys; v=json.loads(os.environ["ORBIT_PRIVATE"]); sys.stderr.write("earlier"*2000+"界"*1500+"\\n"+v["value"]+"\\npassword=another-private-value")'
        result = self.run_script(
            script, environment={"ORBIT_PRIVATE": '{"value":"vault-private-sentinel"}'}
        )
        self.assertEqual(result.returncode, 0)
        self.assertLessEqual(len(result.stderr), 4096)
        self.assertNotIn(b"vault-private-sentinel", result.stderr)
        self.assertNotIn(b"another-private-value", result.stderr)
        self.assertTrue(
            result.stderr.decode("utf-8").endswith("\n[REDACTED]\npassword=[REDACTED]")
        )

    def test_forwards_private_stdin_without_moving_it_into_argv_or_environment(self):
        script = 'import json,os,sys; raw=sys.stdin.read(); sys.stderr.write(json.loads(raw)["value"]); print("private-sentinel" in " ".join(sys.argv)+str(dict(os.environ)))'
        result = self.run_script(script, stdin=b'{"value":"private-sentinel"}')
        self.assertEqual(result.returncode, 0)
        self.assertEqual(result.stdout.strip(), b"False")
        self.assertEqual(result.stderr, b"[REDACTED]")

    def test_preserves_explicit_shell_interpreter_and_recorded_empty_stderr(self):
        result = subprocess.run(
            [
                sys.executable,
                "-B",
                str(WRAPPER),
                "--",
                "/bin/sh",
                "-c",
                "printf public",
            ],
            capture_output=True,
            check=False,
        )
        self.assertEqual(result.returncode, 0)
        self.assertEqual(result.stdout, b"public")
        self.assertEqual(result.stderr, b"")

    def test_redacts_a_secret_whose_prefix_would_be_cut_off_by_the_tail(self):
        script = 'import sys; sys.stderr.write("password="+"q"*5000+"\\npublic")'
        result = self.run_script(script)
        self.assertEqual(result.stderr, b"password=[REDACTED]\npublic")

    def test_replaces_whole_secrets_before_later_replacements_shrink_the_tail(self):
        secret = "boundary-private-" + "q" * 48
        script = 'import os,sys; s=os.environ["BOUNDARY_PRIVATE"]; sys.stderr.write("public"*900+s+"public"*665+s)'
        result = self.run_script(script, environment={"BOUNDARY_PRIVATE": secret})
        self.assertLessEqual(len(result.stderr), 4096)
        self.assertNotIn(b"boundary-private-", result.stderr)
        self.assertNotIn(b"qqqqqqqq", result.stderr)
        self.assertTrue(result.stderr.endswith(b"[REDACTED]"))

    def test_redacts_again_after_control_sequences_are_removed(self):
        script = (
            'import sys; sys.stderr.write("Bearer "+"ghp_"+"x"*10+"\\x1b[0m"+"x"*10)'
        )
        result = self.run_script(script)
        self.assertNotIn(b"ghp_", result.stderr)
        self.assertEqual(result.stderr, b"Bearer [REDACTED]")

    def test_reports_missing_command_with_nonzero_exit(self):
        result = subprocess.run(
            [sys.executable, "-B", str(WRAPPER), "--", "/nonexistent/f1-command"],
            capture_output=True,
            check=False,
        )
        self.assertEqual(result.returncode, 127)
        self.assertIn(b"f1-command", result.stderr)


if __name__ == "__main__":
    unittest.main()
