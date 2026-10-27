"""Native Swamp locks, generation admission, durable waits and killed-run recovery."""
import concurrent.futures
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
import urllib.error

REPO = Path(__file__).resolve().parents[1]


class GroupWorkflowTest(unittest.TestCase):
    def test_concurrent_maintenance_admissions_share_four_slot_cap(self):
        with tempfile.TemporaryDirectory(prefix="orbit-admission-cap-") as temp:
            root = Path(temp)
            shutil.copytree(REPO / "extensions", root / "extensions")
            shutil.copyfile(REPO / ".swamp.yaml", root / ".swamp.yaml")
            source = (REPO / "tests/fixtures/group-runs/admission.ts").read_text()
            (root / "extensions/behaviors/fixture_admission.ts").write_text(
                source.replace("../../../extensions/behaviors/", "./"))
            env = dict(os.environ, SWAMP_NO_UPDATE_CHECK="1", SWAMP_NO_TELEMETRY="1")
            for name in ["SWAMP_SERVER_TOKEN", "SWAMP_SERVER_TOKEN_FILE", "SWAMP_SERVE_URL", "SWAMP_SERVER_URL"]:
                env.pop(name, None)

            def command(*args):
                result = subprocess.run(["swamp", *args, "--json"], cwd=root, env=env,
                                        capture_output=True, text=True, timeout=45)
                self.assertEqual(result.returncode, 0, result.stderr[-2500:] + result.stdout[-2500:])
                return json.loads(result.stdout)

            command("behavior", "create", "@fixture/maintenance-admission", "admission-fixture")
            command("behavior", "run", "admission-fixture", "seed")
            with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
                list(pool.map(lambda project: command("behavior", "run", "admission-fixture", "admit",
                                                       "--input", f"projectId={project}"), [4, 5]))
            admissions = command("data", "query", 'modelName == "admission-fixture" && name.startsWith("admission-")',
                                 "--select", "attributes")["results"]
            decisions = command("data", "query", 'modelName == "admission-fixture" && name.startsWith("decision-")',
                                "--select", "attributes")["results"]
            self.assertEqual(len(admissions), 4)
            self.assertEqual(sum(d["admitted"] for d in decisions), 1)
            self.assertEqual(sorted(d["projectId"] for d in decisions), [4, 5])

    def test_empty_fanout(self):
        with tempfile.TemporaryDirectory(prefix="orbit-group-empty-") as temp:
            root = Path(temp)
            shutil.copytree(REPO / "workflows", root / "workflows")
            shutil.copyfile(REPO / ".swamp.yaml", root / ".swamp.yaml")
            env = dict(os.environ, SWAMP_NO_UPDATE_CHECK="1", SWAMP_NO_TELEMETRY="1")
            for name in ["SWAMP_SERVER_TOKEN", "SWAMP_SERVER_TOKEN_FILE", "SWAMP_SERVE_URL", "SWAMP_SERVER_URL"]:
                env.pop(name, None)
            result = subprocess.run(["swamp", "workflow", "run", "group-fanout", "--stdin", "--json"],
                                    cwd=root, env=env, text=True, input=json.dumps({"groups": [], "conflicts": []}),
                                    capture_output=True, timeout=30)
            self.assertEqual(result.returncode, 0, result.stderr[-2000:])
            self.assertEqual(json.loads(result.stdout)["status"], "succeeded")

    def test_parallel_failure_duplicate_restart_and_wait(self):
        with tempfile.TemporaryDirectory(prefix="orbit-group-runs-") as temp:
            root = Path(temp)
            shutil.copytree(REPO / "extensions", root / "extensions")
            shutil.copytree(REPO / "workflows", root / "workflows")
            workflow = root / "workflows/workflow-group-run.yaml"
            workflow.write_text(workflow.read_text().replace("timeout: 10", "timeout: 30"))
            shutil.copyfile(REPO / ".swamp.yaml", root / ".swamp.yaml")
            shutil.copyfile(REPO / "tests/fixtures/group-runs/stage.ts", root / "extensions/behaviors/fixture_group_stage.ts")
            official = REPO / ".swamp/pulled-extensions/@swamp/software-factory"
            self.assertTrue(official.is_dir(), "pull the official factory into this development clone first")
            shutil.copytree(official, root / ".swamp/pulled-extensions/@swamp/software-factory")
            env = dict(os.environ, SWAMP_NO_UPDATE_CHECK="1", SWAMP_NO_TELEMETRY="1")
            for name in ["SWAMP_SERVER_TOKEN", "SWAMP_SERVER_TOKEN_FILE", "SWAMP_SERVE_URL", "SWAMP_SERVER_URL"]:
                env.pop(name, None)

            def command(*args, body=None, expected=0):
                result = subprocess.run(["swamp", *args, "--json"], cwd=root, env=env, text=True,
                                        input=None if body is None else json.dumps(body), capture_output=True, timeout=45)
                self.assertEqual(result.returncode, expected, f"{args[:3]}: {result.stdout[-2500:]} {result.stderr[-1000:]}")
                return json.loads(result.stdout) if expected == 0 else None

            definition_paths = {}

            def create(kind, name, arguments=None):
                result = command("behavior", "create", kind, name)
                path = Path(result["path"])
                definition_paths[name] = path
                # Preserve the native scaffold's ID; JSON is valid YAML.
                definition = {"id": result["id"], "type": kind, "typeVersion": result["version"],
                              "name": name, "version": 1, "globalArguments": arguments or {}}
                path.write_text(json.dumps(definition))

            factory_config = {"stages": [
                {"id": "work", "initial": True, "work": {"mode": "method", "resultEvidence": "result",
                    "method": {"modelIdOrName": '${{ "fixture-" + self.workItem }}', "methodName": "work",
                               "inputs": {"workItem": "${{ self.workItem }}"}}},
                 "transitions": [{"name": "finish", "to": "done", "gates": [{"type": "evidence-recorded", "config": {"name": "result"}}]}]},
                {"id": "done", "terminal": True},
            ]}
            create("@swamp/software-factory", "fixture-factory", factory_config)
            with socket.socket() as sock:
                sock.bind(("127.0.0.1", 0))
                port = sock.getsockname()[1]
            url = f"ws://127.0.0.1:{port}"
            # Public dummy credential for an isolated auth-mode=none server.
            token = root / "fixture-token"
            token.write_text("fixture.public-test-value")
            # Plain loopback WebSockets need no added CA roots or production CA file.
            config = {"factory": "fixture-factory", "repoDir": str(root), "serveUrl": url,
                      "serveTokenFile": str(token), "caFile": "/dev/null",
                      "maxSteps": 1, "preapprovalModel": "fixture-preapprovals"}
            # Route the copied native workflow to this isolated server only.
            supervisor = root / "workflows/workflow-group-supervise.yaml"
            yaml = supervisor.read_text().replace("assistanceModel: orbit-gateway", 'assistanceModel: ""')
            overrides = "".join(f"            {key}: {json.dumps(value)}\n" for key, value in config.items()
                                if key in ["serveUrl", "serveTokenFile", "caFile", "maxSteps", "repoDir"])
            supervisor.write_text(yaml.replace("          globalArgs:\n", "          globalArgs:\n" + overrides))
            for mode in ["routine", "package"]:
                manual = root / f"workflows/workflow-dependency-{mode}.yaml"
                source = manual.read_text().replace(f"factory: dependency-{mode}", "factory: fixture-factory")
                source = source.replace(f"preapprovalModel: orbit-driver-{mode}", "preapprovalModel: fixture-preapprovals")
                if mode == "package":
                    source = source.replace("modelIdOrName: orbit-gateway", "modelIdOrName: fixture-tg-8")
                manual.write_text(source)
            for group in [7, 8, 9, 10]:
                create("@fixture/group-stage", f"fixture-tg-{group}")
                create("@nckrtl/orbit-group-run", f"orbit-group-tg-{group}", config)
                create("@nckrtl/orbit-group-run", f"orbit-group-recovery-tg-{group}", config)
                command("behavior", "run", "fixture-factory", "start", "--input", f"workItem=tg-{group}")
            create("@nckrtl/orbit-group-runs", "orbit-group-runs", {"factories": [
                {"factory": "fixture-factory", "preapprovalModel": "fixture-preapprovals"}]})
            command("workflow", "validate", "group-run")

            def latest(model, name):
                rows = command("data", "query", f'modelName == "{model}" && name == "{name}"', "--select", "attributes")
                return rows["results"][0] if isinstance(rows, dict) else rows[0]

            def method(model, name, **inputs):
                args = ["behavior", "run", model, name, "--server", url]
                for key, value in inputs.items():
                    args += ["--input", f"{key}={value}"]
                return command(*args)

            def history(run):
                return command("workflow", "history", "get", run, "--server", url)

            def status_is(run, status):
                record = history(run)
                if record["status"] == "failed" and status != "failed":
                    report = command("report", "get", "@swamp/workflow-summary", "--workflow", "group-run")
                    self.fail("unexpected workflow failure: " + json.dumps(report))
                return record["status"] == status

            def until(test, reason):
                deadline = time.monotonic() + 35
                while time.monotonic() < deadline:
                    result = test()
                    if result:
                        return result
                    time.sleep(0.1)
                self.fail(reason)

            log = open(root / "serve.log", "w")
            serve = None
            try:
                def start():
                    nonlocal serve
                    serve = subprocess.Popen(["swamp", "serve", "--port", str(port), "--host", "127.0.0.1",
                                              "--auth-mode", "none", "--no-schedule", "--continuation-sweep-interval", "1s"],
                                             cwd=root, env=env, stdout=log, stderr=log)
                    def ready():
                        self.assertIsNone(serve.poll(), "serve exited")
                        try:
                            with urllib.request.urlopen(f"http://127.0.0.1:{port}/ready", timeout=1):
                                return True
                        except urllib.error.HTTPError as error:
                            error.close()
                            return False
                        except OSError:
                            return False
                    until(ready, "serve did not start")
                start()
                with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
                    list(pool.map(lambda group: command("workflow", "run", "group-supervise", "--server", url, "--stdin",
                                                       body={"workItem": f"tg-{group}", "factory": "fixture-factory",
                                                             "preapprovalModel": "fixture-preapprovals"}), [7, 8, 9, 10]))
                until(lambda: (root / "effect-receipt-written").exists(), "group 7 did not reach its effect receipt")
                owners = {group: latest(f"orbit-group-tg-{group}", "owner") for group in [7, 8, 9, 10]}
                until(lambda: history(owners[9]["runId"])["status"] == "failed", "group 9 did not fail independently")
                until(lambda: status_is(owners[8]["runId"], "suspended"), "group 8 did not reach its durable wait")
                self.assertEqual(latest("fixture-tg-10", "starts")["count"], 1)
                # Both manual entry points must join the scheduled group owner.
                command("workflow", "run", "dependency-routine", "--server", url, "--input", "workItem=tg-8")
                command("workflow", "run", "dependency-package", "--server", url, "--stdin", body={
                    "workItem": "tg-8", "targets": [{"manager": "composer", "package": "fixture/package",
                    "version": "2.0.0", "purpose": "major"}]})
                self.assertEqual(latest("orbit-group-tg-8", "owner"), owners[8])
                self.assertEqual(latest("fixture-tg-8", "starts")["count"], 1)
                # Duplicate requests for the same generation admit no second owner.
                duplicate = command("workflow", "run", "group-run", "--server", url, "--detach", "--no-supersede", "--stdin",
                                    body={**owners[8], "definition": "orbit-group-tg-8", "preapprovalModel": "fixture-preapprovals"})
                until(lambda: history(duplicate["runId"])["status"] == "succeeded", "duplicate did not exit")
                self.assertEqual(latest("fixture-tg-8", "starts")["count"], 1)
                method("orbit-group-tg-8", "ensure", workItem="tg-8")
                self.assertEqual(latest("orbit-group-tg-8", "intent")["generation"], 1)
                # Kill while the effect receipt exists but factory evidence does not.
                serve.kill()
                serve.wait(timeout=10)
                start()
                method("orbit-group-runs", "discover")
                method("orbit-group-tg-7", "ensure", workItem="tg-7")
                # Missing workflow source makes native checkpoint recovery fail.
                # The run record remains readable, and no factory/effect is reset.
                unavailable = root / "workflow-group-run.disabled"
                workflow.rename(unavailable)
                try:
                    for attempt in range(1, 4):
                        command("behavior", "run", "orbit-group-recovery-tg-7", "recover", "--server", url,
                                "--input", "definition=orbit-group-tg-7", "--input", f"runId={owners[7]['runId']}", expected=1)
                        self.assertEqual(latest("orbit-group-tg-7", "recovery")["attempts"], attempt)
                    self.assertTrue(latest("orbit-group-tg-7", "failures")["parked"])
                finally:
                    unavailable.rename(workflow)
                method("orbit-group-tg-7", "ensure", workItem="tg-7")
                self.assertEqual(latest("orbit-group-tg-7", "reconcile")["runId"], None)
                self.assertEqual(latest("orbit-group-tg-7", "intent")["runId"], owners[7]["runId"])
                method("orbit-group-tg-7", "retry", actor="nick", note="restored workflow source")
                method("orbit-group-recovery-tg-7", "recover", definition="orbit-group-tg-7", runId=owners[7]["runId"])
                until(lambda: history(owners[7]["runId"])["status"] in ["suspended", "succeeded"], "owner did not recover")
                self.assertEqual(latest("fixture-tg-7", "starts")["count"], 1)
                self.assertEqual(latest("orbit-group-tg-7", "owner")["runId"], owners[7]["runId"])
                # Native serve continuation settles waits that survived the restart.
                until(lambda: history(owners[8]["runId"])["status"] == "succeeded", "durable wait did not resume after restart")
                method("orbit-group-tg-8", "ensure", workItem="tg-8")
                self.assertEqual(latest("orbit-group-tg-8", "intent")["generation"], 2)
                until(lambda: latest("fixture-factory", "state-tg-8")["stageId"] == "done", "next generation did not advance the factory")
                # The permanently failing group parks after three owner attempts.
                for generation in [2, 3]:
                    method("orbit-group-tg-9", "ensure", workItem="tg-9")
                    failed = latest("orbit-group-tg-9", "intent")
                    self.assertEqual(failed["generation"], generation)
                    until(lambda: history(failed["runId"])["status"] == "failed", "failed owner did not settle")
                for _ in range(2):
                    method("orbit-group-tg-9", "ensure", workItem="tg-9")
                    self.assertEqual(latest("orbit-group-tg-9", "intent")["generation"], 3)
                    failure = latest("orbit-group-tg-9", "failures")
                    self.assertEqual(failure["consecutive"], 3)
                    self.assertTrue(failure["parked"])
                method("orbit-group-tg-9", "retry", actor="nick", note="fixture inspection")
                method("orbit-group-tg-9", "ensure", workItem="tg-9")
                self.assertEqual(latest("orbit-group-tg-9", "intent")["generation"], 4)
                # Conflict notification failure is an allowed native fan-out failure.
                # Healthy discovered groups must still start their own workflow.
                create("@swamp/software-factory", "fixture-conflict", factory_config)
                command("behavior", "run", "fixture-conflict", "start", "--input", "workItem=tg-10")
                create("@fixture/group-stage", "orbit-gateway")
                create("@fixture/group-stage", "fixture-tg-11")
                command("behavior", "run", "fixture-factory", "start", "--input", "workItem=tg-11")
                # Edit only this test-created definition, preserving its native ID.
                path = definition_paths["orbit-group-runs"]
                config_data = json.loads(path.read_text())
                config_data["globalArguments"]["factories"].append({"factory": "fixture-conflict", "preapprovalModel": "fixture-preapprovals"})
                path.write_text(json.dumps(config_data))
                command("workflow", "run", "groups-driver", "--server", url)
                until(lambda: latest("fixture-tg-11", "starts")["count"] == 1, "conflict notification aborted healthy fan-out")
                conflicts = latest("orbit-group-runs", "groups")["conflicts"]
                self.assertEqual([c["workItem"] for c in conflicts], ["tg-10"])
                self.assertEqual(latest("fixture-tg-10", "starts")["count"], 1)
            finally:
                if serve and serve.poll() is None:
                    serve.terminate()
                    try:
                        serve.wait(timeout=10)
                    except subprocess.TimeoutExpired:
                        serve.kill()
                        serve.wait(timeout=10)
                log.close()


if __name__ == "__main__":
    unittest.main()
