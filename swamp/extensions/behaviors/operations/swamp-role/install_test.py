"""Install refusal and atomic replacement tests on disposable local files."""

import hashlib
import importlib.util
import stat
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

SPEC = importlib.util.spec_from_file_location(
    "swamp_role_install", Path(__file__).parent / "scripts/install.py"
)
install = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(install)


class Install(unittest.TestCase):
    def setUp(self):
        directory = tempfile.TemporaryDirectory()
        self.addCleanup(directory.cleanup)
        self.directory = Path(directory.name)
        self.binary = self.directory / "swamp"
        self.memory = self.directory / "meminfo"
        self.memory.write_text("MemAvailable: 4096 kB\n")
        self.artifact = b"official synthetic release"
        self.spec = {
            "binary": str(self.binary),
            "artifact": {
                "url": "https://release.test/swamp",
                "bytes": len(self.artifact),
                "sha256": hashlib.sha256(self.artifact).hexdigest(),
            },
            "minimum_memory_bytes": 4096 * 1024,
        }
        self.addCleanup(patch.stopall)
        patch.object(install, "BINARY", self.binary).start()
        patch.object(install, "MEMINFO", self.memory).start()
        patch.object(install.platform, "machine", return_value="x86_64").start()

    def test_low_memory_refuses_before_directory_or_download_effects(self):
        self.memory.write_text("MemAvailable: 4095 kB\n")
        with (
            patch.object(Path, "lstat") as metadata,
            patch.object(install.subprocess, "run") as run,
            self.assertRaisesRegex(ValueError, "swamp.node_worker_memory_low"),
        ):
            install.install(self.spec)
        metadata.assert_not_called()
        run.assert_not_called()
        self.assertEqual([self.memory], list(self.directory.iterdir()))

    def test_architecture_refuses_before_effects(self):
        with (
            patch.object(install.platform, "machine", return_value="aarch64"),
            patch.object(install.subprocess, "run") as run,
            self.assertRaisesRegex(ValueError, "swamp.role_install_invalid"),
        ):
            install.install(self.spec)
        run.assert_not_called()

    def test_writable_or_symlinked_parent_refuses(self):
        for mode in (stat.S_IFDIR | 0o777, stat.S_IFLNK | 0o755):
            with (
                self.subTest(mode=mode),
                patch.object(
                    Path, "lstat", return_value=SimpleNamespace(st_mode=mode, st_uid=0)
                ),
                self.assertRaisesRegex(
                    ValueError, "swamp.role_install_directory_invalid"
                ),
            ):
                install.preflight(self.spec)

    def download(self, argv, **kwargs):
        self.assertTrue(kwargs["check"])
        self.assertEqual("300", argv[argv.index("--max-time") + 1])
        Path(argv[argv.index("--output") + 1]).write_bytes(self.artifact)

    def test_digest_failure_keeps_existing_binary_and_cleans_stage(self):
        self.binary.write_bytes(b"prior binary")
        self.spec["artifact"]["sha256"] = "0" * 64
        with (
            patch.object(install, "preflight"),
            patch.object(install.subprocess, "run", side_effect=self.download),
            patch.object(install.os, "chown") as chown,
            self.assertRaisesRegex(ValueError, "swamp.role_install_digest_mismatch"),
        ):
            install.install(self.spec)
        self.assertEqual(b"prior binary", self.binary.read_bytes())
        self.assertEqual({self.binary, self.memory}, set(self.directory.iterdir()))
        chown.assert_not_called()

    def test_install_checks_bytes_and_sets_executable_before_atomic_replace(self):
        with (
            patch.object(install, "preflight"),
            patch.object(install.subprocess, "run", side_effect=self.download),
            patch.object(install.os, "chown") as chown,
        ):
            result = install.install(self.spec)
        self.assertTrue(result["changed"])
        self.assertEqual(self.artifact, self.binary.read_bytes())
        self.assertEqual(0o755, stat.S_IMODE(self.binary.stat().st_mode))
        self.assertEqual((0, 0), chown.call_args.args[1:])
        self.assertEqual({self.binary, self.memory}, set(self.directory.iterdir()))

    def test_matching_root_owned_binary_is_kept_without_download(self):
        self.binary.write_bytes(self.artifact)
        self.binary.chmod(0o755)
        original_stat = Path.stat

        def metadata(path, *args, **kwargs):
            actual = original_stat(path, *args, **kwargs)
            if path == self.binary:
                return SimpleNamespace(
                    st_uid=0, st_gid=0, st_mode=actual.st_mode, st_size=actual.st_size
                )
            return actual

        with (
            patch.object(install, "preflight"),
            patch.object(Path, "stat", metadata),
            patch.object(install.subprocess, "run") as run,
        ):
            result = install.install(self.spec)
        self.assertFalse(result["changed"])
        run.assert_not_called()


if __name__ == "__main__":
    unittest.main()
