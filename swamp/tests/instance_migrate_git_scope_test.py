"""Scope retirement guards with real Git stores through the shipped Ansible tasks."""
import json
import os
import runpy
import shutil
import subprocess
import sys
import tempfile
import time
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[2]
HELPERS = runpy.run_path(str(ROOT / "swamp/tests/fixtures/instance-migrate-retention.py"))
git, repository, snapshot = (HELPERS[name] for name in ("git", "repository", "snapshot"))


@unittest.skipUnless(shutil.which("ansible-playbook"), "needs pinned Ansible")
class RetirementScopeTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="orbit-retirement-scope-")
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.owner, self.child = self.root / "owner", self.root / "child"
        repository(self.owner)
        remote = self.root / "origin.git"
        git(self.root, "init", "--bare", "--quiet", str(remote))
        git(self.owner, "remote", "add", "origin", str(remote))
        git(self.owner, "push", "--quiet", "--set-upstream", "origin", "main")
        git(self.owner, "worktree", "add", "--quiet", "-b", "child", str(self.child))
        self.private = Path(git(self.child, "rev-parse", "--absolute-git-dir"))
        tree = git(self.owner, "rev-parse", "HEAD^{tree}")
        self.other = git(self.owner, "commit-tree", tree, "-p", "HEAD", "-m", "other local-only branch")
        git(self.owner, "update-ref", "refs/heads/other-local", self.other)
        for number in range(2):
            checkout = self.owner if number == 0 else self.child
            (checkout / "content.txt").write_text(f"shared stash {number}\\n")
            git(checkout, "stash", "push", "--quiet", "-m", f"shared-{number}")
        self.stash = git(self.owner, "rev-parse", "refs/stash")
        self.sequence = 0

    def check(self, target, retire=False, expected=None):
        self.sequence += 1
        run = self.root / f"run-{self.sequence}"
        run.mkdir()
        result_file = run / "result.json"
        tasks = ROOT / "swamp/ansible/tasks"
        include = [{"ansible.builtin.include_tasks": str(tasks / "instance-migrate-retirement-check.yml")}]
        if retire:
            include.append({"ansible.builtin.include_tasks": str(tasks / "instance-migrate-retire.yml")})
        record = {"ansible.builtin.copy": {
            "dest": str(result_file), "mode": "0600",
            "content": "{{ {'error_code': migrate_refusal_code | default(none), "
                       "'message': migrate_refusal_message | default(''), "
                       "'unpushed': retirement_unpushed | default([])} | to_json }}"}}
        play = [{"hosts": "localhost", "connection": "local", "gather_facts": False,
                 "vars": {"ansible_python_interpreter": sys.executable,
                          "retired": {"path": str(target)}, "migrate": {"checkout": str(target)}},
                 "tasks": [{"block": include, "rescue": [
                     record, {"ansible.builtin.fail": {"msg": "fixture records the production refusal"}}]}, record]}]
        playbook = run / "play.yml"
        playbook.write_text(yaml.safe_dump(play, sort_keys=False))
        before_owner, before_child = snapshot(self.owner), snapshot(self.child) if self.child.exists() else {}
        started = time.monotonic()
        done = subprocess.run(
            ["ansible-playbook", "-i", "localhost,", str(playbook)],
            env={**os.environ, "ORBIT_RUN": str(run), "ANSIBLE_HOME": str(run / "ansible"),
                 "ANSIBLE_FORKS": "1", "ANSIBLE_NOCOLOR": "1", "GIT_OPTIONAL_LOCKS": "0"},
            check=False, capture_output=True, text=True)
        self.assertTrue(result_file.exists(), done.stdout + done.stderr)
        result = json.loads(result_file.read_text())
        self.assertEqual(result["error_code"], expected, done.stdout + done.stderr)
        self.assertEqual(done.returncode == 0, expected is None, done.stdout + done.stderr)
        if expected is not None or not retire:
            self.assertEqual(snapshot(self.owner), before_owner)
            self.assertEqual(snapshot(self.child) if self.child.exists() else {}, before_child)
        print(f"{self.id()} phase {self.sequence}: {time.monotonic() - started:.3f}s", flush=True)
        return result

    def test_linked_child_passes_preflight_and_retirement_with_other_branch_and_shared_stashes(self):
        before = snapshot(self.owner, (self.private,))
        self.check(self.child, retire=True)
        self.assertFalse(self.child.exists())
        self.assertFalse(self.private.exists())
        self.assertEqual(snapshot(self.owner), before)
        self.assertEqual(git(self.owner, "rev-parse", "refs/heads/other-local"), self.other)
        self.assertEqual(git(self.owner, "rev-parse", "refs/stash"), self.stash)
        retained = next(Path(str(self.child) + ".orbit-retired-git").glob("*/.git")).parent
        self.assertEqual(git(retained, "symbolic-ref", "--short", "HEAD"), "child")
        git(retained, "fsck", "--full")

    def test_child_own_unpushed_branch_refuses_before_downtime_and_names_only_that_branch(self):
        self.check(self.child)
        git(self.child, "commit", "--quiet", "--allow-empty", "-m", "child-only commit")
        result = self.check(self.child, expected="instance.migrate_unpushed_commits")
        self.assertEqual(result["unpushed"], [{"branch": "child", "commits": 1}])
        self.assertIn("child", result["message"])
        self.assertNotIn("other-local", result["message"])

    def test_owner_still_checks_every_branch_and_shared_stash_after_the_child_leaves(self):
        self.check(self.child, retire=True)
        result = self.check(self.owner, expected="instance.migrate_unpushed_commits")
        self.assertEqual(result["unpushed"], [{"branch": "other-local", "commits": 1}])
        git(self.owner, "push", "--quiet", "origin", "other-local")
        result = self.check(self.owner, expected="instance.migrate_stashes_present")
        self.assertIn("Stashes: 2", result["message"])
        self.assertEqual(git(self.owner, "rev-parse", "refs/stash"), self.stash)

    def test_linked_detached_unpushed_head_refuses_before_downtime(self):
        self.check(self.child)
        git(self.child, "commit", "--quiet", "--allow-empty", "-m", "detached unpushed")
        git(self.child, "checkout", "--quiet", "--detach", "HEAD")
        result = self.check(self.child, expected="instance.migrate_unpushed_commits")
        self.assertEqual(result["unpushed"], [{"branch": "detached HEAD", "commits": 1}])
        self.assertIn("detached HEAD", result["message"])
        self.assertNotIn("other-local", result["message"])


if __name__ == "__main__":
    unittest.main()
