"""Official native scheduler with disposable Git/GitHub and local SSH/SCP fixtures.

The fixture tools stand in for the hosts: `ssh` and `scp` run locally, `git`
sends the GitHub remote to a local bare repository, and `gh` and `curl` send
their requests to a local HTTP server. Every step argv stays as written.
"""

import hashlib
import importlib.util
import json
import os
import shutil
import subprocess
import sys
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[4]
TOKEN = "synthetic-publication-token-no-real-credential"
spec = importlib.util.spec_from_file_location(
    "native_harness", ROOT / "tests/native_harness.py"
)
harness = importlib.util.module_from_spec(spec)
spec.loader.exec_module(harness)


class GitHub(BaseHTTPRequestHandler):
    def log_message(self, *_args):
        pass

    def do_GET(self):
        self.respond("GET", self.server.pulls)

    def do_DELETE(self):
        self.respond("DELETE", None)

    def do_POST(self):
        self.server.pulls = [self.server.pull]
        self.respond("POST", {"number": 12})

    def do_PATCH(self):
        self.respond("PATCH", {"number": 12})

    def respond(self, method, value):
        if self.headers.get("Authorization") != "Bearer " + TOKEN:
            self.send_response(401)
            self.end_headers()
            return
        raw = self.rfile.read(int(self.headers.get("Content-Length", 0)))
        self.server.calls.append((method, self.path, json.loads(raw) if raw else None))
        if method == "POST" and self.server.lose_reply:
            self.close_connection = True
            return
        self.send_response(204 if method == "DELETE" else 200)
        self.end_headers()
        if value is not None:
            self.wfile.write(json.dumps(value).encode())


def tool(path, source):
    path.write_text("#!" + sys.executable + "\n" + source)
    path.chmod(0o755)


class NativePublicationFixture(harness.NativeTestCase):
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
        for name in ("publish.ts", "check.ts"):
            shutil.copy(ROOT / "extensions/behaviors/operations/tasks" / name, tasks)
        # The check data builds command argv with the shared lifecycle and program rules.
        for name in (
            "_lib/repository_analysis.ts",
            "_lib/public_output.ts",
            "operations/instance-deploy/app_step_runtime.ts",
            "operations/instance-deploy/project_environment.ts",
        ):
            path = self.repo / "extensions/behaviors" / name
            path.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy(ROOT / "extensions/behaviors" / name, path)
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
        api = "http://127.0.0.1:" + str(self.server.server_port)
        tools = self.repo / "tools"
        tools.mkdir()
        tool(
            tools / "ssh",
            'import shlex,subprocess,sys\nif "-M" in sys.argv or "-O" in sys.argv:raise SystemExit(0)\nr=subprocess.run(shlex.split(sys.argv[-1]),input=sys.stdin.buffer.read(),capture_output=True)\nsys.stdout.buffer.write(r.stdout);sys.stderr.buffer.write(r.stderr)\nraise SystemExit(r.returncode)\n',
        )
        tool(
            tools / "scp",
            'import shutil,sys\nfrom pathlib import Path\ndef local(v):return v.split(":",1)[1] if ":" in v else v\nsrc=Path(local(sys.argv[-2]));dst=Path(local(sys.argv[-1]))\nif src.is_dir():shutil.copytree(src,dst/src.name,dirs_exist_ok=True)\nelse:shutil.copy2(src,dst)\n',
        )
        # Git asks `gh auth git-credential` for the token; it is never in argv.
        digest = hashlib.sha256(TOKEN.encode()).hexdigest()
        real_git = shutil.which("git")
        tool(
            tools / "git",
            f"""import hashlib,os,subprocess,sys
url="https://github.com/acme/shop.git"
args=sys.argv[1:]
if url in args:
    fill=subprocess.run([{real_git!r},"credential","fill"],input="protocol=https\\nhost=github.com\\n\\n",capture_output=True,text=True)
    password=dict(line.split("=",1) for line in fill.stdout.splitlines() if "=" in line).get("password","")
    if hashlib.sha256(password.encode()).hexdigest()!={digest!r} or password in " ".join(args):
        raise SystemExit(97)
    args=[{str(self.remote)!r} if a==url else a for a in args]
    os.environ["GIT_ALLOW_PROTOCOL"]="file"
os.execv({real_git!r},["git",*args])
""",
        )
        tool(
            tools / "gh",
            f"""import os,sys,urllib.parse,urllib.request
args=sys.argv[1:]
assert os.environ["GH_TOKEN"] not in " ".join(args)
if args[:2]==["auth","git-credential"]:
    sys.stdin.read()
    print("protocol=https\\nhost=github.com\\nusername=x-access-token\\npassword="+os.environ["GH_TOKEN"])
    raise SystemExit(0)
assert args[0]=="api"
method,fields,body,path="GET",[],None,None
i=1
while i<len(args):
    if args[i]=="--method":method=args[i+1];i+=2
    elif args[i]=="-f":fields.append(tuple(args[i+1].split("=",1)));i+=2
    elif args[i]=="--input":body=sys.stdin.buffer.read();i+=2
    else:path=args[i];i+=1
url={api!r}+"/"+path.lstrip("/")
if fields:url+=("&" if "?" in url else "?")+urllib.parse.urlencode(fields)
request=urllib.request.Request(url,method=method,data=body,headers={{"Authorization":"Bearer "+os.environ["GH_TOKEN"]}})
try:
    with urllib.request.urlopen(request,timeout=30) as response:sys.stdout.write(response.read().decode())
except Exception as error:
    sys.stderr.write(str(error));raise SystemExit(1)
""",
        )
        tool(
            tools / "curl",
            f"""import sys,urllib.request
args=sys.argv[1:]
url=args[-1].replace("https://gateway.orbit",{api!r})
request=urllib.request.Request(url,method="POST",data=args[args.index("--data")+1].encode())
try:
    with urllib.request.urlopen(request,timeout=30) as response:sys.stdout.write(response.read().decode())
except Exception as error:
    sys.stderr.write(str(error));raise SystemExit(22)
""",
        )
        self.native_env.update(PATH=str(tools) + ":" + os.environ["PATH"])
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
        flow = self.copy_flow("workflow-operation-task-publish.yaml")
        flow["name"] = "task-publication-fixture"
        (self.repo / "workflows/workflow-task-publication-fixture.yaml").write_text(
            yaml.safe_dump(flow, sort_keys=False)
        )
        # Each chart step of the publication is a child workflow.
        for child in ("check", "export", "import", "push", "pull-request"):
            name = "workflow-task-publication-" + child + ".yaml"
            (self.repo / "workflows" / name).write_text(
                yaml.safe_dump(self.copy_flow(name), sort_keys=False)
            )

    def copy_flow(self, name):
        """The workflow as written, with only its scratch roots under the fixture."""
        flow = yaml.safe_load((ROOT / "workflows" / name).read_text())

        def rewrite(value):
            if isinstance(value, str):
                return value.replace(
                    "/tmp/orbit-task-publish-", str(self.repo / "publish-")
                ).replace("/tmp/orbit-task-check-", str(self.repo / "check-"))
            if isinstance(value, list):
                return [rewrite(item) for item in value]
            if isinstance(value, dict):
                return {k: rewrite(v) for k, v in value.items()}
            return value

        flow = rewrite(flow)
        for job in flow["jobs"]:
            self.assertEqual(job.pop("labels"), {"role": "swamp"})
            for step in job["steps"]:
                self.assertEqual(step.pop("labels"), {"role": "swamp"})
        return flow

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
        """The receipt as the Gateway reads it: the last step's stdout, else the PR step's."""
        history = json.loads(self.cli("workflow", "history", "get", run["id"]).stdout)
        steps = {step["name"]: step for step in history["jobs"][0]["steps"]}
        last = steps["Revoke the token"]
        if last["status"] == "succeeded":
            return json.loads(last["outputs"]["stdout"])
        return json.loads(
            steps["Open or update the PR"]["outputs"]["shape_result"]["result_json"]
        )

    def scratch(self):
        return sorted(path.name for path in self.repo.glob("publish-*"))


class NativePublication(NativePublicationFixture):
    def test_native_publication_pushes_without_force_and_records_pr(self):
        run = self.run_workflow()
        self.assertEqual(run["status"], "succeeded")
        self.assertEqual(self.result(run)["pull_request_number"], 12)
        self.assertEqual(
            self.git(self.remote, "rev-parse", "refs/heads/task-1"), self.head
        )
        self.assertEqual(
            [row[0] for row in self.server.calls], ["GET", "POST", "DELETE"]
        )
        self.assertIn("head=acme%3Atask-1", self.server.calls[0][1])
        self.assertEqual(
            self.server.calls[1][2],
            {
                **self.inputs["intent"]["pull_request"],
                "head": "task-1",
                "base": "main",
            },
        )
        self.assertFalse(self.workspace.joinpath("inert").exists())
        self.assertEqual(self.scratch(), [])

    def test_push_only_skips_pr_actions(self):
        self.inputs["intent"]["pull_request"] = None
        run = self.run_workflow()
        self.assertIsNone(self.result(run)["pull_request_url"])
        self.assertEqual([row[0] for row in self.server.calls], ["DELETE"])

    def test_identical_tip_pushes_nothing_and_updates_the_open_pr(self):
        self.run_workflow()
        self.inputs["operation_id"] = 16
        run = self.run_workflow()
        self.assertEqual(self.result(run)["pull_request_number"], 12)
        self.assertEqual(
            [row[0] for row in self.server.calls],
            ["GET", "POST", "DELETE", "GET", "PATCH", "DELETE"],
        )
        self.assertEqual(self.server.calls[4][2], self.inputs["intent"]["pull_request"])

    def test_divergent_tip_is_not_forced(self):
        self.run_workflow()
        self.git(self.workspace, "checkout", "-q", "--orphan", "other")
        self.git(self.workspace, "commit", "--allow-empty", "-qm", "Other root")
        self.inputs["intent"]["commit"] = self.git(self.workspace, "rev-parse", "HEAD")
        self.inputs["intent"]["tree"] = self.git(
            self.workspace, "rev-parse", "HEAD^{tree}"
        )
        self.inputs["operation_id"] = 16
        run = self.run_workflow(success=False)
        self.assertEqual(self.result(run), {"error_code": "tasks.not_fast_forward"})
        self.assertEqual(
            self.git(self.remote, "rev-parse", "refs/heads/task-1"), self.head
        )

    def test_workflow_permission_refusal_keeps_its_code(self):
        hook = self.remote / "hooks/pre-receive"
        hook.write_text(
            "#!/bin/sh\necho 'refusing to allow a GitHub App to create or update workflow .github/workflows/ci.yml' >&2\nexit 1\n"
        )
        hook.chmod(0o755)
        run = self.run_workflow(success=False)
        self.assertEqual(self.result(run), {"error_code": "tasks.workflows_permission"})
        self.assertEqual([row[0] for row in self.server.calls], ["DELETE"])

    def test_workspace_git_config_and_hooks_never_run_with_the_token(self):
        marker = self.repo / "untrusted-ran"
        hooks = self.repo / "hooks"
        hooks.mkdir()
        hook = hooks / "pre-push"
        hook.write_text("#!/bin/sh\ntouch '" + str(marker) + "'\nexit 1\n")
        hook.chmod(0o755)
        self.git(self.workspace, "config", "core.hooksPath", str(hooks))
        self.git(self.workspace, "config", "credential.helper", "!false")
        run = self.run_workflow()
        self.assertEqual(run["status"], "succeeded")
        self.assertFalse(marker.exists())

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
