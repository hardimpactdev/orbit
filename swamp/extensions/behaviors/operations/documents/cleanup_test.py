"""Real scratch filesystem coverage for the exact fixed startup program."""

import ast
import fcntl
import json
import os
import subprocess
import tempfile
import unittest
from pathlib import Path

SOURCE = Path(__file__).with_name("scripts").joinpath("prepare_cleanup.py").read_text()


def sandbox_source(root):
    """Bind every fixed identity constant or refuse before executing anything."""
    tree = ast.parse(SOURCE)
    assignments = {
        node.targets[0].id: node
        for node in tree.body
        if isinstance(node, ast.Assign)
        and len(node.targets) == 1
        and isinstance(node.targets[0], ast.Name)
    }
    assert ast.literal_eval(assignments["ROOT"].value.args[0]) == "/run/orbit"
    assert ast.literal_eval(assignments["ROOT_UID"].value) == 0
    assert ast.literal_eval(assignments["ROOT_GID"].value) == 0
    assignments["ROOT"].value.args[0] = ast.Constant(str(root))
    assignments["ROOT_UID"].value = ast.Constant(os.getuid())
    assignments["ROOT_GID"].value = ast.Constant(os.getgid())
    calls = [
        node
        for node in ast.walk(tree)
        if isinstance(node, ast.Call)
        and isinstance(node.func, ast.Attribute)
        and isinstance(node.func.value, ast.Name)
        and node.func.value.id == "pwd"
        and node.func.attr == "getpwnam"
    ]
    assert len(calls) == 1 and ast.literal_eval(calls[0].args[0]) == "orbit"
    calls[0].func.attr = "getpwuid"
    calls[0].args = [ast.Constant(os.getuid())]
    return ast.unparse(ast.fix_missing_locations(tree))


class Cleanup(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix="documents-gate-")
        self.base = Path(self.tmp.name)
        self.root = self.base / "run"
        self.directory = self.root / "project-documents"
        self.lock = self.directory / "execution.lock"
        self.generation = self.directory / "generation.json"
        self.permit = self.directory / "permit.json"
        self.source = sandbox_source(self.root)

    def tearDown(self):
        self.tmp.cleanup()

    def invoke(self, source=None):
        result = subprocess.run(
            ["/usr/bin/python3", "-I", "-c", source or self.source],
            capture_output=True,
            text=True,
            timeout=5,
            check=False,
        )
        self.assertEqual(result.stderr, "")
        value = json.loads(result.stdout)
        self.assertEqual(result.returncode, 1 if "error_code" in value else 0)
        return value

    def start(self):
        result = self.invoke()
        self.assertRegex(result["generation"], "^[a-f0-9]{64}$")
        return result

    def arm(self):
        state = json.loads(self.generation.read_text())
        state.update(report_id="a" * 64, report_sha256="b" * 64)
        self.generation.write_text(json.dumps(state))
        self.permit.write_text(json.dumps(state))
        self.permit.chmod(0o600)

    def refused(self):
        self.assertEqual(
            self.invoke(), {"error_code": "project_documents.cleanup_state_unavailable"}
        )

    def test_creates_paused_bound_generation_and_preserves_lock_when_repeated(self):
        first = self.start()
        inode = self.lock.stat().st_ino
        self.arm()
        second = self.start()
        self.assertNotEqual(first, second)
        self.assertEqual(self.lock.stat().st_ino, inode)
        self.assertFalse(self.permit.exists())
        state = json.loads(self.generation.read_text())
        self.assertEqual(state["lock_inode"], inode)
        self.assertEqual(state["lock_device"], self.lock.stat().st_dev)
        self.assertIsNone(state["report_id"])
        self.assertIsNone(state["report_sha256"])
        for path, mode in [
            (self.directory, 0o700),
            (self.lock, 0o600),
            (self.generation, 0o600),
        ]:
            self.assertEqual(path.stat().st_mode & 0o7777, mode)
            self.assertEqual(path.stat().st_uid, os.getuid())

    def test_never_repairs_a_missing_existing_lock(self):
        self.start()
        self.lock.unlink()
        self.refused()
        self.assertFalse(self.lock.exists())

    def test_rejects_symlinked_paths_without_touching_targets(self):
        self.start()
        self.arm()
        for path in [
            self.permit,
            self.generation,
            self.lock,
            self.directory,
            self.root,
        ]:
            saved = self.base / "saved"
            path.rename(saved)
            path.symlink_to(saved)
            self.refused()
            self.assertTrue(path.is_symlink())
            path.unlink()
            saved.rename(path)

    def test_rejects_hardlinked_state_and_lock(self):
        self.start()
        self.arm()
        for path in [self.lock, self.generation, self.permit]:
            extra = self.base / "extra"
            extra.hardlink_to(path)
            self.refused()
            extra.unlink()

    def test_refuses_mode_drift_without_repair(self):
        self.start()
        self.arm()
        for path in [
            self.root,
            self.directory,
            self.lock,
            self.generation,
            self.permit,
        ]:
            previous = path.stat().st_mode & 0o7777
            path.chmod(0o777)
            self.refused()
            self.assertEqual(path.stat().st_mode & 0o7777, 0o777)
            path.chmod(previous)

    def test_refuses_a_generation_bound_to_a_different_lock(self):
        self.start()
        self.arm()
        state = json.loads(self.generation.read_text())
        state["lock_inode"] += 1
        self.generation.write_text(json.dumps(state))
        self.refused()
        self.assertTrue(self.permit.exists())

    def test_refuses_malformed_oversized_and_wrong_shape_generation(self):
        self.start()
        for value in ["{", "x" * 8193, json.dumps({"generation": "a" * 64})]:
            self.generation.write_text(value)
            self.refused()

    def test_failed_atomic_publication_cannot_leave_old_authorization(self):
        self.start()
        self.arm()
        self.assertEqual(self.source.count("os.replace(candidate, generation)"), 1)
        source = self.source.replace(
            "os.replace(candidate, generation)",
            "raise OSError('injected publication failure')",
        )
        self.assertIn("error_code", self.invoke(source))
        self.assertFalse(self.permit.exists())
        self.assertFalse(self.generation.exists())
        self.assertEqual(
            sorted(p.name for p in self.directory.iterdir()), ["execution.lock"]
        )

    def test_uses_same_lock_as_a_running_cleanup_without_waiting(self):
        self.start()
        self.arm()
        inode = self.lock.stat().st_ino
        previous = self.generation.read_bytes()
        with self.lock.open("r+b") as lock:
            fcntl.flock(lock, fcntl.LOCK_EX)
            self.refused()
            self.assertEqual(self.generation.read_bytes(), previous)
            self.assertTrue(self.permit.exists())
        self.start()
        self.assertFalse(self.permit.exists())
        self.assertEqual(self.lock.stat().st_ino, inode)


if __name__ == "__main__":
    unittest.main()
