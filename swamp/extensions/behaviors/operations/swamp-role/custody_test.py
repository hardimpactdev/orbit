"""TLS custody checks use metadata and never read private values."""

import importlib.util
import os
import sys
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

SCRIPTS = Path(__file__).parent / "scripts"
SPEC = importlib.util.spec_from_file_location(
    "swamp_role_common", SCRIPTS / "common.py"
)
common = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(common)


class Custody(unittest.TestCase):
    def test_loader_prerequisites_refuse_before_configuration_or_service_effects(self):
        spec = importlib.util.spec_from_file_location(
            "swamp_role_account", SCRIPTS / "account.py"
        )
        account = importlib.util.module_from_spec(spec)
        with patch.dict(sys.modules, {"common": common}):
            spec.loader.exec_module(account)
        data = {
            "intent": {"source_sha": "a" * 40},
            "install": {"binary": "/usr/local/bin/swamp"},
            "mounts": [],
        }
        owner = SimpleNamespace(pw_uid=1001, pw_gid=1001, pw_dir="/home/orbit")
        for missing in ("yaml", "helper"):
            with (
                self.subTest(missing=missing),
                patch.object(account.pwd, "getpwnam", return_value=owner),
                patch.object(
                    account.grp, "getgrnam", return_value=SimpleNamespace(gr_gid=1001)
                ),
                patch.object(account.platform, "system", return_value="Linux"),
                patch.object(account.platform, "machine", return_value="x86_64"),
                patch.object(account, "source_sha", return_value="a" * 40),
                patch.object(Path, "is_symlink", return_value=False),
                patch.object(
                    Path,
                    "is_file",
                    lambda path, missing=missing: (
                        str(path) != "/usr/local/libexec/behavior_versions.py"
                        or missing != "helper"
                    ),
                ),
                patch.object(
                    account.importlib.util,
                    "find_spec",
                    return_value=None if missing == "yaml" else object(),
                ),
                patch.object(account, "control") as control,
                patch.object(account, "run") as run,
                self.assertRaisesRegex(ValueError, "release_prerequisites_missing"),
            ):
                account.preflight(data)
            control.assert_not_called()
            run.assert_not_called()

    def test_tls_pair_requires_exact_owner_and_mode_without_reading_values(self):
        with tempfile.TemporaryDirectory() as directory:
            pair = Path(directory) / "serve-tls"
            pair.mkdir()
            for name in ("cert.pem", "key.pem"):
                path = pair / name
                path.touch(mode=0o600)
            owner = SimpleNamespace(pw_uid=os.getuid(), pw_gid=os.getgid())
            with (
                patch.object(common.pwd, "getpwnam", return_value=owner),
                patch.object(
                    Path, "read_text", side_effect=AssertionError("private read")
                ),
            ):
                common.tls_custody({"config": directory})
                (pair / "key.pem").chmod(0o644)
                with self.assertRaisesRegex(ValueError, "private_file_custody"):
                    common.tls_custody({"config": directory})

    def test_symlink_and_wrong_owner_are_refused(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "file"
            path.touch(mode=0o600)
            alias = Path(directory) / "alias"
            alias.symlink_to(path)
            owner = SimpleNamespace(pw_uid=os.getuid() + 1, pw_gid=os.getgid())
            with patch.object(common.pwd, "getpwnam", return_value=owner):
                for file in (path, alias):
                    with self.assertRaisesRegex(ValueError, "private_file_custody"):
                        common.private_file(file, "orbit")

    def test_subprocess_keeps_diagnostics_on_stderr(self):
        with self.assertRaises(common.subprocess.CalledProcessError) as failure:
            common.run([sys.executable, "-c", "raise SystemExit(8)"])
        self.assertEqual(8, failure.exception.returncode)
        self.assertIsNone(failure.exception.stderr)


if __name__ == "__main__":
    unittest.main()
