"""Real Services detect workflow, official Git acquisition and native data.get; no task VM."""
import concurrent.futures
import hashlib
import http.server
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import threading
import time
import unittest
import urllib.request

REPO = Path(__file__).resolve().parents[1]
RPC = """
const [url, input] = Deno.args;
const request = JSON.parse(input);
const ws = new WebSocket(url);
ws.onopen = () => ws.send(JSON.stringify(request));
ws.onmessage = e => { const r = JSON.parse(e.data); if(r.id === request.id) { console.log(JSON.stringify(r)); ws.close(); } };
ws.onerror = () => { console.error('native socket failed'); Deno.exit(1); };
"""


class ServiceDetectionNativeTest(unittest.TestCase):
    def test_vm_free_scan_exact_reports_retention_and_native_serialization(self):
        with tempfile.TemporaryDirectory(prefix="service-detection-native-") as temp:
            root = Path(temp)
            shutil.copytree(REPO / "extensions", root / "extensions")
            shutil.copytree(REPO / ".swamp/pulled-extensions/@swamp/git",
                            root / ".swamp/pulled-extensions/@swamp/git")
            shutil.copyfile(REPO / ".swamp.yaml", root / ".swamp.yaml")
            # Registry publication dates are fixtures; the native resolver command remains real glue.
            batch = root / "extensions/behaviors/composer-maintenance/_lib/batch.ts"
            source = batch.read_text()
            a, b = source.index("export async function releases("), source.index("function select(")
            batch.write_text(source[:a] + "export async function releases(_c: {package:string}) { return [{version:'2.0.0',publishedAt:'2020-01-01T00:00:00Z'}]; }\n" + source[b:])
            bins = root / "bin"
            bins.mkdir()
            trace = root / "commands.jsonl"
            hold = root / "hold-scanner"
            for manager in ["composer", "npm"]:
                script = bins / manager
                script.write_text("#!/usr/bin/env python3\nimport json,pathlib,sys,os,time\n"
                                  + f"with open({str(trace)!r},'a') as f:f.write(json.dumps({{'manager':{manager!r},'args':sys.argv[1:],'cwd':str(pathlib.Path.cwd()),'files':sorted(p.name for p in pathlib.Path.cwd().iterdir()),'registry':os.environ.get('npm_config_registry'),'git':os.environ.get('npm_config_git')}})+'\\n')\n"
                                  + f"while pathlib.Path({str(hold)!r}).exists():time.sleep(0.02)\n"
                                  + "if json.loads(pathlib.Path('composer.json').read_text()).get('require',{}).get('fixture/pkg')=='fail-scan':sys.exit(23)\n"
                                  + ("""
if sys.argv[1]=='outdated':print('{"locked":[{"name":"fixture/pkg","version":"1.0.0","latest":"2.0.0"}]}')
elif sys.argv[1]=='audit':print('{"advisories":[]}')
elif sys.argv[1]=='update':
 assert '--no-install' in sys.argv and '--no-scripts' in sys.argv and '--no-plugins' in sys.argv
 manifest=json.loads(pathlib.Path('composer.json').read_text())
 version=manifest['require']['fixture/pkg'].split(' || ')[0]
 pathlib.Path('composer.lock').write_text(json.dumps({'packages':[{'name':'fixture/pkg','version':version}], 'packages-dev':[]}))
else:sys.exit(64)
""" if manager == "composer" else """
if sys.argv[1]=='outdated':print('{}')
elif sys.argv[1]=='audit':print('{"vulnerabilities":{}}')
else:sys.exit(64)
"""))
                script.chmod(0o755)
            home = root / "home"
            home.mkdir()
            work = root / "source"
            work.mkdir()
            (home / ".gitconfig").write_text(f'[url "file://{work}"]\n\tinsteadOf = https://github.com/fixture/dependency-app.git\n')
            (work / "composer.json").write_text(json.dumps({"require": {"fixture/pkg": "^1.0.0"}}))
            (work / "composer.lock").write_text(json.dumps({"packages": [{"name": "fixture/pkg", "version": "1.0.0"}], "packages-dev": []}))
            (work / "package.json").write_text(json.dumps({"name": "app", "devDependencies": {"vite-plus": "*"}}))
            (work / "package-lock.json").write_text(json.dumps({"lockfileVersion": 3, "packages": {"": {}, "node_modules/vite-plus": {"version": "0.1.0", "dev": True}}}))
            (work / ".npmrc").write_text("registry=http://127.0.0.1:1/\ngit=./tools/g\n")
            (work / "bunfig.toml").write_text('[install]\nregistry="http://10.44.0.1"\n')
            (work / ".gitattributes").write_text("composer.json filter=hostile\n")
            smudge = root / "smudge-executed"
            with (home / ".gitconfig").open("a") as config:
                config.write(f'[filter "hostile"]\n\tsmudge = "touch {smudge}; cat"\n')
            (work / "internal.txt").write_text("tracked\n")
            (work / "internal-link").symlink_to("internal.txt")
            env = dict(os.environ, SWAMP_NO_UPDATE_CHECK="1", SWAMP_NO_TELEMETRY="1",
                       PATH=str(bins) + ":" + os.environ["PATH"], HOME=str(home))
            # Use the installed CLI/account, without importing any live serve endpoint.
            env["SWAMP_CONFIG_DIR"] = os.environ.get("SWAMP_CONFIG_DIR", "/home/nckrtl/.config/swamp")
            for key in ["SWAMP_SERVER_TOKEN", "SWAMP_SERVER_TOKEN_FILE", "SWAMP_SERVE_URL", "SWAMP_SERVER_URL"]:
                env.pop(key, None)

            def execute(*args, expected=0):
                result = subprocess.run(args, cwd=root, env=env, capture_output=True, text=True, timeout=90)
                self.assertEqual(result.returncode, expected, result.stdout[-5000:] + result.stderr[-2000:])
                return result.stdout

            def git(*args):
                return execute("git", "-C", str(work), *args).strip()

            git("init", "-b", "main")
            git("add", ".")
            git("-c", "user.name=Fixture", "-c", "user.email=fixture@example.test", "commit", "-m", "fixture")
            commit = git("rev-parse", "HEAD")
            execute("git", "init")  # Native Git remote_ref's working directory.
            project = {"projectId": 20, "repository": "https://github.com/fixture/dependency-app.git", "defaultBranch": "main", "check": "composer check",
                       "worker": {"composerPaths": ["."], "jsPaths": ["."], "jsPackageManagers": {".": "npm"}, "policy": {}}}
            writes = []

            class Gateway(http.server.BaseHTTPRequestHandler):
                def log_message(self, *args):
                    pass

                def do_GET(self):
                    if self.path == "/api/v1/projects/20":
                        data = {"id": 20, "repository_url": project["repository"], "default_branch": "main", "task_check": "composer check"}
                    elif self.path.startswith("/api/v1/task-groups"):
                        data = []
                    else:
                        self.send_error(404)
                        return
                    self.send_response(200)
                    self.send_header("Content-Type", "application/json")
                    self.end_headers()
                    self.wfile.write(json.dumps({"data": data}).encode())

                def do_POST(self):
                    writes.append(self.path)
                    self.send_error(500)

            gateway = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Gateway)
            threading.Thread(target=gateway.serve_forever, daemon=True).start()
            created = json.loads(execute("swamp", "behavior", "create", "@nckrtl/orbit-gateway", "orbit-gateway", "--json"))
            Path(created["path"]).write_text(json.dumps({"id": created["id"], "type": "@nckrtl/orbit-gateway", "typeVersion": created["version"], "name": "orbit-gateway", "version": 1,
                "globalArguments": {"gatewayUrl": f"http://127.0.0.1:{gateway.server_port}", "caFile": "/dev/null", "dependencyProjects": [project]}}))
            (root / "workflows").mkdir()
            shutil.copyfile(REPO / "workflows/workflow-project-nckrtl-com-detect.yaml", root / "workflows/workflow-detect.yaml")
            # This isolated repo has no schedule trigger: the test starts exactly its named workflow.
            workflow = root / "workflows/workflow-detect.yaml"
            workflow.write_text(workflow.read_text().replace("trigger:\n  schedule: 0 3 * * *\n", ""))
            with socket.socket() as sock:
                sock.bind(("127.0.0.1", 0))
                port = sock.getsockname()[1]
            url = f"ws://127.0.0.1:{port}"
            with (root / "serve.log").open("w") as log:
                serve = subprocess.Popen(["swamp", "serve", "--port", str(port), "--host", "127.0.0.1"], cwd=root, env=env, stdout=log, stderr=log)
                try:
                    for _ in range(100):
                        try:
                            with urllib.request.urlopen(f"http://127.0.0.1:{port}/ready", timeout=1):
                                break
                        except OSError:
                            self.assertIsNone(serve.poll(), (root / "serve.log").read_text()[-3000:])
                            time.sleep(0.1)
                    else:
                        self.fail("isolated serve did not start")

                    def rpc(name, version=None):
                        payload = {"modelIdOrName": "orbit-gateway", "dataName": name, "includeContent": True}
                        if version is not None:
                            payload["version"] = version
                        frame = json.loads(execute("deno", "eval", RPC, url, json.dumps({"id": "native-read", "type": "data.get", "payload": payload})))
                        return frame

                    def run():
                        result = json.loads(execute("swamp", "workflow", "run", "@nckrtl/project-nckrtl-com/detect", "--server", url, "--json"))
                        self.assertEqual(result["status"], "succeeded")

                    name = "dependencies-v1-project-20-" + hashlib.sha256(b"refs/heads/main").hexdigest()
                    # Concurrent scan fires serialize on the scanner lock, not orbit-gateway.
                    hold.write_text("hold package command during tick proof")
                    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
                        pending = [pool.submit(run) for _ in range(2)]
                        try:
                            deadline = time.monotonic() + 20
                            while not trace.exists() and time.monotonic() < deadline:
                                time.sleep(0.05)
                            self.assertTrue(trace.exists(), "scan must reach the package process")
                            for _ in range(3):
                                started = time.monotonic()
                                execute("swamp", "behavior", "run", "orbit-gateway", "project_work", "--server", url, "--json")
                                self.assertLess(time.monotonic()-started,5,"minute ticks must not wait behind the scanner")
                            self.assertTrue(all(not future.done() for future in pending))
                        finally:
                            hold.unlink(missing_ok=True)
                        for future in pending:
                            future.result()
                    execute("swamp", "behavior", "run", "orbit-gateway", "prune_dependency_results", "--server", url, "--json")
                    frame = rpc(name)
                    self.assertEqual(frame["type"], "data.get")
                    metadata = frame["payload"]["data"]
                    index = json.loads(metadata["content"])
                    self.assertEqual(index["last_attempt"]["status"], "succeeded")
                    self.assertEqual(index["source_commit"], commit)
                    self.assertEqual(metadata["version"], 6)
                    self.assertEqual(metadata["lifetime"], "infinite")
                    self.assertEqual(metadata["garbageCollection"], 9007199254740991)
                    self.assertEqual(metadata["content"],json.dumps(index,separators=(",",":"),ensure_ascii=False))
                    self.assertLessEqual(len(metadata["content"].encode()),65536)
                    self.assertEqual(metadata["tags"]["contract"], "orbit.dependencies.v1")
                    old = index["sources"][0]["report"]
                    report_frame = rpc(old["dataName"], old["version"])
                    report_meta = report_frame["payload"]["data"]
                    report = json.loads(report_meta["content"])
                    self.assertEqual(report_meta["content"],json.dumps(report,separators=(",",":"),ensure_ascii=False))
                    self.assertLessEqual(len(report_meta["content"].encode()),1048576)
                    self.assertEqual(report["basis"], "locked")
                    self.assertEqual(report["observed_on"], "repository")
                    self.assertIsNone(report["instance_id"])
                    self.assertEqual(report_meta["size"], len(report_meta["content"].encode()))
                    self.assertEqual(report_meta["checksum"], hashlib.sha256(report_meta["content"].encode()).hexdigest())
                    self.assertEqual(report["findings"][0]["target_version"], "2.0.0")
                    self.assertEqual(report_meta["version"], old["version"])
                    self.assertEqual(rpc(old["dataName"], old["version"] + 1)["type"], "error")
                    self.assertEqual(rpc(name, 1)["type"], "data.get")
                    (work / "composer.json").write_text(json.dumps({"require":{"fixture/pkg":"fail-scan"}}))
                    git("add", ".")
                    git("-c", "user.name=Fixture", "-c", "user.email=fixture@example.test", "commit", "-m", "failed refresh")
                    run()
                    failed = json.loads(rpc(name)["payload"]["data"]["content"])
                    self.assertEqual(failed["last_attempt"]["status"], "failed")
                    self.assertEqual(failed["sources"][0]["report"], old)
                    self.assertEqual(rpc(old["dataName"], old["version"]), report_frame)
                    self.assertEqual(writes, [])
                    self.assertFalse(smudge.exists(),"Git must not execute repository-selected checkout filters")
                    calls = [json.loads(line) for line in trace.read_text().splitlines()]
                    self.assertFalse(any(c["manager"] == "npm" and c["args"][0] in ["ci", "install", "update"] for c in calls))
                    self.assertTrue(all(c["registry"]=="https://registry.npmjs.org/" and c["git"]=="git" for c in calls))
                    self.assertTrue(all(".npmrc" not in c["files"] and "bunfig.toml" not in c["files"] and "internal.txt" not in c["files"] for c in calls))
                    self.assertFalse((work / "node_modules").exists())
                finally:
                    serve.terminate()
                    serve.wait(timeout=20)
                    gateway.shutdown()
                    gateway.server_close()
