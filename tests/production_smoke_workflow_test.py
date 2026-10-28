"""Native durable wait recovery with no real Gateway, GitHub or repo code execution."""
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


class ProductionSmokeWorkflowTest(unittest.TestCase):
    def test_wait_survives_serve_restart_without_redispatch(self):
        self.run_scenario(False)

    def test_busy_admission_retries_on_followup_observe_after_serve_restart(self):
        self.run_scenario(True)

    def run_scenario(self, refuse_first):
        with tempfile.TemporaryDirectory(prefix="production-smoke-") as temp:
            root = Path(temp)
            shutil.copytree(REPO / "extensions", root / "extensions")
            fixtures = root / "tests/fixtures/production-smoke"
            fixtures.parent.mkdir(parents=True)
            shutil.copytree(REPO / "tests/fixtures/production-smoke", fixtures)
            shutil.copyfile(REPO / ".swamp.yaml", root / ".swamp.yaml")
            for name in ["project", "native"]:
                source = (fixtures / f"{name}.ts").read_text().replace('"../../../extensions/behaviors/', '"./')
                source = source.replace('"./fixture.ts"', '"../../tests/fixtures/production-smoke/fixture.ts"')
                (root / f"extensions/behaviors/fixture_smoke_{name}.ts").write_text(source)
            (root / "workflows").mkdir()
            workflow = (REPO / "workflows/workflow-production-smoke.yaml").read_text()
            (root / "workflows/workflow-production-smoke.yaml").write_text(workflow.replace("@nckrtl/production-smoke", "@fixture/production-smoke"))
            env = dict(os.environ, SWAMP_NO_UPDATE_CHECK="1", SWAMP_NO_TELEMETRY="1")
            for key in ["SWAMP_SERVER_TOKEN", "SWAMP_SERVER_TOKEN_FILE", "SWAMP_SERVE_URL", "SWAMP_SERVER_URL"]:
                env.pop(key, None)

            def command(*args):
                result = subprocess.run(["swamp", *args, "--json"], cwd=root, env=env, capture_output=True, text=True, timeout=45)
                self.assertEqual(result.returncode, 0, result.stdout[-2000:] + result.stderr[-2000:])
                return json.loads(result.stdout)

            def until(check):
                deadline = time.monotonic() + 35
                while time.monotonic() < deadline:
                    if check():
                        return
                    time.sleep(0.1)
                self.fail("native smoke continuation did not reach the expected state")

            with socket.socket() as sock:
                sock.bind(("127.0.0.1", 0))
                port = sock.getsockname()[1]
            url = f"ws://127.0.0.1:{port}"
            log = open(root / "serve.log", "w")
            serve = None

            def start():
                nonlocal serve
                serve = subprocess.Popen(["swamp", "serve", "--host", "127.0.0.1", "--port", str(port), "--auth-mode", "none", "--no-schedule", "--continuation-sweep-interval", "1s"], cwd=root, env=env, stdout=log, stderr=log)

                def ready():
                    self.assertIsNone(serve.poll(), (root / "serve.log").read_text()[-2000:])
                    try:
                        with urllib.request.urlopen(f"http://127.0.0.1:{port}/ready", timeout=1):
                            return True
                    except OSError as error:
                        if hasattr(error, "close"):
                            error.close()
                        return False
                until(ready)

            try:
                command("workflow", "validate", "production-smoke")
                start()
                if refuse_first:
                    (root / "refuse-first").touch()
                run = command("workflow", "run", "production-smoke", "--server", url, "--detach", "--input", "projectId=20", "--input", "projectType=@nckrtl/project-native-smoke", "--input", "mode=baseline")["runId"]
                def history():
                    record = command("workflow", "history", "get", run, "--server", url)
                    if record["status"] == "failed":
                        self.fail(json.dumps(record) + (root / "serve.log").read_text()[-3000:])
                    return record
                until(lambda: history()["status"] == "suspended")
                if refuse_first:
                    self.assertTrue((root / "refused.json").exists())
                    self.assertFalse((root / "dispatch.json").exists())
                else:
                    self.assertEqual(json.loads((root / "dispatch.json").read_text())["count"], 1)
                serve.kill()
                serve.wait(timeout=10)
                (root / "ready-result").touch()
                start()
                until(lambda: history()["status"] == "succeeded")
                if refuse_first:
                    admission = json.loads((root / "dispatch.json").read_text())
                    refused = json.loads((root / "refused.json").read_text())
                    self.assertEqual(admission["input"], refused["input"])
                    self.assertEqual(admission["key"], refused["key"])
                    self.assertEqual(admission["count"], 1)
                    run = command("workflow", "run", "production-smoke", "--server", url, "--detach", "--input", "projectId=20", "--input", "projectType=@nckrtl/project-native-smoke", "--input", "mode=observe")["runId"]
                    until(lambda: history()["status"] == "succeeded")
                rows = command("data", "query", 'modelName == "production-smoke-20" && name == "state"', "--select", "attributes")["results"]
                self.assertEqual(rows[0]["status"], "passed")
                self.assertEqual(rows[0]["known_good"]["deployment_id"], 9)
                self.assertEqual(json.loads((root / "dispatch.json").read_text())["count"], 1)
            finally:
                if serve and serve.poll() is None:
                    serve.terminate()
                    serve.wait(timeout=10)
                log.close()
