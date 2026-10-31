"""Official native scheduler with disposable Git/GitHub and local SSH/SCP fixtures."""

import importlib.util
import json
import os
import shutil
import subprocess
import sys
import threading
from http.server import ThreadingHTTPServer
from pathlib import Path

import yaml
from publication_actions_test import TOKEN, GitHub

ROOT = Path(__file__).resolve().parents[4]
spec = importlib.util.spec_from_file_location(
    "native_harness", ROOT / "tests/native_harness.py"
)
harness = importlib.util.module_from_spec(spec)
spec.loader.exec_module(harness)


class NativePublication(harness.NativeTestCase):
    native_timeout = 90

    def setUp(self):
        super().setUp()
        self.secrets = (TOKEN,)
        source = ROOT / "extensions/behaviors/operations/task-repository"
        target = self.repo / "extensions/behaviors/operations/task-repository"
        shutil.copytree(
            source, target, ignore=shutil.ignore_patterns("*test*", "__pycache__")
        )
        tasks = self.repo / "extensions/behaviors/operations/tasks"
        tasks.mkdir(parents=True)
        shutil.copy(ROOT / "extensions/behaviors/operations/tasks/publish.ts", tasks)
        shutil.copy(
            ROOT / "extensions/behaviors/operations/task-repository/input.ts", target
        )
        official = self.repo / ".swamp/pulled-extensions/@swamp/ssh"
        official.parent.mkdir(parents=True)
        shutil.copytree(ROOT / ".swamp/pulled-extensions/@swamp/ssh", official)
        inventory = self.repo / "extensions/models/upstream_extensions.json"
        inventory.parent.mkdir(parents=True)
        inventory.write_text(
            json.dumps(
                {
                    "@swamp/ssh": json.loads(
                        (
                            ROOT / "extensions/models/upstream_extensions.json"
                        ).read_text()
                    )["@swamp/ssh"]
                }
            )
        )
        self.workspace = self.repo / "workspace"
        self.workspace.mkdir()
        self.git(self.workspace, "init", "-qb", "task-1")
        self.git(self.workspace, "config", "user.email", "test@example.test")
        self.git(self.workspace, "config", "user.name", "Test")
        self.workspace.joinpath("code").write_text("one\n")
        self.git(self.workspace, "add", ".")
        self.git(self.workspace, "commit", "-qm", "Fixture")
        self.head = self.git(self.workspace, "rev-parse", "HEAD")
        tree = self.git(self.workspace, "rev-parse", "HEAD^{tree}")
        self.remote = self.repo / "remote.git"
        self.git(self.repo, "init", "--bare", "-q", str(self.remote))
        self.server = ThreadingHTTPServer(("127.0.0.1", 0), GitHub)
        self.server.calls, self.server.pulls, self.server.lose_reply = [], [], False
        self.server.pull = {
            "number": 12,
            "state": "open",
            "head": {
                "ref": "task-1",
                "sha": self.head,
                "repo": {"full_name": "acme/shop"},
            },
            "base": {"ref": "main"},
        }
        threading.Thread(target=self.server.serve_forever, daemon=True).start()
        self.addCleanup(self.server.server_close)
        self.addCleanup(self.server.shutdown)
        tools = self.repo / "tools"
        tools.mkdir()
        ssh = tools / "ssh"
        ssh.write_text(
            "#!"
            + sys.executable
            + '\nimport shlex,subprocess,sys\nif "-M" in sys.argv or "-O" in sys.argv:raise SystemExit(0)\nr=subprocess.run(shlex.split(sys.argv[-1]),input=sys.stdin.buffer.read(),capture_output=True)\nsys.stdout.buffer.write(r.stdout);sys.stderr.buffer.write(r.stderr)\nraise SystemExit(r.returncode)\n'
        )
        ssh.chmod(0o755)
        scp = tools / "scp"
        scp.write_text(
            "#!"
            + sys.executable
            + '\nimport shutil,sys\nfrom pathlib import Path\ndef local(v):return v.split(":",1)[1] if ":" in v else v\nsrc=Path(local(sys.argv[-2]));dst=Path(local(sys.argv[-1]))\nif src.is_dir():shutil.copytree(src,dst/src.name,dirs_exist_ok=True)\nelse:shutil.copy2(src,dst)\n'
        )
        scp.chmod(0o755)
        shim = tools / "sitecustomize.py"
        shim.write_text(
            """import subprocess,urllib.request
original_build=urllib.request.build_opener
def build(*args,**kwargs):
 opener=original_build(*args,**kwargs)
 original_open=opener.open
 def open_local(request,*a,**kw):
  request.full_url=request.full_url.replace('https://api.github.com',API)
  return original_open(request,*a,**kw)
 opener.open=open_local
 return opener
urllib.request.build_opener=build
original_run=subprocess.run
def run(args,**kwargs):
 if 'https://github.com/acme/shop.git' in args:
  args=[REMOTE if v=='https://github.com/acme/shop.git' else v for v in args]
  kwargs['env']={**kwargs['env'],'GIT_ALLOW_PROTOCOL':'file'}
 return original_run(args,**kwargs)
subprocess.run=run
""".replace("API", repr("http://127.0.0.1:" + str(self.server.server_port))).replace(
                "REMOTE", repr(str(self.remote))
            )
        )
        self.native_env.update(
            PATH=str(tools) + ":" + os.environ["PATH"], PYTHONPATH=str(tools)
        )
        self.cli("vault", "create", "local_encryption", "orbit-control-inputs")
        self.cli(
            "vault",
            "put",
            "orbit-control-inputs",
            "task-publish-token-aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa",
            input=TOKEN,
        )
        self.inputs = {
            "operation_id": 15,
            "node_id": 30,
            "kind": "tasks.publish",
            "execution_secrets": {
                "github_token": {
                    "vault": "orbit-control-inputs",
                    "key": "task-publish-token-aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa",
                }
            },
            "intent": {
                "group_id": 1,
                "instance_id": 2,
                "node_id": 30,
                "check": 4,
                "checkout_path": str(self.workspace),
                "commit": self.head,
                "tree": tree,
                "repository": "acme/shop",
                "base": "main",
                "branch": "task-1",
                "pull_request": {
                    "title": "Export",
                    "body": "User prose $(touch inert).",
                },
                "ssh_execution": {
                    "identity_file": "/dev/null",
                    "target": {
                        "user": "orbit",
                        "host": "192.0.2.30",
                        "port": 22,
                        "host_keys": [
                            "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAINqVuK+N4gL/sDvGNKnGrYhEtEFnZx9hvH+134PsQgND"
                        ],
                    },
                },
            },
        }
        flow = yaml.safe_load(
            (ROOT / "workflows/workflow-operation-task-publish.yaml").read_text()
        )
        flow["name"] = "task-publication-fixture"
        for job in flow["jobs"]:
            self.assertEqual(job.pop("target"), "orbit-node-209")
            self.assertEqual(job.pop("labels"), {"node": "209"})
            for step in job["steps"]:
                self.assertEqual(step.pop("target"), "orbit-node-209")
                self.assertEqual(step.pop("labels"), {"node": "209"})
                task_inputs = step["task"].get("inputs", {})
                # Keep native CEL/argv intact, replacing only the scratch root.
                for key in ("src", "dst"):
                    if key in task_inputs:
                        task_inputs[key] = task_inputs[key].replace(
                            "/tmp/orbit-task-publish-", str(self.repo / "run-")
                        )
                if "argv" in task_inputs:
                    task_inputs["argv"] = [
                        v.replace("/tmp/orbit-task-publish-", str(self.repo / "run-"))
                        for v in task_inputs["argv"]
                    ]
                for key, val in task_inputs.get("env", {}).items():
                    task_inputs["env"][key] = val.replace(
                        "/tmp/orbit-task-publish-", str(self.repo / "run-")
                    )
        (self.repo / "workflows/workflow-task-publication-fixture.yaml").write_text(
            yaml.safe_dump(flow, sort_keys=False)
        )

    def git(self, root, *args):
        return subprocess.check_output(
            ["git", "-C", str(root), *args], text=True, stderr=subprocess.DEVNULL
        ).strip()

    def run_workflow(self, success=True):
        self.cli("workflow", "validate", "task-publication-fixture")
        raw = self.cli(
            "workflow",
            "run",
            "task-publication-fixture",
            "--stdin",
            input=json.dumps(self.inputs),
            success=success,
        ).stdout
        return json.loads(raw)

    def result(self, run):
        history = json.loads(self.cli("workflow", "history", "get", run["id"]).stdout)
        return json.loads(
            next(
                step for step in history["jobs"][0]["steps"] if step["name"] == "result"
            )["outputs"]["stdout"]
        )

    def test_native_publication_uses_official_ssh_and_records_pr(self):
        run = self.run_workflow()
        self.assertEqual(run["status"], "succeeded")
        self.assertEqual(self.result(run)["pull_request_number"], 12)
        self.assertEqual(
            self.git(self.remote, "rev-parse", "refs/heads/task-1"), self.head
        )
        self.assertEqual(
            [row[0] for row in self.server.calls], ["GET", "POST", "DELETE"]
        )
        self.assertFalse(self.workspace.joinpath("inert").exists())

    def test_push_only_skips_pr_actions(self):
        self.inputs["intent"]["pull_request"] = None
        run = self.run_workflow()
        self.assertIsNone(self.result(run)["pull_request_url"])
        self.assertEqual([row[0] for row in self.server.calls], ["DELETE"])

    def test_moved_workspace_refuses_before_any_remote_push(self):
        self.workspace.joinpath("code").write_text("changed\n")
        run = self.run_workflow(success=False)
        self.assertEqual(self.result(run), {"error_code": "tasks.head_moved"})
        self.assertEqual([row[0] for row in self.server.calls], ["DELETE"])
        self.assertEqual(self.git(self.remote, "for-each-ref"), "")

    def test_lost_pr_reply_is_reconciled_on_fresh_admission(self):
        self.server.lose_reply = True
        run = self.run_workflow(success=False)
        self.assertEqual(self.result(run), {"error_code": "tasks.publish_failed"})
        self.server.lose_reply = False
        self.inputs["operation_id"] = 16
        run = self.run_workflow()
        self.assertEqual(self.result(run)["pull_request_number"], 12)
        self.assertEqual(sum(row[0] == "POST" for row in self.server.calls), 1)
        self.assertEqual(sum(row[0] == "PATCH" for row in self.server.calls), 1)


if __name__ == "__main__":
    import unittest

    unittest.main()
