"""Two real Swamp workers, isolated state, fixture commands, no network installs."""
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
import unittest
import urllib.request

REPO = Path(__file__).resolve().parents[1]


class MaintenanceWorkflowTest(unittest.TestCase):
    def test_remote_routine_detect_package_summary_and_failure(self):
        with tempfile.TemporaryDirectory(prefix="maintenance-workflow-") as temp:
            root = Path(temp)
            shutil.copytree(REPO / "extensions", root / "extensions")
            # Replace only public registry metadata in this disposable fixture.
            batch_module = root / "extensions/behaviors/composer-maintenance/_lib/batch.ts"
            source = batch_module.read_text()
            start, end = source.index("export async function releases("), source.index("function select(")
            batch_module.write_text(source[:start] + "export async function releases(_c: {package:string}) { return ['2.0.0','2.0.1','2.0.2'].map(version=>({version,publishedAt:'2020-01-01T00:00:00Z'})); }\n" + source[end:])

            (root / "workflows").mkdir()
            shutil.copyfile(REPO / "workflows/workflow-maintenance-worker.yaml",
                            root / "workflows/workflow-maintenance-worker.yaml")
            workflow = root / "workflows/workflow-maintenance-worker.yaml"
            workflow.write_text(workflow.read_text().replace("queueTimeout: 120", "queueTimeout: 1") + """
      - name: environment
        dependsOn:
          - step: before
            condition: { type: succeeded }
        task:
          type: model_method
          modelType: '@fixture/enrollment-probe'
          modelName: environment-probe
          methodName: environment
          inputs: {}
""")
            shutil.copyfile(REPO / ".swamp.yaml", root / ".swamp.yaml")
            probe = (REPO / "tests/fixtures/maintenance-worker/enrollment_probe.ts").read_text().replace('"../../../extensions/behaviors/_lib/swamp_maintenance.ts"', '"./_lib/swamp_maintenance.ts"')
            (root / "extensions/behaviors/enrollment_probe.ts").write_text(probe)
            work = root / "checkout"
            work.mkdir()
            (work / "composer.json").write_text(json.dumps({"name": "fixture/app", "require": {"vendor/pkg": "^1.0"}}))
            (work / "composer.lock").write_text(json.dumps({"packages": [{"name": "vendor/pkg", "version": "1.0.0"}], "packages-dev": []}))
            (work / "vet.json").write_text('{"minimum-release-age":7}')
            (work / "package.json").write_text(json.dumps({"name": "fixture", "dependencies": {"fixture-pkg": "^1.0.0"}}))
            (work / "package-lock.json").write_text(json.dumps({"lockfileVersion": 3, "packages": {"": {"dependencies": {"fixture-pkg": "^1.0.0"}}, "node_modules/fixture-pkg": {"version": "1.0.0"}}}))
            bins = root / "bin"
            bins.mkdir()
            # Only the worker has this command on PATH. Serve cannot execute it.
            (bins / "composer").write_text('''#!/usr/bin/env python3
import json,sys,pathlib
if pathlib.Path('fail').exists(): sys.exit(23)
if sys.argv[1]=='update':
 manifest=json.loads(pathlib.Path('composer.json').read_text())
 if pathlib.Path('fail-security-update').exists(): sys.exit(23)
 version=manifest['require']['vendor/pkg'].removeprefix('^')
 if version=='1.0': version='1.1.0'
 pathlib.Path('composer.lock').write_text(json.dumps({'packages':[{'name':'vendor/pkg','version':version}],'packages-dev':[]}))
elif sys.argv[1]=='require':
 name,version=sys.argv[2].split(':',1)
 manifest=json.loads(pathlib.Path('composer.json').read_text());manifest['require'][name]=version
 pathlib.Path('composer.json').write_text(json.dumps(manifest))
elif sys.argv[1]=='audit':
 fixed=pathlib.Path('security-target')
 version=json.loads(pathlib.Path('composer.lock').read_text())['packages'][0]['version']
 advisories={'vendor/pkg':[{'advisoryId':'PKSA-fixture-one','title':'fixture security','link':'https://example.test/advisory','affectedVersions':'<'+fixed.read_text()}]} if fixed.exists() and version!=fixed.read_text() else []
 print(json.dumps({'advisories':advisories}))
elif sys.argv[1]=='outdated':print('{"locked":[]}')
else:sys.exit(64)
''')
            (bins / "composer").chmod(0o755)
            (bins / "npm").write_text('''#!/usr/bin/env python3
import json,sys,pathlib
if sys.argv[1]=='update':
 lock=json.loads(pathlib.Path('package-lock.json').read_text());lock['packages']['node_modules/fixture-pkg']['version']='1.1.0'
 pathlib.Path('package-lock.json').write_text(json.dumps(lock))
elif sys.argv[1]=='outdated': print('{}')
elif sys.argv[1]=='audit': print('{"auditReportVersion":2,"vulnerabilities":{}}')
else:sys.exit(64)
''')
            (bins / "npm").chmod(0o755)
            # Each git invocation records which worker executed it, including snapshots
            # and summary steps that do not invoke a package manager.
            real_git = shutil.which("git")
            impostor_bins = root / "impostor-bin"
            impostor_bins.mkdir()
            for directory, identity in [(bins, "task-group-7-1"), (impostor_bins, "task-group-8-1")]:
                trace = root / f"{identity}.trace"
                wrapper = directory / "git"
                wrapper.write_text("#!/usr/bin/env python3\nimport os,sys,pathlib\n"
                                   + f"if pathlib.Path.cwd()==pathlib.Path({str(work)!r}): pathlib.Path({str(trace)!r}).touch()\n"
                                   + f"os.execv({real_git!r},[{real_git!r}]+sys.argv[1:])\n")
                wrapper.chmod(0o755)
            env = dict(os.environ, SWAMP_NO_UPDATE_CHECK="1", SWAMP_NO_TELEMETRY="1")

            for key in ["SWAMP_SERVER_TOKEN", "SWAMP_SERVER_TOKEN_FILE", "SWAMP_SERVE_URL", "SWAMP_SERVER_URL"]:
                env.pop(key, None)

            def execute(args, stdin=None, override_env=None):
                child_env = override_env or env
                reader = None
                # The pinned client reads token files, not SWAMP_SERVER_TOKEN.
                # Pass authentication through an anonymous pipe; never argv or disk.
                if "--server" in args and child_env.get("SWAMP_SERVER_TOKEN"):
                    reader, writer = os.pipe()
                    os.write(writer, child_env["SWAMP_SERVER_TOKEN"].encode())
                    os.close(writer)
                    args = [*args, "--token-file", f"/dev/fd/{reader}"]
                try:
                    return subprocess.run(args, cwd=root, env=child_env, input=stdin,
                                          text=True, capture_output=True, timeout=90,
                                          pass_fds=() if reader is None else (reader,))
                finally:
                    if reader is not None:
                        os.close(reader)

            def command(*args, stdin=None, override_env=None):
                result = execute(args, stdin, override_env)
                # Never include command output: token creation is one of these calls.
                self.assertEqual(result.returncode, 0, f"{args[:3]} failed ({result.returncode})" + (result.stdout[-5000:] + result.stderr[-1000:] if args[1:3] not in [("worker", "token"), ("access", "token")] else ""))
                return result.stdout

            command("git", "init", str(work))
            command("git", "-C", str(work), "add", ".")
            command("git", "-C", str(work), "-c", "user.name=Fixture", "-c",
                    "user.email=fixture@example.test", "commit", "-m", "base")
            base = command("git", "-C", str(work), "rev-parse", "HEAD").strip()
            command("swamp", "behavior", "create", "@fixture/enrollment-probe", "enrollment-probe", "--json")
            command("swamp", "workflow", "validate", "maintenance-worker")
            command("swamp", "vault", "create", "local_encryption", "default", "--json")
            command("swamp", "access", "token", "mint", "fixture-admin", "--principal", "user:nckrtl", "--duration", "10m", "--json")
            admin = json.loads(command("swamp", "access", "token", "reveal", "fixture-admin", "--yes", "--json"))["token"]
            env["SWAMP_SERVER_TOKEN"] = admin
            del admin
            with socket.socket() as sock:
                sock.bind(("127.0.0.1", 0))
                port = sock.getsockname()[1]
            url = f"ws://127.0.0.1:{port}"
            with open(root / "serve.log", "w") as log, open(root / "worker.log", "w") as wlog, open(root / "impostor.log", "w") as ilog:
                serve = subprocess.Popen(["swamp", "serve", "--port", str(port), "--host", "127.0.0.1", "--auth-mode", "token", "--admins", "user:nckrtl", "--dispatch-env-allow=,"], cwd=root, env=dict(env, MAINTENANCE_SERVE_CANARY="serve"), stdout=log, stderr=log)
                worker = impostor = None
                try:
                    for _ in range(100):
                        try:
                            with urllib.request.urlopen(f"http://127.0.0.1:{port}/ready", timeout=1):
                                break
                        except OSError:
                            self.assertIsNone(serve.poll(), "serve exited: " + (root / "serve.log").read_text()[-2000:])
                            time.sleep(0.1)
                    else:
                        self.fail("serve did not become ready")
                    # Exercise the production enrollment adapter inside a real serve run.
                    command("swamp", "model", "method", "run", "enrollment-probe", "verify", "--server", url, "--json")
                    # A different group's credential can claim group=7. Enroll it first
                    # so label placement would choose it; named placement must exclude it.
                    impostor_token = json.loads(command("swamp", "worker", "token", "create", "task-group-8-1", "--duration", "10m", "--server", url, "--json"))["token"]
                    command("swamp", "model", "method", "run", "enrollment-probe", "server", "--input", "name=task-group-8-1", "--server", url, "--json")
                    impostor_server = json.loads(command("swamp", "access", "token", "reveal", "task-group-8-1-access", "--yes", "--json"))["token"]
                    impostor = subprocess.Popen(["swamp", "worker", "connect", url, "--label", "group=7", "--cache-dir", str(root / "impostor-cache"), "--no-reconnect"], cwd=root, env=dict(env, MAINTENANCE_WORKER_CANARY="worker", SWAMP_WORKER_TOKEN=impostor_token, SWAMP_SERVER_TOKEN=impostor_server, PATH=str(impostor_bins) + ":" + str(bins) + ":" + env["PATH"]), stdin=subprocess.DEVNULL, stdout=ilog, stderr=ilog)
                    del impostor_token, impostor_server
                    for _ in range(100):
                        data = json.loads(command("swamp", "worker", "list", "--server", url, "--json"))
                        if any(w["name"] == "task-group-8-1" for w in data.get("workers", [])):
                            break
                        self.assertIsNone(impostor.poll(), "impostor worker exited")
                        time.sleep(0.1)
                    else:
                        self.fail("impostor worker did not enroll")
                    # With only the impostor online, named placement must time out
                    # without executing even a snapshot on that worker.
                    empty = {"cwd": str(work), "paths": [], "policy": {}, "captureFailure": True}
                    isolated = dict(key="tg-7.isolation", name="tg-7-isolation", group="7", worker="task-group-7-1", cwd=str(work), mode="detect", method="detect", composerPaths=[], jsPaths=[], composer=empty, js=empty, composerCommon=empty, jsCommon=empty, summary=dict(empty, base=base))
                    started = json.loads(command("swamp", "workflow", "run", "maintenance-worker", "--detach", "--server", url, "--stdin", "--json", stdin=json.dumps(isolated)))
                    for _ in range(100):
                        isolated_run = json.loads(command("swamp", "workflow", "history", "get", started["runId"], "--server", url, "--json"))
                        if isolated_run["status"] not in ["pending", "running"]:
                            break
                        time.sleep(0.1)
                    self.assertFalse((root / "task-group-8-1.trace").exists(), "a different group's worker must never receive group-7 steps despite its forged label")
                    self.assertEqual(isolated_run["status"], "failed", "a missing named worker must fail rather than use a forged label")
                    token = json.loads(command("swamp", "worker", "token", "create", "task-group-7-1", "--duration", "10m", "--server", url, "--json"))["token"]
                    command("swamp", "model", "method", "run", "enrollment-probe", "server", "--input", "name=task-group-7-1", "--server", url, "--json")
                    server_token = json.loads(command("swamp", "access", "token", "reveal", "task-group-7-1-access", "--yes", "--json"))["token"]
                    # Enrollment alone must fail at the token-mode WebSocket upgrade.
                    without_server = dict(env, SWAMP_WORKER_TOKEN=token)
                    without_server.pop("SWAMP_SERVER_TOKEN")
                    refused = subprocess.run(["swamp", "worker", "connect", url, "--no-reconnect"], cwd=root, env=without_server, text=True, capture_output=True, timeout=10)
                    self.assertIn("401", refused.stdout + refused.stderr)
                    restricted = dict(env, SWAMP_SERVER_TOKEN=server_token)
                    for args in [("worker", "list"), ("workflow", "run", "maintenance-worker"), ("access", "token", "list"), ("model", "search"), ("data", "query", "true")]:
                        denied = execute(["swamp", *args, "--server", url, "--json"], override_env=restricted)
                        self.assertNotEqual(denied.returncode, 0, "worker credential must not access the control plane")
                        self.assertTrue("denied" in (denied.stdout + denied.stderr).lower() or "forbidden" in (denied.stdout + denied.stderr).lower(), "command must fail at authorization")
                    worker = subprocess.Popen(["swamp", "worker", "connect", url, "--label", "group=7", "--cache-dir", str(root / "worker-cache"), "--no-reconnect"], cwd=root, env=dict(env, MAINTENANCE_WORKER_CANARY="worker", SWAMP_WORKER_TOKEN=token, SWAMP_SERVER_TOKEN=server_token, PATH=str(bins) + ":" + env["PATH"]), stdin=subprocess.DEVNULL, stdout=wlog, stderr=wlog)
                    del token, server_token
                    for _ in range(100):
                        data = json.loads(command("swamp", "worker", "list", "--server", url, "--json"))
                        if len(data.get("workers", [])) == 2:
                            self.assertEqual({w["name"] for w in data["workers"]}, {"task-group-7-1", "task-group-8-1"})
                            self.assertTrue(all(w["labels"] == {"group": "7"} for w in data["workers"]))
                            break
                        self.assertIsNone(worker.poll(), "worker exited")
                        time.sleep(0.1)
                    else:
                        self.fail("worker did not enroll")
                    common = {"cwd": str(work), "paths": ["."], "policy": {".": {"minimumReleaseAgeDays": 7}}, "captureFailure": True}

                    def run(mode, number, **extra):
                        inputs = dict(key=f"tg-7.script.c{number}", name=f"tg-7-script-c{number}", group="7", worker="task-group-7-1", cwd=str(work), mode=mode, method="batch" if mode == "package" else mode, composerPaths=["."], jsPaths=["."], composer=dict(common, **extra), js=common, composerCommon=common, jsCommon=dict(common, paths=[]), summary=dict(common, base=base))
                        if mode == "package":
                            inputs["jsPaths"] = []
                            inputs["composer"] = dict(common, targets=[dict(path=extra.get("path", "."),manager="composer",package=extra["package"],version=extra["version"],purpose=extra["purpose"],advisories=extra.get("advisoryIds", []),advisoryUrls=["https://example.test/advisory" for _ in extra.get("advisoryIds", [])])])
                        output = json.loads(command("swamp", "workflow", "run", "maintenance-worker", "--server", url, "--stdin", "--json", stdin=json.dumps(inputs)))
                        history = json.loads(command("swamp", "workflow", "history", "get", output["id"], "--server", url, "--json"))
                        self.assertEqual(history["status"], "succeeded")
                        self.assertTrue((root / "task-group-7-1.trace").exists(), "named worker must execute filesystem steps")
                        self.assertFalse((root / "task-group-8-1.trace").exists(), "a different group's worker must never receive group-7 steps despite its forged label")
                        return {step["name"]: {artifact["name"]: artifact.get("attributes") for artifact in step.get("dataArtifacts", [])} for step in history["jobs"][0]["steps"]}

                    routine = run("routine", 1)
                    self.assertEqual(json.loads((work / "composer.lock").read_text())["packages"][0]["version"], "1.1.0")
                    self.assertNotEqual(routine["before"]["snapshot"]["tree"], routine["after"]["snapshot"]["tree"])
                    self.assertIn("routine", routine["js"])
                    detect = run("detect", 2)
                    self.assertEqual(detect["composer"]["detection"]["candidates"], [])
                    self.assertEqual(detect["js"]["detection"]["candidates"], [])
                    self.assertEqual(detect["composer_plan"]["plan"]["targets"], [])
                    self.assertEqual(detect["js_plan"]["plan"]["targets"], [])
                    package = run("package", 3, path=".", package="vendor/pkg", version="2.0.0", purpose="major", advisoryIds=[])
                    self.assertEqual(package["composer"]["batch"]["targets"][0]["version"], "2.0.0")
                    self.assertEqual(package["composer_detect"]["detection"]["candidates"], [])
                    summary = run("summary", 4)
                    self.assertIn("vendor/pkg", summary["summary"]["summary"]["text"])
                    self.assertEqual(summary["before"]["snapshot"], summary["after"]["snapshot"])
                    (work / "security-target").write_text("2.0.1")
                    common["exceptions"] = {"schema": 1, "exceptions": []}
                    security = run("package", 6, path=".", package="vendor/pkg", version="2.0.1", purpose="security", advisoryIds=["PKSA-fixture-one"])
                    self.assertNotIn("exceptions", security["composer"], "batch fixes never bypass release-age policy")
                    self.assertEqual(json.loads((work / "vet.json").read_text()), {"minimum-release-age": 7})
                    self.assertFalse((work / "maintainer-exceptions.json").exists())
                    (work / "security-target").write_text("2.0.2")
                    (work / "fail-security-update").touch()
                    failed_security = run("package", 7, path=".", package="vendor/pkg", version="2.0.2", purpose="security", advisoryIds=["PKSA-fixture-one"])
                    self.assertNotIn("exceptions", failed_security["composer"])
                    self.assertEqual(failed_security["composer"]["failure"]["exitCode"], 23)
                    self.assertFalse((work / "maintainer-exceptions.json").exists())
                    (work / "fail").touch()
                    failure = run("routine", 5)
                    self.assertEqual(failure["composer"]["failure"]["exitCode"], 23)
                    self.assertNotIn("routine", failure["js"], "stop after the first failed command")
                finally:
                    for process in (worker, impostor, serve):
                        if process is not None:
                            process.terminate()
                            try:
                                process.wait(timeout=10)
                            except subprocess.TimeoutExpired:
                                process.kill()
                                process.wait(timeout=5)


if __name__ == "__main__":
    unittest.main()
