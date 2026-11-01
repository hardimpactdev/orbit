"""Exercise source/archive actions against disposable checkouts; no host access."""
import importlib.util
import io
import subprocess
import tarfile
import tempfile
import unittest
from pathlib import Path

HERE = Path(__file__).parent / "scripts"


def module(name):
    spec = importlib.util.spec_from_file_location(name, HERE / (name + ".py"))
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


class SourceActions(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.base = Path(self.temporary.name)
        self.source = self.base / "source"
        self.source.mkdir()
        self.git(self.source, "init", "-q")
        self.git(self.source, "config", "user.name", "Fixture")
        self.git(self.source, "config", "user.email", "fixture@example.test")
        self.git(self.source, "remote", "add", "origin", "https://github.com/example/project.git")
        (self.source / "tracked").write_text("committed\n")
        self.git(self.source, "add", "tracked")
        self.git(self.source, "commit", "-qm", "fixture")

    def git(self, path, *args):
        return subprocess.run(["git", "-C", str(path), *args], capture_output=True, check=True).stdout.decode().strip()

    def move_archive(self, source):
        archive, destination = self.base / "source.tar", self.base / "destination"
        module("transfer_archive").capture({"path": str(source), "common": self.git(source, "rev-parse", "--path-format=absolute", "--git-common-dir"),
                                            "git_directory": self.git(source, "rev-parse", "--absolute-git-dir"), "archive": str(archive)})
        module("transfer_install").install({"path": str(destination), "archive": str(archive)})
        return destination

    def test_archive_preserves_staged_unstaged_untracked_and_private_files(self):
        (self.source / "staged").write_text("new index blob\n")
        self.git(self.source, "add", "staged")
        (self.source / "tracked").write_text("unstaged\n")
        (self.source / ".env").write_text("PRIVATE=value\n")
        before = self.git(self.source, "status", "--porcelain")
        destination = self.move_archive(self.source)
        self.assertEqual(self.git(destination, "status", "--porcelain"), before)
        self.assertEqual(self.git(destination, "show", ":staged"), "new index blob")
        self.assertEqual((destination / ".env").read_bytes(), (self.source / ".env").read_bytes())
        self.assertEqual(self.git(self.source, "status", "--porcelain"), before)

    def test_linked_worktree_becomes_independent_without_changing_seed_or_siblings(self):
        worktree = self.base / "worktree"
        self.git(self.source, "worktree", "add", "-qb", "feature", str(worktree))
        (worktree / "staged").write_text("worktree blob\n")
        self.git(worktree, "add", "staged")
        destination = self.move_archive(worktree)
        self.assertTrue((destination / ".git").is_dir())
        self.assertEqual(self.git(destination, "branch", "--show-current"), "feature")
        self.assertEqual(self.git(destination, "show", ":staged"), "worktree blob")
        self.assertFalse((self.source / "staged").exists())
        self.assertIn(str(worktree), self.git(self.source, "worktree", "list"))

    def test_prepare_marker_permits_owned_preinit_retry_and_refuses_replaced_identity(self):
        path = self.base / "new"
        value = {"source": {"checkout": str(path), "root": str(self.base), "prepare_id": "fixture", "seed_repository": None}, "resolve": {"seed_commit": None}}
        prepare = module("prepare_directory").prepare
        self.assertTrue(prepare(value)["created"])
        self.assertTrue(prepare(value)["created"])
        self.git(path, "init", "-q")
        self.assertFalse(prepare(value)["created"])
        (path / ".git/orbit-source-prepare").write_text("foreign\n")
        with self.assertRaisesRegex(ValueError, "identity_changed"):
            prepare(value)

    def test_removal_refuses_replaced_directory(self):
        proof = module("transfer_probe").probe({"path": str(self.source), "destination": False})
        moved = self.base / "saved"
        self.source.rename(moved)
        self.source.mkdir()
        with self.assertRaisesRegex(ValueError, "source_changed"):
            module("transfer_remove").remove({"proof": proof})
        self.assertTrue(moved.is_dir())

    def test_archive_extraction_refuses_traversal_and_external_links(self):
        archive = self.base / "unsafe.tar"
        with tarfile.open(archive, "w") as bundle:
            info = tarfile.TarInfo("../escape")
            info.size = 1
            bundle.addfile(info, io.BytesIO(b"x"))
        with self.assertRaises(tarfile.FilterError):
            module("transfer_install").install({"path": str(self.base / "target"), "archive": str(archive)})
        self.assertFalse((self.base / "escape").exists())
        self.assertFalse((self.base / "target").exists())
        self.assertEqual(list(self.base.glob(".orbit-transfer-*")), [])

    def test_environment_protection_refuses_symlinks(self):
        private = self.base / "private"
        private.write_text("unchanged")
        private.chmod(0o644)
        (self.source / ".env").symlink_to(private)
        with self.assertRaises(OSError):
            module("protect_environment").protect({"application": str(self.source)})
        self.assertEqual(private.stat().st_mode & 0o777, 0o644)

    def test_profile_returns_only_constraint_and_framework_metadata(self):
        (self.source / "composer.json").write_text('{"require":{"php":"^8.4","secret/package":"private"},"config":{"token":"private"}}')
        result = module("profile").profile({"application": str(self.source), "project_type": "laravel-package", "entry_point": None})
        self.assertNotIn("private", str(result))
        self.assertEqual(result["kind"], "composer")
