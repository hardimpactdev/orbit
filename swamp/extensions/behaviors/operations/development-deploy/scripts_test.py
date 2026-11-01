"""Development source refusals and one-command execution against real scratch checkouts."""
import fcntl
import hashlib
import json
import os
import pwd
import re
import signal
import subprocess
import sys
import tempfile
import time
import unittest
from pathlib import Path
from unittest.mock import patch

HERE = Path(__file__).resolve().parent


def script(name):
    body = re.sub(r"^from common import (?:\([^)]*\)|[^\n]*)\n", "", (HERE / ("scripts/" + name + ".py")).read_text(), flags=re.M)
    runtime = (HERE.parent / "instance-deploy/app_step_runtime.ts").read_text()
    cache = re.search(r".raw`(.*?)`;", runtime, re.S)[1]
    program = re.search(r"appStepRuntime = String.raw`(.*?)`;", runtime, re.S)[1].replace("${JSON.stringify(ciCacheImportProgram)}", repr(cache))
    program = program.replace("${projectEnvironmentRuntime}", (HERE.parent / "task-repository/scripts/project_environment.py").read_text())
    body = body.replace('APP_RUNTIME = "__APP_STEP_RUNTIME__"', "APP_RUNTIME = " + repr(program))
    environment = (HERE.parent / "task-repository/scripts/project_environment.py").read_text()
    common = (HERE / "scripts/common.py").read_text().replace("from project_environment import load_project_environment\n", "")
    return environment + "\n" + common + "\n" + body



class DevelopmentScripts(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="development-deploy-", dir=Path.home())
        self.addCleanup(self.temp.cleanup)
        self.repo = Path(self.temp.name) / "checkout"
        self.repo.mkdir()
        self.git("init", "--initial-branch=main")
        self.git("config", "user.name", "Fixture")
        self.git("config", "user.email", "fixture@example.test")
        self.git("remote", "add", "origin", "https://github.com/example/fixture.git")
        (self.repo / "app").write_text("one")
        self.git("add", ".")
        self.git("commit", "-m", "first")
        self.first = self.git("rev-parse", "HEAD")
        (self.repo / "app").write_text("two")
        self.git("commit", "-am", "second")
        self.second = self.git("rev-parse", "HEAD")
        self.git("update-ref", "refs/remotes/origin/main", self.second)
        self.git("reset", "--hard", self.first)
        self.input = {"operation_id": 1, "run_id": "225438ec-dc32-42e1-b05f-a874329d8e58", "intent": {
            "commit": None, "github_mint": False, "deployment": {"variant": "development", "node_user": pwd.getpwuid(os.geteuid()).pw_name,
                "project_id": 1, "repository": "https://github.com/example/fixture.git", "branch": "main", "home": str(self.repo), "protocols": ["https"], "steps": []}}}

    def git(self, *args):
        return subprocess.check_output(["git", "-C", str(self.repo), *args], stderr=subprocess.DEVNULL, text=True).strip()

    def action(self, mode, command="", required=True, seconds=2, digest=None):
        self.input["intent"]["deployment"]["steps"] = [{"name": "proof", "required": required, "timeout_seconds": seconds,
            "command_digest": digest or hashlib.sha256(command.encode()).hexdigest()}]
        argv = [sys.executable, "-c", script("app" if mode == "app" else "source"), mode, json.dumps(self.input), "", "null", "0"]
        result = subprocess.run(argv, input=command, capture_output=True, text=True, timeout=15)
        self.assertEqual(result.returncode, 0, result.stderr)
        return json.loads(result.stdout)

    def test_checkout_and_final_inspect_use_the_exact_captured_branch_target(self):
        self.assertEqual(self.action("check"), {"ready": True, "commit": self.second})
        self.assertTrue(self.action("checkout")["ready"])
        self.assertEqual((self.repo / "app").read_text(), "two")
        self.assertEqual(self.action("inspect"), {"ready": True, "commit": self.second})

    def test_dirty_divergent_origin_and_pin_refusals_do_not_checkout(self):
        (self.repo / "app").write_text("dirty")
        self.assertEqual(self.action("checkout")["error_code"], "deployment.checkout_dirty")
        self.git("reset", "--hard", self.first)
        (self.repo / "app").write_text("divergent")
        self.git("commit", "-am", "divergent")
        self.assertEqual(self.action("checkout")["error_code"], "deployment.branch_diverged")
        self.git("reset", "--hard", self.first)
        self.input["intent"]["commit"] = "f" * 40
        self.assertFalse(self.action("check")["ready"])
        self.input["intent"]["commit"] = None
        self.git("remote", "set-url", "origin", "https://example.test/foreign")
        self.assertEqual(self.action("checkout")["error_code"], "deployment.identity_changed")
        self.assertEqual(self.git("rev-parse", "HEAD"), self.first)

    def test_directory_lock_and_detached_or_changed_branch_refuse(self):
        directory = os.open(self.repo, os.O_RDONLY)
        try:
            fcntl.flock(directory, fcntl.LOCK_EX | fcntl.LOCK_NB)
            self.assertEqual(self.action("app", "true")["error_code"], "instance.lifecycle_busy")
        finally:
            os.close(directory)
        self.git("checkout", "--detach", self.second)
        self.assertEqual(self.action("inspect")["error_code"], "deployment.branch_changed")
        self.git("checkout", "-b", "foreign")
        self.assertEqual(self.action("inspect")["error_code"], "deployment.branch_changed")

    def test_clean_preserves_top_level_dependency_and_tracked_parent_content(self):
        self.git("checkout", "--quiet", "-B", "main", self.second)
        (self.repo / "cache").mkdir()
        (self.repo / "cache/data").write_text("preserve")
        self.assertTrue(self.action("clean")["ready"])
        self.assertTrue((self.repo / "cache/data").exists())

    def test_app_uses_private_stdin_and_target_environment_without_output(self):
        (self.repo / ".env").write_text('TOKEN="private-environment-sentinel"\n')
        result = self.action("app", 'test "$TOKEN" = private-environment-sentinel\nprintf private-output-sentinel\nprintf ok > proof')
        self.assertEqual(result, {"ready": True, "exit_code": 0, "timed_out": False, "warning": False})
        self.assertEqual((self.repo / "proof").read_text(), "ok")

    def test_required_optional_digest_and_deadline_outcomes(self):
        self.assertEqual(self.action("app", "exit 7")["error_code"], "deployment.step_failed")
        optional = self.action("app", "exit 7", required=False)
        self.assertTrue(optional["ready"] and optional["warning"])
        self.assertEqual(self.action("app", "touch unexpected", digest="a" * 64)["error_code"], "instance.step_not_admitted")
        self.assertFalse((self.repo / "unexpected").exists())
        self.assertTrue(self.action("app", "sleep 4", seconds=1)["timed_out"])

    def test_vp_scope_follows_the_legacy_owner_rule(self):
        home = Path(self.temp.name) / "home"
        (home / ".vite-plus/bin").mkdir(parents=True)
        (home / ".vite-plus/bin/vp").write_text("#!/bin/sh\n")
        (home / ".vite-plus/bin/vp").chmod(0o755)
        (home / ".vite-plus/bin").chmod(0o775)
        account = pwd.getpwuid(os.geteuid())
        namespace = self.definitions("vp")
        namespace["ACCOUNT"] = type("A", (), {"pw_uid": account.pw_uid, "pw_gid": account.pw_gid, "pw_dir": str(home)})()
        self.assertEqual(namespace["scope"](), home / ".vite-plus")
        (home / ".vite-plus/bin/vp").chmod(0o644)
        with self.assertRaisesRegex(namespace["Refused"], "tool.vp_scope_conflict"):
            namespace["scope"]()
        (home / ".vite-plus/bin/vp").chmod(0o755)
        namespace["ACCOUNT"] = type("A", (), {"pw_uid": account.pw_uid, "pw_gid": account.pw_gid + 1, "pw_dir": str(home)})()
        with self.assertRaisesRegex(namespace["Refused"], "tool.vp_scope_conflict"):
            namespace["scope"]()

    def definitions(self, name):
        namespace = {}
        with patch.object(sys, "argv", ["fixture", "unused", json.dumps(self.input)]):
            exec(script(name).replace("\nemit(action)", ""), namespace)
        return namespace

    def test_vp_npm_pins_leave_target_with_no_private_manifest_or_registry_fields(self):
        (self.repo / "package.json").write_text(json.dumps({"devDependencies": {"vite-plus": "^1.4.0"},
            "packageManager": "npm@11.12.0", "engines": {"node": ">=24"}, "scripts": {"private": "private-program"}}))
        (self.repo / ".node-version").write_text("v24.22.0\n")
        (self.repo / "package-lock.json").write_text(json.dumps({"packages": {"node_modules/vite-plus": {
            "version": "1.4.0", "resolved": "https://private:password@registry.invalid/pkg", "engines": {"node": ">=22"}}}}))
        pins, constraints = self.definitions("vp")["project"](Path("/opt/orbit/vite-plus"))
        self.assertEqual(pins["node_version"], "24.22.0")
        self.assertEqual(pins["package_manager"], "npm")
        self.assertEqual(constraints, [["node", ">=22"], ["node", ">=24"]])
        self.assertNotIn("private", json.dumps([pins, constraints]))
        (self.repo / ".node-version").write_text("26.0.0-rc.1\n")
        package = json.loads((self.repo / "package.json").read_text())
        package["engines"]["node"] = ">=26.0.0-rc.1"
        (self.repo / "package.json").write_text(json.dumps(package))
        self.assertIn(["node", ">=26.0.0-rc.1"], self.definitions("vp")["project"](Path("/opt/orbit/vite-plus"))[1])

    def test_vp_pnpm_selects_root_importer_and_locked_engines_not_unrelated_versions(self):
        (self.repo / "package.json").write_text(json.dumps({"devDependencies": {"vite-plus": "^1.4.0"}}))
        (self.repo / ".nvmrc").write_text("24.22.0\n")
        (self.repo / "pnpm-lock.yaml").write_text("""lockfileVersion: '9.0'
packageManager: 'pnpm@10.5.0'
importers:
  .:
    devDependencies:
      vite-plus:
        specifier: ^1.4.0
        version: 1.4.0(typescript@5.9.3)
  other:
    dependencies:
      vite-plus:
        version: 2.0.0
packages:
  vite-plus@1.4.0:
    engines:
      node: '>=22'
  vite-plus@2.0.0:
    engines:
      node: '>=30'
""")
        pins, constraints = self.definitions("vp")["project"](Path("/opt/orbit/vite-plus"))
        self.assertEqual((pins["vp_version"], pins["package_manager_version"]), ("1.4.0", "10.5.0"))
        self.assertEqual(constraints, [["node", ">=22"]])
        path = self.repo / "pnpm-lock.yaml"
        path.write_text(path.read_text().replace("engines:\n      node: '>=22'", "engines: {node: '>=22'}"))
        self.assertEqual(self.definitions("vp")["project"](Path("/opt/orbit/vite-plus"))[1], [["node", ">=22"]])
        lockfile = self.repo / "pnpm-lock.yaml"
        lockfile.write_text(lockfile.read_text().replace("version: 1.4.0(typescript@5.9.3)", "version: link:packages/vite"))
        workspace = self.repo / "packages/vite"
        workspace.mkdir(parents=True)
        (workspace / "package.json").write_text(json.dumps({"name": "vite-plus", "version": "1.4.0", "engines": {"node": ">=22"}}))
        self.assertEqual(self.definitions("vp")["project"](Path("/opt/orbit/vite-plus"))[0]["vp_version"], "1.4.0")

    def test_fetch_classifies_public_github_without_mint_and_keeps_private_token_in_environment_only(self):
        import io
        import types
        import urllib.error
        namespace = self.definitions("source")
        namespace["INTENT"].update(github_mint=True, github_repository="example/fixture")
        calls, fetches = [], []

        class Reply(io.BytesIO):
            def __init__(self, value):
                super().__init__(json.dumps(value).encode())

        class Opener:
            def __init__(self, private):
                self.private = private

            def open(self, request, **_):
                calls.append(request.full_url)
                if request.full_url.startswith("https://api.github.com/"):
                    if self.private:
                        raise urllib.error.HTTPError(request.full_url, 404, "private", {}, None)
                    return Reply({"full_name": "example/fixture", "private": False})
                return Reply({"data": {"github": {"repository": "example/fixture", "permissions": {"contents": "read"}, "token": "ephemeral-private-fixture"}}})

        def git(*args, **kwargs):
            fetches.append((args, kwargs))
            return types.SimpleNamespace(returncode=0)

        namespace["git"] = git
        with patch("urllib.request.build_opener", return_value=Opener(False)):
            namespace["fetch"]()
        self.assertEqual(len(calls), 1)
        self.assertNotIn("GIT_CONFIG_COUNT", fetches[-1][1]["env"])
        with patch("urllib.request.build_opener", return_value=Opener(True)), patch("ssl.create_default_context", return_value=object()) as context:
            namespace["fetch"]()
        context.assert_called_once_with(cafile="/usr/local/share/ca-certificates/orbit-root.crt")
        self.assertEqual(calls[-1], "https://gateway.orbit/api/v1/direct-operations/1/github-token")
        self.assertNotIn("ephemeral-private-fixture", repr(fetches[-1][0]))
        self.assertEqual(fetches[-1][1]["env"]["GIT_CONFIG_COUNT"], "3")

    def test_success_reaps_an_escaped_child_before_returning(self):
        result = self.action("app", "python3 -c 'import os,signal,time; os.setsid(); signal.signal(signal.SIGTERM,signal.SIG_IGN); open(\"child.pid\",\"w\").write(str(os.getpid())); time.sleep(30)' &\nwhile [ ! -s child.pid ]; do sleep .01; done")
        self.assertTrue(result["ready"])
        child = int((self.repo / "child.pid").read_text())
        self.assertFalse(Path("/proc/" + str(child)).exists())

    def test_vp_cli_installs_exact_pins_with_target_registry_and_verifies_the_result(self):
        namespace = self.definitions("vp")
        store = self.repo / "vp-store"
        (store / "bin").mkdir(parents=True)
        binary = store / "bin/vp"
        binary.write_text("#!" + sys.executable + "\n" + "import json,os,sys\nfrom pathlib import Path\n"
            + "assert os.environ['TOKEN']=='private-registry-value'\n"
            + "assert os.environ['NPM_CONFIG_USERCONFIG']==str(Path.cwd()/'.npmrc')\n"
            + "Path('vp-argv.json').write_text(json.dumps(sys.argv[1:]))\n"
            + "print('private-registry-value')\n")
        binary.chmod(0o700)
        (self.repo / ".env").write_text('TOKEN="private-registry-value"\n')
        (self.repo / ".npmrc").write_text('//registry.invalid/:_authToken=${TOKEN}\n')
        namespace["scope"] = lambda: store
        namespace["MODE"] = "vp-install"
        namespace["INPUT"]["runtime"] = {"store": str(store), "vp_version": "1.4.0", "node_version": "24.22.0",
            "package_manager": "npm", "package_manager_version": "11.12.0", "package_manager_hash": "sha512.aabb"}
        self.assertEqual(namespace["action"](), {})
        self.assertEqual(json.loads((self.repo / "vp-argv.json").read_text()), ["env", "install", "node@24.22.0", "npm@11.12.0+sha512.aabb"])
        node = store / "js_runtime/node/24.22.0/bin/node"
        node.parent.mkdir(parents=True)
        node.write_text("synthetic executable")
        node.chmod(0o700)
        manager = store / "package_manager/npm/11.12.0/npm/package.json"
        manager.parent.mkdir(parents=True)
        manager.write_text(json.dumps({"version": "11.12.0"}))
        namespace["MODE"] = "vp-verify"
        self.assertEqual(namespace["action"](), {})
        manager.write_text(json.dumps({"version": "12.0.0"}))
        with self.assertRaises(namespace["Refused"]):
            namespace["action"]()

    def test_cancellation_forwards_to_existing_runtime_and_reaps_escaped_children(self):
        import shlex
        child = "import os,signal,time;from pathlib import Path;os.setsid();signal.signal(signal.SIGTERM,signal.SIG_IGN);Path('cancel.pid').write_text(str(os.getpid()));time.sleep(30)"
        command = "python3 -c " + shlex.quote(child) + " &\nwhile [ ! -s cancel.pid ]; do sleep .01; done\nsleep 30"
        self.input["intent"]["deployment"]["steps"] = [{"name": "cancel", "required": True, "timeout_seconds": 20,
            "command_digest": hashlib.sha256(command.encode()).hexdigest()}]
        process = subprocess.Popen([sys.executable, "-c", script("app"), "app", json.dumps(self.input), "", "null", "0"],
            stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        self.addCleanup(lambda: process.kill() if process.poll() is None else None)
        process.stdin.write(command)
        process.stdin.close()
        process.stdin = None
        deadline = time.monotonic() + 5
        while not (self.repo / "cancel.pid").exists() and time.monotonic() < deadline:
            time.sleep(.01)
        self.assertTrue((self.repo / "cancel.pid").exists())
        child_pid = int((self.repo / "cancel.pid").read_text())
        process.send_signal(signal.SIGTERM)
        stdout, stderr = process.communicate(timeout=5)
        self.assertEqual(process.returncode, 0, stderr)
        self.assertEqual(json.loads(stdout)["exit_code"], 130)
        self.assertFalse(Path("/proc/" + str(child_pid)).exists())
        self.assertTrue(self.action("app", "true")["ready"])


if __name__ == "__main__":
    unittest.main()
