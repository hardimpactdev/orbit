"""Real Git fixtures for the shipped check actions; no fleet credentials or nodes."""

import json
import os
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

SCRIPTS = Path(__file__).parent / "scripts"
sys.path.insert(0, str(SCRIPTS))
import check_ci_read
from repository_common import snapshot


class Actions(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(
            prefix="orbit-check-", dir=os.environ.get("TMPDIR")
        )
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.work = self.root / "work"
        self.work.mkdir()
        self.assets = self.root / "remote/scripts"
        shutil.copytree(SCRIPTS, self.assets)
        self.git("init", "-qb", "task-1")
        self.git("config", "user.name", "Test")
        self.git("config", "user.email", "test@example.test")
        (self.work / "code").write_text("old\n")
        self.git("add", ".")
        self.git("commit", "-qm", "Base")
        self.base = self.git("rev-parse", "HEAD")
        self.value = {
            "checkout_path": str(self.work),
            "ssr_port": None,
            "deliverables": [],
            "base": self.base,
            "operation_id": 7,
            "message": "Checked task",
        }

    def git(self, *args):
        return subprocess.check_output(
            ["git", "-C", str(self.work), *args], stderr=subprocess.DEVNULL, text=True
        ).strip()

    def action(self, name, *args, value=None, code=0):
        result = subprocess.run(
            [
                sys.executable,
                "-B",
                str(self.assets / name),
                *[
                    json.dumps(arg) if isinstance(arg, dict) else str(arg)
                    for arg in args
                ],
            ],
            input=json.dumps(value or self.value),
            check=False,
            capture_output=True,
            text=True,
        )
        self.assertEqual(result.returncode, code, result.stderr + result.stdout)
        return json.loads(result.stdout)

    def test_setup_checks_and_candidate_use_the_observed_tree(self):
        self.action("check_command.py", {"command": "printf new > code"})
        before = snapshot(str(self.work))
        result = self.action("check_command.py", {"command": "test $(cat code) = new"})
        self.assertEqual(result["exit_code"], 0)
        commit = self.action("check_commit.py", before)
        self.assertEqual(self.git("rev-parse", "HEAD"), commit["commit"])
        self.assertEqual(commit["tree"], before["tree"])
        self.assertEqual(self.action("check_commit.py", before), commit)
        self.assertEqual(
            self.git("rev-parse", "refs/orbit/check-operations/7"), commit["commit"]
        )

    def test_moved_tree_cannot_commit_saved_evidence(self):
        before = snapshot(str(self.work))
        (self.work / "code").write_text("moved\n")
        self.assertEqual(self.action("check_commit.py", before), {"changed": True})
        self.assertEqual(self.git("rev-parse", "HEAD"), self.base)

    def test_start_tree_failure_and_declared_test_overlay(self):
        (self.work / "tests").mkdir()
        (self.work / "tests/assert.sh").write_text('test "$(cat code)" = new\n')
        (self.work / "code").write_text("new\n")
        item = {
            "id": "test",
            "command": "bash tests/assert.sh",
            "directory": ".",
            "paths": ["tests/assert.sh"],
            "fails_on_base": True,
        }
        self.value["deliverables"] = [{"type": "command", **item}]
        self.assertEqual(self.action("check_validate.py"), {"valid": True})
        prepared = self.action("check_base_prepare.py", item, "0")
        self.assertEqual(self.action("check_command.py", item)["exit_code"], 0)
        base = self.action("check_command.py", item, prepared["checkout_path"], code=1)
        self.assertEqual(base["exit_code"], 1)
        self.assertEqual(
            (Path(prepared["checkout_path"]) / "code").read_text(), "old\n"
        )

    def test_escaping_overlay_is_rejected_before_any_command(self):
        self.value["deliverables"] = [
            {"type": "command", "directory": ".", "paths": ["../code"]}
        ]
        self.assertEqual(
            self.action("check_validate.py"),
            {"error_code": "tasks.deliverable_invalid"},
        )
        self.assertEqual(self.git("rev-parse", "HEAD"), self.base)

    def test_check_failure_and_private_output_are_public_evidence(self):
        result = self.action(
            "check_command.py",
            {"command": "printf 'API_KEY=synthetic-private-value\\n'; exit 23"},
            code=23,
        )
        self.assertEqual(result["exit_code"], 23)
        self.assertNotIn("synthetic-private-value", result["output"])
        self.assertIn("[REDACTED]", result["output"])

    def test_ci_tick_makes_one_read_and_drops_private_github_fields(self):
        pending = {"inputs":{"intent":{"repository":"acme/check"}},"result":{"commit":self.base}}
        reply = {"total_count":1,"check_runs":[{"id":3,"name":"Required checks","head_sha":self.base,"status":"in_progress","conclusion":None,"app":{"slug":"github-actions"},"output":{"summary":"synthetic-private-summary"}}]}
        with patch.dict(os.environ,{"ORBIT_PENDING_CHECK":json.dumps(pending)}), patch("check_ci_read.request",return_value=reply) as request:
            result = check_ci_read.observe()
            request.assert_called_once_with("repos/acme/check/commits/"+self.base+"/check-runs?filter=latest&per_page=100")
            self.assertNotIn("summary",json.dumps(result))
            reply["total_count"]=101
            with self.assertRaisesRegex(Exception,"tasks.handoff_ci_unavailable"):
                check_ci_read.observe()
            self.assertEqual(request.call_count,2)

    def test_diff_keeps_added_and_modified_paths(self):
        (self.work / "code").write_text("new\n")
        (self.work / "added").write_text("new\n")
        rows = self.action("check_diff.py", snapshot(str(self.work)))["diff"]
        self.assertEqual(
            rows, [{"path": "added", "status": "A"}, {"path": "code", "status": "M"}]
        )


if __name__ == "__main__":
    unittest.main()
