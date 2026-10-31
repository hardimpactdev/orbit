"""Schedule validation preserves account identity and command integrity."""

import hashlib
import importlib.util
import types
import unittest
from pathlib import Path
from unittest.mock import patch


def load(name):
    spec = importlib.util.spec_from_file_location(
        name, Path(__file__).parent / "scripts" / (name + ".py")
    )
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


account = load("account")
command_hash = load("command_hash")


class ScheduleValidation(unittest.TestCase):
    def test_account_requires_non_root_exact_recorded_home_shell_and_existing_group(
        self,
    ):
        record = types.SimpleNamespace(
            pw_name="orbit", pw_uid=1000, pw_dir="/home/orbit", pw_shell="/bin/bash"
        )
        with (
            patch.object(account.pwd, "getpwnam", return_value=record),
            patch.object(
                account.grp,
                "getgrnam",
                return_value=types.SimpleNamespace(gr_name="orbit"),
            ),
        ):
            self.assertTrue(
                account.validate("orbit", "/home/orbit", "/bin/bash", "orbit")
            )
            for field, value in [
                ("pw_uid", 0),
                ("pw_name", "foreign"),
                ("pw_dir", "/foreign"),
                ("pw_shell", "/bin/sh"),
            ]:
                with self.subTest(field=field), patch.object(record, field, value):
                    self.assertFalse(
                        account.validate("orbit", "/home/orbit", "/bin/bash", "orbit")
                    )

    def test_command_input_requires_the_original_single_line_digest(self):
        command = b"printf private-command"
        digest = hashlib.sha256(command).hexdigest()
        self.assertTrue(command_hash.validate(command, digest))
        for value, expected in [
            (command + b"\n", digest),
            (command, "a" * 64),
            (b"\xff", digest),
            (b"a\0b", digest),
            (b"a\rb", digest),
            (b"", digest),
        ]:
            with self.subTest(value=value):
                self.assertFalse(command_hash.validate(value, expected))


if __name__ == "__main__":
    unittest.main()
