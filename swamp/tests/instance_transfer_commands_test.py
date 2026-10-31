"""Run the transfer workflow's visible commands against disposable Git checkouts."""

import json
import os
import pwd
import shutil
import subprocess
import tarfile
import tempfile
import unittest
import uuid
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[1]


class TransferCommands(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="orbit-transfer-commands-")
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name).resolve()
        self.source = self.root / "source"
        self.source.mkdir()
        self.git("init", "--quiet", "--initial-branch=main")
        self.git("config", "user.name", "Transfer fixture")
        self.git("config", "user.email", "fixture@example.invalid")
        self.git("remote", "add", "origin", "https://example.invalid/org/repo.git")
        (self.source / "tracked").write_text("committed\n")
        self.git("add", "tracked")
        self.git("commit", "--quiet", "-m", "fixture")
        self.steps = {
            step["name"]: step
            for name in ("workflow-instance-transfer-capture.yaml", "workflow-instance-transfer-materialize.yaml", "workflow-instance-transfer-retire-checkout.yaml")
            for job in yaml.safe_load((ROOT / "workflows" / name).read_text())["jobs"]
            for step in job["steps"]
        }
        self.env = {
            "SOURCE_PATH": str(self.source),
            "SOURCE_REPOSITORY": "example.invalid/org/repo",
            "SOURCE_LAYOUT": "checkout",
            "SOURCE_BRANCH": "main",
            "EXPECTED_USER": pwd.getpwuid(os.getuid()).pw_name,
            "DESTINATION_ROOT": str(self.root),
            "DESTINATION_PATH": str(self.root / "new" / "destination"),
        }

    def git(self, *args):
        return subprocess.check_output(
            ["git", "-C", str(self.source), *args], stderr=subprocess.DEVNULL
        ).decode().strip()

    def command(self, name, **env):
        return subprocess.run(
            ["/bin/sh", "-c", self.steps[name]["task"]["inputs"]["run"]],
            env={"PATH": "/usr/bin:/bin", **self.env, **env},
            capture_output=True,
            check=False,
            text=True,
            timeout=15,
        )

    def test_inspects_checkout_without_printing_file_contents(self):
        (self.source / ".env").write_text("PRIVATE_FIXTURE=never-in-output\n")

        result = self.command("inspect-source-checkout")

        self.assertEqual(result.returncode, 0, result.stderr)
        facts = json.loads(result.stdout)
        self.assertEqual(facts["head"], self.git("rev-parse", "HEAD"))
        self.assertEqual(facts["branch"], "main")
        self.assertEqual(facts["inode"], self.source.stat().st_ino)
        self.assertNotIn("never-in-output", result.stdout + result.stderr)

    def test_refuses_branch_drift(self):
        result = self.command("inspect-source-checkout", SOURCE_BRANCH="different")

        self.assertNotEqual(result.returncode, 0)
        self.assertIn("instance.source_branch_mismatch", result.stderr)

    def test_refuses_checkout_that_is_shared_by_another_worktree(self):
        self.git("worktree", "add", "--quiet", "-b", "feature", str(self.root / "linked"))

        result = self.command("inspect-source-checkout")

        self.assertNotEqual(result.returncode, 0)
        self.assertIn("instance.transfer_shared_checkout", result.stderr)

    def test_accepts_linked_worktree_with_its_own_identity(self):
        path = self.root / "linked"
        self.git("worktree", "add", "--quiet", "-b", "feature", str(path))

        result = self.command(
            "inspect-source-checkout", SOURCE_PATH=str(path),
            SOURCE_LAYOUT="worktree", SOURCE_BRANCH="feature",
        )

        self.assertEqual(result.returncode, 0, result.stderr)
        facts = json.loads(result.stdout)
        self.assertEqual(facts["path"], str(path))
        self.assertEqual(facts["layout"], "worktree")
        self.assertEqual(facts["common"], str(self.source / ".git"))

    def test_refuses_credential_bearing_origin_without_exposing_it(self):
        self.git("remote", "set-url", "origin", "https://user:private-sentinel@example.invalid/org/repo")

        result = self.command("inspect-source-checkout")

        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn("private-sentinel", result.stdout + result.stderr)

    def test_refuses_wrong_repository(self):
        result = self.command("inspect-source-checkout", SOURCE_REPOSITORY="example.invalid/foreign/repo")

        self.assertNotEqual(result.returncode, 0)
        self.assertIn("instance.source_identity_invalid", result.stderr)

    def test_refuses_symlinked_source(self):
        link = self.root / "alias"
        link.symlink_to(self.source, target_is_directory=True)

        result = self.command("inspect-source-checkout", SOURCE_PATH=str(link))

        self.assertNotEqual(result.returncode, 0)

    def test_destination_preflight_does_not_create_the_destination(self):
        result = self.command("check-destination-path")

        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertFalse((self.root / "new").exists())

    def test_refuses_destination_traversal_and_invalid_segments(self):
        for suffix in ("../escape", "new/../escape", "new//escape", "new/./escape", "new/escape/", "new/line\nbreak"):
            with self.subTest(suffix=suffix):
                result = self.command("check-destination-path", DESTINATION_PATH=str(self.root) + "/" + suffix)

                self.assertNotEqual(result.returncode, 0)
                self.assertIn("instance.transfer_destination_invalid", result.stderr)

    def test_refuses_existing_and_symlinked_destination_parents(self):
        link = self.root / "alias"
        link.symlink_to(self.source, target_is_directory=True)
        for path in (str(self.source), str(link / "new")):
            with self.subTest(path=path):
                result = self.command("check-destination-path", DESTINATION_PATH=path)

                self.assertNotEqual(result.returncode, 0)

    def test_does_not_interpret_public_input_as_shell_code(self):
        marker = self.root / "injected"

        result = self.command("check-destination-path", DESTINATION_PATH=f"{self.root}/$(touch {marker})")

        self.assertNotEqual(result.returncode, 0)
        self.assertFalse(marker.exists())

    def test_archive_retains_worktree_and_staged_only_objects_without_changing_source_index(self):
        self.git("branch", "retained-local-branch")
        self.git("tag", "retained-local-tag")
        self.git("config", "orbit.fixture", "retained-local-config")
        for layout in ("checkout", "worktree"):
            with self.subTest(layout=layout):
                source = self.source
                if layout == "worktree":
                    source = self.root / "linked"
                    self.git("worktree", "add", "--quiet", "-b", "feature", str(source))
                tracked = source / "tracked"
                tracked.write_text("staged-only-content\n")
                subprocess.run(["git", "-C", str(source), "add", "tracked"], check=True)
                tracked.write_text("dirty-worktree-content\n")
                (source / "untracked").write_text("untracked-file\n")
                (source / ".env").write_text("ARCHIVE_PRIVATE_SENTINEL=retained\n")
                index = Path(subprocess.check_output([
                    "git", "-C", str(source), "rev-parse", "--path-format=absolute", "--git-path", "index",
                ]).decode().strip())
                original_index = index.read_bytes()
                scratch_result = self.command("prepare-source-scratch", NATIVE_RUN_ID=str(uuid.uuid4()))
                self.assertEqual(scratch_result.returncode, 0, scratch_result.stderr)
                scratch = Path(scratch_result.stdout)
                self.addCleanup(shutil.rmtree, scratch, True)
                self.assertEqual(scratch.stat().st_mode & 0o777, 0o700)

                for step in ("bundle-source-commits", "save-source-index", "expand-saved-index", "pack-staged-objects", "archive-source-files", "append-git-state", "digest-source-archive"):
                    if layout == "checkout" and step in ("bundle-source-commits", "pack-staged-objects"):
                        continue
                    result = self.command(step, SCRATCH_PATH=str(scratch), SOURCE_PATH=str(source), SOURCE_LAYOUT=layout)
                    self.assertEqual(result.returncode, 0, step + ": " + result.stderr)
                    self.assertNotIn("ARCHIVE_PRIVATE_SENTINEL", result.stdout + result.stderr)

                facts = json.loads(result.stdout)
                self.assertEqual(facts["bytes"], (scratch / "source.tar").stat().st_size)
                self.assertEqual(len(facts["sha256"]), 64)
                self.assertEqual(index.read_bytes(), original_index)
                self.assertEqual((scratch / "source.tar").stat().st_mode & 0o777, 0o600)
                destination = self.root / ("restored-" + layout)
                source_proof = self.command("inspect-source-checkout", SOURCE_PATH=str(source), SOURCE_LAYOUT=layout,
                                            SOURCE_BRANCH="feature" if layout == "worktree" else "main")
                self.assertEqual(source_proof.returncode, 0, source_proof.stderr)
                materialize_env = {"DESTINATION_PATH": str(destination), "ENVIRONMENT_PATH": str(destination / ".env"),
                                   "ARCHIVE_PATH": str(scratch / "source.tar"), "ARCHIVE_BYTES": str(facts["bytes"]),
                                   "ARCHIVE_SHA256": facts["sha256"], "SOURCE_PROOF": source_proof.stdout, "SOURCE_LAYOUT": layout}
                names = ("verify-incoming-archive", "prepare-destination-staging", "extract-checkout-files",
                         "initialize-independent-checkout", "fetch-committed-history", "import-staged-objects",
                         "restore-branch", "restore-commit", "restore-index", "restore-origin",
                         "remove-transfer-metadata", "protect-environment-file", "verify-restored-checkout", "publish-checkout")
                for name in names:
                    if layout == "checkout" and name in ("initialize-independent-checkout", "fetch-committed-history", "import-staged-objects", "restore-branch", "restore-commit"):
                        continue
                    result = self.command(name, **materialize_env)
                    self.assertEqual(result.returncode, 0, name + ": " + result.stderr)
                    self.assertNotIn("ARCHIVE_PRIVATE_SENTINEL", result.stdout + result.stderr)
                    if name == "prepare-destination-staging":
                        materialize_env["DESTINATION_STAGING"] = result.stdout
                    elif name == "verify-restored-checkout":
                        materialize_env["DESTINATION_PROOF"] = result.stdout
                staged = subprocess.check_output(["git", "-C", str(destination), "show", ":tracked"])
                self.assertEqual(staged, b"staged-only-content\n")
                self.assertEqual((destination / "tracked").read_text(), "dirty-worktree-content\n")
                self.assertEqual((destination / "untracked").read_text(), "untracked-file\n")
                self.assertEqual((destination / ".env").read_text(), "ARCHIVE_PRIVATE_SENTINEL=retained\n")
                self.assertEqual((destination / ".env").stat().st_mode & 0o777, 0o600)
                self.assertEqual(subprocess.check_output(["git", "-C", str(destination), "branch", "--show-current"]).decode().strip(),
                                 "feature" if layout == "worktree" else "main")
                replay = self.command("publish-checkout", **materialize_env)
                self.assertEqual(replay.returncode, 0, replay.stderr)
                if layout == "checkout":
                    self.assertEqual(subprocess.check_output(["git", "-C", str(destination), "config", "orbit.fixture"]).decode().strip(), "retained-local-config")
                    self.assertEqual(subprocess.check_output(["git", "-C", str(destination), "rev-parse", "retained-local-branch"]).decode().strip(), self.git("rev-parse", "HEAD"))
                    self.assertEqual(subprocess.check_output(["git", "-C", str(destination), "tag", "--list"]).decode().strip(), "retained-local-tag")

    def test_archive_digest_refusal_precedes_any_destination_creation(self):
        path = self.root / "source.tar"
        path.write_bytes(b"not-the-admitted-archive")
        path.chmod(0o600)

        result = self.command("verify-incoming-archive", ARCHIVE_PATH=str(path), ARCHIVE_BYTES=str(path.stat().st_size), ARCHIVE_SHA256="a" * 64)

        self.assertNotEqual(result.returncode, 0)
        self.assertIn("instance.transfer_archive_invalid", result.stderr)
        self.assertFalse((self.root / "new").exists())

    def test_archive_extraction_refuses_escaping_members(self):
        import io

        for name in ("../escaped", "/tmp/escaped", ".git/hooks/post-checkout"):
            with self.subTest(name=name):
                archive_path = self.root / "hostile.tar"
                with tarfile.open(archive_path, "w") as archive:
                    entry = tarfile.TarInfo(name)
                    entry.size = 1
                    archive.addfile(entry, io.BytesIO(b"x"))
                staging = Path(tempfile.mkdtemp(dir=self.root))

                result = self.command("extract-checkout-files", ARCHIVE_PATH=str(archive_path), DESTINATION_STAGING=str(staging), SOURCE_LAYOUT="worktree")

                self.assertNotEqual(result.returncode, 0)
                self.assertFalse((self.root / "escaped").exists())

    def test_retirement_refuses_a_replaced_source_checkout(self):
        proof = self.command("inspect-source-checkout")
        self.assertEqual(proof.returncode, 0, proof.stderr)
        saved = self.root / "saved-source"
        self.source.rename(saved)
        self.source.mkdir()
        (self.source / "must-survive").write_text("replacement\n")

        result = self.command("remove-source-checkout", SOURCE_PROOF=proof.stdout)

        self.assertNotEqual(result.returncode, 0)
        self.assertIn("instance.transfer_path_unproven", result.stderr)
        self.assertEqual((self.source / "must-survive").read_text(), "replacement\n")
        self.assertTrue((saved / ".git").is_dir())

    def test_linked_worktree_retirement_preserves_the_common_repository_and_sibling(self):
        path = self.root / "linked"
        self.git("worktree", "add", "--quiet", "-b", "feature", str(path))
        proof = self.command("inspect-source-checkout", SOURCE_PATH=str(path), SOURCE_LAYOUT="worktree", SOURCE_BRANCH="feature")
        self.assertEqual(proof.returncode, 0, proof.stderr)

        result = self.command("remove-source-checkout", SOURCE_PROOF=proof.stdout)

        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertFalse(path.exists())
        self.assertTrue((self.source / ".git").is_dir())
        self.assertEqual(self.git("branch", "--show-current"), "main")
        self.assertEqual(self.git("worktree", "list", "--porcelain").count("worktree "), 1)
        replay = self.command("remove-source-checkout", SOURCE_PROOF=proof.stdout)
        self.assertEqual(replay.returncode, 0, replay.stderr)


if __name__ == "__main__":
    unittest.main()
