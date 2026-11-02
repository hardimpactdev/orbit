"""The Schedule check refuses a wrong account, an unreachable directory and a changed command."""

import hashlib
import importlib.util
import types
import unittest
from pathlib import Path
from unittest.mock import patch

spec = importlib.util.spec_from_file_location("check", Path(__file__).parent / "scripts" / "check.py")
assert spec and spec.loader
check = importlib.util.module_from_spec(spec)
spec.loader.exec_module(check)

COMMAND = b"php artisan report:send"
ARGS = ["orbit", "/apps/proof", hashlib.sha256(COMMAND).hexdigest()]
ACCOUNT = types.SimpleNamespace(pw_name="orbit", pw_uid=4321, pw_gid=4321, pw_dir="/home/orbit", pw_shell="/bin/bash")


class ScheduleCheck(unittest.TestCase):
    def run_check(self, args=ARGS, stdin=COMMAND, account=ACCOUNT, probe=0):
        with (
            patch.object(check.pwd, "getpwnam", return_value=account),
            patch.object(check.grp, "getgrgid", return_value=types.SimpleNamespace(gr_name="orbit")),
            patch.object(check.subprocess, "run", return_value=types.SimpleNamespace(returncode=probe)),
        ):
            return check.check(args, stdin)

    def test_passes_for_the_recorded_account_directory_and_command(self):
        self.assertIsNone(self.run_check())

    def test_refuses_root(self):
        self.assertIn("cannot run", self.run_check(account=types.SimpleNamespace(**{**vars(ACCOUNT), "pw_uid": 0})))

    def test_an_empty_working_directory_is_the_home(self):
        self.assertIsNone(self.run_check(args=["orbit", "", ARGS[2]]))

    def test_refuses_a_missing_account(self):
        with patch.object(check.pwd, "getpwnam", side_effect=KeyError("orbit")):
            self.assertIn("does not exist", check.check(ARGS, COMMAND))

    def test_refuses_a_directory_the_user_cannot_enter(self):
        self.assertIn("cannot enter", self.run_check(probe=1))

    def test_refuses_a_changed_or_multiline_command(self):
        self.assertIn("hash", self.run_check(stdin=b"php artisan other"))
        self.assertIn("hash", self.run_check(stdin=COMMAND + b"\n", args=[*ARGS[:2], hashlib.sha256(COMMAND + b"\n").hexdigest()]))


if __name__ == "__main__":
    unittest.main()
