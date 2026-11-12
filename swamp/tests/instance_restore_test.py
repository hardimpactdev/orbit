"""Unprivileged restore evidence and actual Ansible marker-compensation fixtures."""
import copy
import grp
import json
import os
import pwd
import runpy
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import yaml

ROOT = Path(__file__).resolve().parents[2]
SOURCE = ROOT / "swamp/ansible/instance/restore-source.py"


class InstanceRestoreTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="orbit-restore-fixture-")
        self.addCleanup(self.temp.cleanup)
        self.folder = Path(self.temp.name)
        self.checkout = self.folder / "checkout"
        self.git("init", "-q", "--initial-branch=main", str(self.checkout))
        (self.checkout / "public").mkdir()
        (self.checkout / "public/index").write_text("surviving source")
        self.git("-C", str(self.checkout), "add", ".")
        self.git("-C", str(self.checkout), "-c", "user.name=Fixture",
                 "-c", "user.email=fixture@example.test", "commit", "-qm", "fixture")
        self.git("-C", str(self.checkout), "remote", "add", "origin",
                 "https://github.com/example/fixture.git")
        self.member = {
            "instance_id": 1, "path": str(self.checkout), "root": "public",
            "repository_identity": "github.com/example/fixture", "branch": "main",
            "commit": self.git("-C", str(self.checkout), "rev-parse", "HEAD").stdout.strip(),
            "awake_path": str(self.folder / "fixture.awake"),
        }
        self.program = runpy.run_path(str(SOURCE))

    def git(self, *args):
        return subprocess.run(["git", *args], check=True, capture_output=True, text=True, timeout=5)

    def read(self, member=None):
        return self.program["source"]({"members": [member or self.member], "container": None, "sqlite": []})

    def test_intact_source_read_preserves_checkout_and_reports_marker_baseline(self):
        marker = Path(self.member["awake_path"])
        marker.touch()
        before = (self.checkout / "public/index").read_bytes()
        result = self.read()
        self.assertEqual(result, {"outcome": "intact", "data": {
            "awake_before": [{"instance_id": 1, "present": True}]}})
        self.assertEqual((self.checkout / "public/index").read_bytes(), before)

    def test_source_deletion_refuses_without_creating_anything(self):
        shutil.rmtree(self.checkout)
        self.assertEqual(self.read()["data"]["reason"], "source_removed")
        self.assertFalse(self.checkout.exists())

    def test_changed_branch_origin_commit_or_workspace_refuses(self):
        for field, value in [("branch", "elsewhere"), ("commit", "b" * 40),
                             ("repository_identity", "github.com/other/repository"),
                             ("root", "missing")]:
            with self.subTest(field=field):
                member = {**self.member, field: value}
                self.assertEqual(self.read(member)["data"]["reason"], "source_changed")
        self.assertEqual((self.checkout / "public/index").read_text(), "surviving source")

    def test_every_planned_sqlite_file_must_survive_the_same_bounded_read(self):
        first = self.checkout / "first.sqlite"
        second = self.checkout / "second.sqlite"
        first.write_bytes(b"surviving data")
        second.write_bytes(b"other surviving data")
        request = {"members": [self.member], "container": None, "sqlite": [
            {"checkout": str(self.checkout), "path": str(path)} for path in (first, second)]}
        self.assertEqual(self.program["source"](request)["outcome"], "intact")
        second.unlink()
        self.assertEqual(self.program["source"](request), {
            "outcome": "refused", "data": {"reason": "sqlite_removed", "path": str(second)}})
        self.assertEqual(first.read_bytes(), b"surviving data")
        self.assertFalse(second.exists())

    def test_removed_container_refuses_before_reading_checkout(self):
        request = {"members": [self.member], "container": {"project": "fixture", "name": "fixture"}, "sqlite": []}
        with patch.object(self.program["subprocess"], "run", return_value=subprocess.CompletedProcess([], 1)) as call:
            self.assertEqual(self.program["source"](request)["data"]["reason"], "container_removed")
            self.assertEqual(call.call_count, 1)

    def test_restore_fixture_makes_the_checkout_writable_before_git_initialization(self):
        sys.path.insert(0, str(ROOT / "swamp/proof/runner"))
        fixture = runpy.run_path(str(ROOT / "swamp/proof/runner/restore_fixture.py"))
        checkout = self.folder / "fixture-checkout"
        (checkout / "public").mkdir(parents=True)
        checkout.chmod(0o555)
        self.addCleanup(checkout.chmod, 0o755)
        broken = subprocess.run(["git", "-C", str(checkout), "init", "-q"],
                                capture_output=True, text=True, timeout=5)
        self.assertNotEqual(broken.returncode, 0)
        slug = "restore-proof-111111111111"
        script = fixture["checkout_script"](str(checkout), slug)
        # Replace only account/privilege boundaries; execute the shipped directory and Git commands.
        user, group = pwd.getpwuid(os.getuid()).pw_name, grp.getgrgid(os.getgid()).gr_name
        script = script.replace("-o orbit -g orbit", f"-o {user} -g {group}")
        script = script.replace("runuser -u orbit -- ", "")
        result = subprocess.run(["sh", "-c", script], capture_output=True, text=True, timeout=10,
                                env={**os.environ, "GIT_CONFIG_GLOBAL": "/dev/null", "GIT_CONFIG_NOSYSTEM": "1"})
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(len(result.stdout.strip()), 40)
        self.assertEqual((checkout / "public/index.html").read_text(), slug)
        self.assertEqual((checkout / ".git").stat().st_uid, os.getuid())

    def test_restore_proof_has_a_runnable_isolated_slot_fixture_and_three_cli_stages(self):
        sys.path.insert(0, str(ROOT / "swamp/proof/runner"))
        import spec
        document = spec.load(ROOT / "swamp/proof/instance/restore.cases.yaml",
                             ROOT / "swamp/proof/batches.yaml")
        case = next(case for case in document.cases if case.id == "restore-slot-blocked-removal")
        self.assertTrue(case.exclusive)
        commands = [step.args["run"][0] for step in spec.case_steps(case) if step.name == "cli"]
        self.assertEqual(commands, ["instance:restore", "instance:restore", "instance:destroy"])

    def test_admin_read_uses_stdin_and_never_returns_private_client_output(self):
        facts = {"databases": [{"name": "fixture", "oid": 42, "owner": "fixture", "allow": True}],
                 "users": [{"name": "fixture", "oid": 43}]}
        request = {"argv": ["docker", "exec", "-i", "fixture-postgres", "psql"],
                   "password": "fixture-private-password", "sql": "BEGIN READ ONLY; SELECT 1; ROLLBACK;"}
        reply = subprocess.CompletedProcess([], 0, json.dumps(facts) + "\n[]\n", "")
        with patch.object(self.program["subprocess"], "run", return_value=reply) as call:
            result = self.program["catalog"](request)
            self.assertEqual(result["data"], {**facts, "fences": []})
            self.assertNotIn(request["password"], json.dumps(call.call_args.args))
            self.assertTrue(call.call_args.kwargs["input"].startswith(request["password"] + "\n"))
            self.assertNotIn(request["password"], json.dumps(result))
        reply = subprocess.CompletedProcess([], 1, "", request["password"])
        with patch.object(self.program["subprocess"], "run", return_value=reply):
            self.assertEqual(self.program["catalog"](request), {
                "outcome": "refused", "data": {"reason": "evidence_missing"}})

    def test_publication_failure_compensation_removes_only_new_awake_markers_and_repeats(self):
        new = self.folder / "new.awake"
        retained = self.folder / "retained.awake"
        unrelated = self.folder / "unrelated.awake"
        for path in [new, retained, unrelated]:
            path.write_text("preserve" if path != new else "")
        intent = {
            "awake_before": [{"instance_id": 1, "present": False}, {"instance_id": 2, "present": True}],
            "route_create": {"awake": [
                {"instance_id": 1, "node_id": 7, "path": str(new)},
                {"instance_id": 2, "node_id": 7, "path": str(retained)},
                {"instance_id": 3, "node_id": 9, "path": str(unrelated)},
            ]},
        }
        (self.folder / "intent.json").write_text(json.dumps(intent))
        # Replace transport and privilege boundaries only; execute the shipped file module and selection.
        phase = copy.deepcopy(yaml.safe_load((ROOT / "swamp/ansible/instance-restore.yml").read_text())[2])
        phase.update(hosts="localhost", connection="local", become=False)
        phase["vars"]["ansible_python_interpreter"] = sys.executable
        play = self.folder / "compensate.yml"
        play.write_text(yaml.safe_dump([phase], sort_keys=False))
        command = [shutil.which("ansible-playbook") or "ansible-playbook", "-i", "localhost,",
                   "--tags", "compensate", str(play)]
        (self.folder / "ansible.cfg").write_text("[defaults]\n")
        environment = {**os.environ, "ORBIT_RUN": str(self.folder), "ORBIT_INDEX": "7",
                       "ANSIBLE_CONFIG": str(self.folder / "ansible.cfg"), "ANSIBLE_COLLECTIONS_PATH": str(self.folder / "collections"),
                       "ANSIBLE_STDOUT_CALLBACK": "default", "ANSIBLE_NOCOLOR": "1", "ANSIBLE_FORKS": "1"}
        first = subprocess.run(command, env=environment, capture_output=True, text=True, timeout=30)
        self.assertEqual(first.returncode, 0, first.stdout + first.stderr)
        self.assertFalse(new.exists())
        self.assertEqual(retained.read_text(), "preserve")
        self.assertEqual(unrelated.read_text(), "preserve")
        again = subprocess.run(command, env=environment, capture_output=True, text=True, timeout=30)
        self.assertEqual(again.returncode, 0, again.stdout + again.stderr)
        self.assertIn("changed=0", again.stdout)


if __name__ == "__main__":
    unittest.main()
