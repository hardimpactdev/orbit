"""A real Swamp scheduler must not dispatch paused cron work during deployment."""
import importlib.machinery
import importlib.util
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.request
from unittest.mock import patch

REPO = Path(__file__).resolve().parents[1]
loader = importlib.machinery.SourceFileLoader("deploy_services", str(REPO / "bin/deploy-services"))
spec = importlib.util.spec_from_loader(loader.name, loader)
deploy = importlib.util.module_from_spec(spec)
loader.exec_module(deploy)


class NativeDeploymentTest(unittest.TestCase):
    def test_paused_schedule_starts_no_run_during_drain_including_queued_fires(self):
        with tempfile.TemporaryDirectory(prefix="orbit-deploy-native-") as temp:
            root = Path(temp)
            fixture = root / "extensions/behaviors"
            fixture.mkdir(parents=True)
            shutil.copyfile(REPO / "tests/fixtures/deploy-services/stage.ts", fixture / "stage.ts")
            shutil.copyfile(REPO / ".swamp.yaml", root / ".swamp.yaml")
            env = dict(os.environ, SWAMP_NO_UPDATE_CHECK="1", SWAMP_NO_TELEMETRY="1")
            for name in ["SWAMP_SERVER_TOKEN", "SWAMP_SERVER_TOKEN_FILE", "SWAMP_SERVE_URL", "SWAMP_SERVER_URL",
                         "SWAMP_REPO_DIR", "SWAMP_CA_CERT", "SWAMP_SERVE_EXTRA_HEADERS"]:
                env.pop(name, None)
            with socket.socket() as sock:
                sock.bind(("127.0.0.1", 0))
                port = sock.getsockname()[1]
            url = f"ws://127.0.0.1:{port}"
            token = root / "fixture-token"
            stopped = False

            def command(*args):
                result = subprocess.run(["swamp", *args, "--json"], cwd=root, env=env,
                                        capture_output=True, text=True, timeout=30)
                self.assertEqual(result.returncode, 0, result.stdout[-2000:] + result.stderr[-2000:])
                return json.loads(result.stdout)

            model = command("behavior", "create", "@fixture/deployment-drain", "fixture")
            command("access", "token", "mint", "fixture-auth", "--principal", "user:fixture")
            revealed = command("access", "token", "reveal", "fixture-auth", "--yes")
            token.write_text(revealed["token"])
            token.chmod(0o600)
            for name, method in [("blocker", "hold"), ("maintenance-projects", "record")]:
                created = command("workflow", "create", name)
                Path(created["path"]).write_text(json.dumps({
                    "id": created["id"], "name": name, "trigger": {"schedule": "0 0 1 1 *"},
                    "jobs": [{"name": "work", "steps": [{"name": "work", "task": {
                        "type": "behavior", "behavior": model["name"], "methodName": method}}]}],
                }))
                command("workflow", "validate", name)

            def until(predicate, reason):
                deadline = time.monotonic() + 20
                while time.monotonic() < deadline:
                    if predicate():
                        return
                    time.sleep(0.1)
                self.fail(reason)

            def health():
                with urllib.request.urlopen(urllib.request.Request(f"http://127.0.0.1:{port}/api/v1/health", headers={"Authorization": "Bearer " + revealed["token"]}), timeout=2) as response:
                    return json.load(response)

            def remote(script, *args, timeout=None):
                # Exercise the helper's native commands against this isolated serve.
                script = script.replace("ws://127.0.0.1:9417", url)
                result = subprocess.run(["bash", "-s", "--", *args], input=script, cwd=root, env=env,
                                        capture_output=True, text=True, timeout=timeout)
                if result.returncode:
                    raise RuntimeError(result.stdout[-2000:] + result.stderr[-2000:])
                return result.stdout

            with (root / "serve.log").open("w") as log:
                serve = subprocess.Popen(["swamp", "serve", "--port", str(port), "--host", "127.0.0.1",
                                          "--auth-mode", "token", "--admins", "user:fixture"],
                                         cwd=root, env=env, stdout=log, stderr=log)
                try:
                    def ready():
                        self.assertIsNone(serve.poll(), "isolated serve exited")
                        try:
                            with urllib.request.urlopen(f"http://127.0.0.1:{port}/ready", timeout=1):
                                return True
                        except urllib.error.HTTPError as error:
                            error.close()
                            return False
                        except OSError:
                            return False
                    until(ready, "isolated serve did not become ready")
                    env["SWAMP_SERVER_TOKEN_FILE"] = str(token)
                    command("workflow", "trigger", "set", "blocker", "--schedule", "* * * * * *", "--server", url)
                    until(lambda: (root / "blocked").exists(), "blocker did not occupy the scheduler")
                    command("workflow", "trigger", "set", "maintenance-projects", "--schedule", "* * * * * *", "--server", url)
                    until(lambda: any(s["workflowName"] == "maintenance-projects" and s["queued"] >= 2
                                      for s in health()["scheduling"]["schedules"]), "ordinary cron fires did not queue")
                    blocker = next(s for s in health()["scheduling"]["schedules"] if s["workflowName"] == "blocker")
                    self.assertEqual(blocker["queued"], 0, "fires while running must be skipped, not backlogged")
                    held = command("workflow", "history", "search", "--workflow", "blocker", "--server", url)
                    self.assertEqual(len(held["results"]), 1, "a running workflow must not overlap itself")
                    with patch.object(deploy, "LOCK_FILE", str(root / "deploy-marker.json")), \
                            patch.object(deploy, "APP", str(root)), \
                            patch.object(deploy, "SWAMP", shutil.which("swamp")), \
                            patch.object(deploy, "DRAIN_TIMEOUT", 15), patch.object(deploy, "DRAIN_POLL", 0.1):
                        with deploy.deployment_lock(remote) as progress:
                            self.addCleanup(Path(progress["snapshot"]).unlink, missing_ok=True)
                            with deploy.paused_schedules(remote, str(token), lambda: stopped, progress):
                                self.assertTrue(all(s["paused"] for s in deploy.schedule_list(remote, str(token))))
                                (root / "release").touch()
                                deploy.wait_for_idle(remote, str(token))
                                until(lambda: all(s["queued"] == 0 for s in health()["scheduling"]["schedules"]),
                                      "paused queue did not settle")
                                self.assertFalse((root / "unexpected-start").exists(),
                                                 "paused ordinary cron fire started during the deployment drain")
                                runs = command("workflow", "history", "search", "--workflow", "maintenance-projects", "--server", url)
                                self.assertEqual(runs["results"], [], "a paused workflow started a native run")
                                # A native schedule pause does not block an explicit operator run.
                                command("workflow", "run", "maintenance-projects", "--server", url)
                                self.assertTrue((root / "unexpected-start").exists())
                                (root / "unexpected-start").unlink()
                                serve.terminate()
                                serve.wait(timeout=10)
                                stopped = True
                            # Restore the saved enabled schedules directly while serve is stopped.
                        self.assertFalse((root / "deploy-marker.json").exists())
                    serve = subprocess.Popen(["swamp", "serve", "--port", str(port), "--host", "127.0.0.1",
                                              "--auth-mode", "token", "--admins", "user:fixture"],
                                             cwd=root, env=env, stdout=log, stderr=log)
                    until(ready, "isolated serve did not restart")
                    until(lambda: (root / "unexpected-start").exists(),
                          "restored schedule did not survive restart")
                finally:
                    (root / "release").touch()
                    serve.terminate()
                    try:
                        serve.wait(timeout=10)
                    except subprocess.TimeoutExpired:
                        serve.kill()
                        serve.wait(timeout=5)


if __name__ == "__main__":
    unittest.main()
