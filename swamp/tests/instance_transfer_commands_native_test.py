"""Run the actual placed-command DAG locally with disposable, synthetic source data."""

import hashlib
import json
import os
import pwd
import shutil
import subprocess
import tarfile
from pathlib import Path

import yaml
from native_harness import NativeTestCase

ROOT = Path(__file__).resolve().parents[1]


class TransferMaterializeNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.source = self.repo / "fixture-source"
        self.source.mkdir()
        self.git(self.source, "init", "--quiet", "--initial-branch=feature")
        self.git(self.source, "config", "user.name", "Native fixture")
        self.git(self.source, "config", "user.email", "fixture@example.invalid")
        self.git(self.source, "remote", "add", "origin", "https://example.invalid/org/repo.git")
        (self.source / "tracked").write_text("committed\n")
        self.git(self.source, "add", "tracked")
        self.git(self.source, "commit", "--quiet", "-m", "fixture")
        (self.source / "tracked").write_text("staged\n")
        self.git(self.source, "add", "tracked")
        (self.source / "tracked").write_text("dirty\n")
        (self.source / ".env").write_text("TRANSFER_SYNTHETIC_PRIVATE=retained\n")
        metadata = self.repo / "git-metadata"
        metadata.mkdir()
        self.git(self.source, "bundle", "create", str(metadata / "checkout.bundle"), "HEAD")
        shutil.copyfile(self.source / ".git/index", metadata / "checkout.index")
        with (metadata / "checkout.pack").open("wb") as target:
            subprocess.run(["git", "-C", str(self.source), "pack-objects", "--stdout", "--revs"],
                           input=(self.git(self.source, "rev-parse", ":tracked") + "\n").encode(),
                           stdout=target, stderr=subprocess.DEVNULL, check=True)
        self.archive = self.repo / "incoming.tar"
        with tarfile.open(self.archive, "w") as archive:
            for name in ("tracked", ".env"):
                archive.add(self.source / name, arcname=name)
            for name in ("checkout.bundle", "checkout.index", "checkout.pack"):
                archive.add(metadata / name, arcname=name)
        self.archive.chmod(0o600)
        self.destination = self.repo / "destination"
        self.input = {
            "node_id": 9, "layout": "worktree", "destination": str(self.destination), "root": str(self.repo),
            "environment": str(self.destination / ".env"), "archive_path": str(self.archive),
            "archive_bytes": self.archive.stat().st_size,
            "archive_sha256": hashlib.sha256(self.archive.read_bytes()).hexdigest(),
            "source_proof": json.dumps({"head": self.git(self.source, "rev-parse", "HEAD"),
                                        "branch": "feature", "repository": "https://example.invalid/org/repo.git"}),
        }
        workflow = yaml.safe_load((ROOT / "workflows/workflow-instance-transfer-materialize.yaml").read_text())
        # This fixture has one local worker. The original file's placements are separately linted.
        for job in workflow["jobs"]:
            for step in job["steps"]:
                step.pop("target")
                step.pop("labels")
        (self.repo / "workflows/workflow-instance-transfer-materialize.yaml").write_text(yaml.safe_dump(workflow))
        self.input_path = self.repo / "input.json"

    def git(self, path, *args):
        return subprocess.check_output(["git", "-C", str(path), *args], stderr=subprocess.DEVNULL).decode().strip()

    def run_materialize(self, success=True):
        self.input_path.write_text(json.dumps(self.input))
        return self.cli("workflow", "run", "instance-transfer-materialize", "--input-file", str(self.input_path), success=success)

    def test_real_native_shell_outputs_wire_each_action_and_restore_exact_git_state(self):
        result = self.run_materialize()

        self.assertEqual((self.destination / "tracked").read_text(), "dirty\n")
        self.assertEqual(self.git(self.destination, "show", ":tracked"), "staged")
        self.assertEqual(self.git(self.destination, "branch", "--show-current"), "feature")
        self.assertEqual(self.git(self.destination, "rev-parse", "HEAD"), self.git(self.source, "rev-parse", "HEAD"))
        self.assertEqual(self.git(self.destination, "remote", "get-url", "origin"), "https://example.invalid/org/repo.git")
        self.assertEqual((self.destination / ".env").stat().st_mode & 0o777, 0o600)
        self.assertNotIn("TRANSFER_SYNTHETIC_PRIVATE", result.stdout + result.stderr)
        for path in (self.repo / ".swamp").rglob("*"):
            if path.is_file():
                self.assertNotIn(b"TRANSFER_SYNTHETIC_PRIVATE=retained", path.read_bytes(), str(path))
        self.assertFalse((self.destination / "checkout.bundle").exists())
        self.assertEqual(os.stat(self.source / "tracked").st_size, len("dirty\n"))

    def test_native_failure_skips_all_destination_effects_after_digest_refusal(self):
        self.input["archive_sha256"] = "a" * 64

        result = self.run_materialize(success=False)

        run = json.loads(result.stdout)
        self.assertEqual(run['jobs'][0]['steps'][0]['name'], 'verify-incoming-archive')
        self.assertEqual(run['jobs'][0]['steps'][0]['status'], 'failed')
        self.assertTrue(all(step['status'] == 'skipped' for step in run['jobs'][0]['steps'][1:]))
        self.assertFalse(self.destination.exists())
        self.assertEqual(list(self.repo.glob(".orbit-transfer-*")), [])

    def test_real_native_source_capture_retains_private_files_only_in_local_archive(self):
        path = ROOT / "workflows/workflow-instance-transfer-capture.yaml"
        workflow = yaml.safe_load(path.read_text())
        for job in workflow["jobs"]:
            for step in job["steps"]:
                step.pop("target")
                step.pop("labels")
        (self.repo / "workflows" / path.name).write_text(yaml.safe_dump(workflow))
        user = pwd.getpwuid(os.getuid()).pw_name
        self.input_path.write_text(json.dumps({
            "operation_id": 7, "kind": "instance.transfer", "node_id": 9,
            "intent": {"process_runtime": {}, "route_transfer": {}, "direct_transfer": {
                "transfer_id": "43a17cb2-f652-48ad-91a4-3ba2d921caae", "instance_id": 11,
                "source_node_id": 9, "destination_node_id": 17, "cutover": False, "completed": False,
                "source": {"source": str(self.source), "user": user, "layout": "checkout",
                           "repository_identity": "example.invalid/org/repo", "branch": "feature"},
                "destination": {"destination": str(self.destination), "root": str(self.repo),
                                "environment": str(self.destination / ".env"), "user": user},
            }},
        }))
        index = (self.source / ".git/index").read_bytes()

        result = self.cli("workflow", "run", "instance-transfer-capture", "--input-file", str(self.input_path))

        run = json.loads(result.stdout)
        for scratch in Path('/tmp').glob('orbit-transfer-' + run['id'] + '-*'):
            self.addCleanup(shutil.rmtree, scratch, True)
        history = json.loads(self.cli('workflow', 'history', 'get', run['id']).stdout)
        steps = {step["name"]: step for job in history["jobs"] for step in job["steps"]}
        scratch = Path(steps["prepare-source-scratch"]["outputs"]["stdout"])
        self.addCleanup(shutil.rmtree, scratch, True)
        facts = json.loads(steps["digest-source-archive"]["outputs"]["stdout"])
        self.assertEqual(hashlib.sha256((scratch / "source.tar").read_bytes()).hexdigest(), facts["sha256"])
        self.assertEqual((scratch / "source.tar").stat().st_mode & 0o777, 0o600)
        self.assertEqual((self.source / ".git/index").read_bytes(), index)
        self.assertNotIn("TRANSFER_SYNTHETIC_PRIVATE", result.stdout + result.stderr)
        self.assertFalse(self.destination.exists())
