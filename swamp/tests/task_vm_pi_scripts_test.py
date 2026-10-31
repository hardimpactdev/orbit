"""Run the shipped Pi action scripts on isolated, real filesystem fixtures."""
import hashlib
import importlib.util
import json
import os
import pathlib
import shutil
import subprocess
import tempfile
import unittest
from unittest.mock import patch

ROOT = pathlib.Path(__file__).resolve().parents[1]
SCRIPTS = ROOT / "extensions/behaviors/operations/task-vm-pi/scripts"
CONTENT = b"pinned Pi executable fixture\n"
SHA = hashlib.sha256(CONTENT).hexdigest()


def fixture_scripts(destination, home, cache):
    """Replace code-owned paths/account lookup only in a disposable bundle."""
    shutil.copytree(SCRIPTS, destination)
    path = destination / "pi_common.py"
    source = path.read_text().replace('pathlib.Path("/home/orbit")', f"pathlib.Path({str(home)!r})")
    source = source.replace('pathlib.Path("/var/cache/orbit/pi")', f"pathlib.Path({str(cache)!r})")
    source = source.replace('pwd.getpwnam("orbit")',
                            'pwd.struct_passwd(("orbit", "x", os.getuid(), os.getgid(), "", str(HOME), "/bin/sh"))')
    path.write_text(source)


class PiScriptsTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="orbit-pi-actions-", dir=pathlib.Path.home())
        self.addCleanup(self.temp.cleanup)
        self.root = pathlib.Path(self.temp.name)
        self.home = self.root / "home"
        self.home.mkdir(mode=0o755)
        self.cache = self.root / "cache"
        self.cache.mkdir(mode=0o700)
        self.source = self.cache / (SHA + ".bin")
        self.source.write_bytes(CONTENT)
        self.source.chmod(0o600)
        self.scripts = self.root / "scripts"
        fixture_scripts(self.scripts, self.home, self.cache)
        self.target = self.home / ".local/bin/pi-server"

    def action(self, name, success=True, vm="42", sha=SHA):
        result = subprocess.run(["/usr/bin/python3", str(self.scripts / ("pi_" + name + ".py")), vm, sha],
                                text=True, capture_output=True, timeout=5, check=False)
        self.assertEqual(result.returncode == 0, success, result.stdout + result.stderr)
        if success:
            self.assertEqual(result.stderr, "")
            return json.loads(result.stdout)
        self.assertEqual(result.stdout, "")
        self.assertEqual(result.stderr.strip(), "task_vm.pi_artifact_failed")
        return result

    def test_installs_then_observes_and_verifies_exact_digest_owner_and_mode(self):
        self.assertEqual(self.action("inspect"), {"task_vm_id": 42, "sha256": SHA, "current": False})
        self.assertEqual(self.action("install"), {"task_vm_id": 42, "sha256": SHA, "installed": True, "changed": True})
        self.assertEqual(self.target.read_bytes(), CONTENT)
        self.assertEqual(self.target.stat().st_uid, os.getuid())
        self.assertEqual(self.target.stat().st_gid, os.getgid())
        self.assertEqual(self.target.stat().st_mode & 0o777, 0o755)
        self.assertTrue(self.action("inspect")["current"])
        self.assertEqual(self.action("verify"), {"task_vm_id": 42, "sha256": SHA, "installed": True})
        self.assertEqual(list(self.target.parent.glob(".orbit-pi-*")), [])

    def test_current_install_is_idempotent_even_after_cache_retention(self):
        self.action("install")
        before = self.target.stat()
        self.source.unlink()
        self.assertFalse(self.action("install")["changed"])
        self.assertEqual(self.target.stat().st_ino, before.st_ino)
        self.assertEqual(self.target.stat().st_mtime_ns, before.st_mtime_ns)

    def test_corrupt_cache_preserves_previous_executable(self):
        self.action("install")
        self.target.write_bytes(b"previous valid executable")
        self.source.write_bytes(b"wrong artifact")
        self.action("install", success=False)
        self.assertEqual(self.target.read_bytes(), b"previous valid executable")
        self.assertEqual(list(self.target.parent.glob(".orbit-pi-*")), [])

    def test_symlink_cache_and_world_writable_cache_are_refused(self):
        self.source.unlink()
        foreign = self.root / "foreign"
        foreign.write_bytes(CONTENT)
        self.source.symlink_to(foreign)
        self.action("install", success=False)
        self.source.unlink()
        self.source.write_bytes(CONTENT)
        self.source.chmod(0o666)
        self.action("install", success=False)
        self.assertFalse(self.target.exists())

    def test_target_and_parent_symlinks_are_refused_without_following_them(self):
        self.target.parent.mkdir(parents=True)
        foreign = self.root / "foreign"
        foreign.write_bytes(b"untouched")
        self.target.symlink_to(foreign)
        self.action("inspect", success=False)
        self.action("install", success=False)
        self.assertEqual(foreign.read_bytes(), b"untouched")
        self.target.unlink()
        self.target.parent.rmdir()
        self.target.parent.symlink_to(self.root, target_is_directory=True)
        self.action("install", success=False)
        self.assertFalse((self.root / "pi-server").exists())

    def test_unsafe_modes_and_missing_or_corrupt_final_files_fail_verification(self):
        self.action("verify", success=False)
        self.action("install")
        self.target.write_bytes(b"corrupt")
        self.action("verify", success=False)
        self.target.chmod(0o777)
        self.action("install", success=False)

    def test_empty_owned_target_can_be_repaired_but_empty_cache_cannot_be_published(self):
        self.target.parent.mkdir(parents=True)
        self.target.touch(mode=0o755)
        self.assertFalse(self.action("inspect")["current"])
        self.assertTrue(self.action("install")["changed"])
        self.target.write_bytes(b"previous executable")
        self.source.write_bytes(b"")
        self.action("install", success=False)
        self.assertEqual(self.target.read_bytes(), b"previous executable")

    def test_invalid_public_arguments_fail_with_only_the_fixed_code(self):
        for vm, sha in [("0", SHA), ("42;echo bad", SHA), ("42", "secret-bearing-invalid-digest")]:
            with self.subTest(vm=vm):
                self.action("install", success=False, vm=vm, sha=sha)

    def test_failed_atomic_publication_keeps_old_target_and_removes_candidate(self):
        spec = importlib.util.spec_from_file_location("fixture_pi_common", self.scripts / "pi_common.py")
        common = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(common)
        self.action("install")
        self.target.write_bytes(b"previous executable")
        with (patch.object(common.os, "replace", side_effect=OSError("synthetic disk error")),
              self.assertRaises(OSError)):
            common.install(42, SHA)
        self.assertEqual(self.target.read_bytes(), b"previous executable")
        self.assertEqual(list(self.target.parent.glob(".orbit-pi-*")), [])

    def test_foreign_owner_is_refused_before_opening(self):
        spec = importlib.util.spec_from_file_location("fixture_pi_common", self.scripts / "pi_common.py")
        common = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(common)
        with self.assertRaises(ValueError):
            common.opened(self.source, os.getuid() + 1)


if __name__ == "__main__":
    unittest.main()
