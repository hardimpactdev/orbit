"""Real native Plausible HTTP actions, vault resolution and parent DAG ordering."""

import json
import os
import socket
import subprocess
import sys
import time
import urllib.request
from pathlib import Path

import yaml

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from tests.fixtures.plausible_http import PlausibleFixture
from tests.native_harness import NativeTestCase

ROOT = Path(__file__).resolve().parents[1]


class AnalyticsEnableNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors(
            "operations/analytics-site",
            "operations/analytics",
            "operations/certificates",
        )
        # Keep only pure schema imports.
        for family, keep in [
            ("analytics", {"plausible.ts", "host-intent.ts"}),
            ("certificates", {"input.ts"}),
        ]:
            for path in (self.repo / "extensions/behaviors/operations" / family).glob(
                "*.ts"
            ):
                if path.name not in keep:
                    path.unlink()
        self.provider = PlausibleFixture()
        self.addCleanup(self.provider.close)
        self.secrets = (
            self.provider.password,
            self.provider.api_key,
            self.provider.plugin,
            self.provider.cookie,
            "private-csrf-826495",
        )
        self.cli("vault", "create", "local_encryption", "orbit-control-inputs")
        self.key = "analytics-plausible-21ac9e70-96f4-4d75-a7b3-ab52a946492b"
        self.cli(
            "vault",
            "put",
            "orbit-control-inputs",
            self.key,
            input=json.dumps(self.provider.credentials),
        )
        self.audit = self.repo / "audit"
        self.native_env["ANALYTICS_AUDIT"] = str(self.audit)
        self.native_env["ANALYTICS_FAIL_CHILD"] = ""
        parent = yaml.safe_load(
            (
                ROOT / "workflows/workflow-operation-analytics-enable-direct.yaml"
            ).read_text()
        )
        for job in parent["jobs"]:
            for step in job["steps"]:
                self.assertEqual(step.pop("target"), "orbit-node-209")
                self.assertNotIn("labels", step)
        (self.repo / "workflows/workflow-analytics.yaml").write_text(
            yaml.safe_dump(parent, sort_keys=False)
        )
        effect = self.repo / "effect.py"
        effect.write_text("""import os
from pathlib import Path
name=os.environ['ACTION']
with Path(os.environ['ANALYTICS_AUDIT']).open('a') as out: out.write(name+'\\n')
if os.environ['ANALYTICS_FAIL_CHILD']==name: raise SystemExit(1)
print('{"ready":true}')
""")
        for index, name in enumerate(
            [
                "route-certificate-publish",
                "operation-route-caddy-delta",
                "cluster-router-probe",
                "route-dns-publish",
                "route-dns-grace",
                "route-certificate-remove",
            ]
        ):
            child = {
                "id": f"dba9f970-197d-443a-9037-{index:012d}",
                "name": name,
                "inputs": {
                    "type": "object",
                    "properties": {
                        "execution_secrets": {
                            "type": "object",
                            "additionalProperties": False,
                        }
                    },
                },
                "jobs": [
                    {
                        "name": "effect",
                        "steps": [
                            {
                                "name": "apply",
                                "task": {
                                    "type": "behavior",
                                    "behaviorType": "command/shell",
                                    "behaviorName": "fixture-" + name,
                                    "methodName": "execute",
                                    "inputs": {
                                        "run": "python3 " + str(effect),
                                        "env": {"ACTION": name},
                                    },
                                },
                            }
                        ],
                    }
                ],
            }
            (self.repo / f"workflows/workflow-{name}.yaml").write_text(
                yaml.safe_dump(child)
            )
        self.site = {
            "domain": "shop.test",
            "previous_domain": None,
            "timezone": "UTC",
            "goals": [],
        }
        self.inputs = {
            "operation_id": 7,
            "node_id": 14,
            "kind": "analytics.enable",
            "execution_secrets": {
                "plausible_credentials": {
                    "vault": "orbit-control-inputs",
                    "key": self.key,
                }
            },
            "intent": {
                "base_url": self.provider.url,
                "site": self.site,
                **{
                    key: [{}]
                    for key in (
                        "certificates",
                        "router",
                        "probes",
                        "ingress",
                        "dns",
                        "retire",
                        "cleanup",
                    )
                },
                "result_json": json.dumps(
                    {
                        "ready": True,
                        "enabled": True,
                        "readable": True,
                        "domain": "shop.test",
                        "timezone": "UTC",
                    }
                ),
            },
        }

    def run_enable(self, success=True):
        result = json.loads(
            self.cli(
                "workflow",
                "run",
                "operation-analytics-enable-direct",
                "--input",
                json.dumps(self.inputs),
                success=success,
            ).stdout
        )
        run = json.loads(self.cli("workflow", "history", "get", result["id"]).stdout)
        workspace = next(
            step
            for job in run["jobs"]
            for step in job["steps"]
            if step["name"] == "workspace"
        )["outputs"]["stdout"].strip()
        self.assertFalse(
            Path(workspace).exists(), "Private provider session retained after terminal"
        )
        return run

    def test_creates_and_verifies_before_canonical_publication_and_terminal_result(
        self,
    ):
        self.cli("workflow", "validate", "operation-analytics-enable-direct")
        run = self.run_enable()
        self.assertEqual(self.provider.sites, {"shop.test": "UTC"})
        self.assertEqual(
            self.audit.read_text().splitlines(),
            [
                "route-certificate-publish",
                "operation-route-caddy-delta",
                "cluster-router-probe",
                "operation-route-caddy-delta",
                "route-dns-publish",
                "route-dns-grace",
                "operation-route-caddy-delta",
                "route-certificate-remove",
            ],
        )
        self.assertEqual(run["status"], "succeeded")
        self.assertTrue(
            json.loads(run["jobs"][-1]["steps"][0]["outputs"]["stdout"])["readable"]
        )
        self.assertIn(("GET", "/logout"), self.provider.calls)

    def test_updates_previous_domain_and_timezone_without_creating_duplicate_site(self):
        self.provider.sites = {"old.test": "Europe/Amsterdam"}
        self.site["previous_domain"] = "old.test"
        self.run_enable()
        self.assertEqual(self.provider.sites, {"shop.test": "UTC"})
        self.assertIn(("PUT", "/old.test/settings"), self.provider.calls)
        self.assertNotIn(("POST", "/sites"), self.provider.calls)

    def test_current_site_uses_observation_guards_and_batches_goals_by_eight(self):
        self.provider.sites = {"shop.test": "UTC"}
        self.site["goals"] = [
            {"goal_type": "Goal.CustomEvent", "goal": {"event_name": "Signup" + str(i)}}
            for i in range(17)
        ]
        run = self.run_enable()
        self.assertNotIn(("POST", "/sites"), self.provider.calls)
        self.assertNotIn(("PUT", "/shop.test/settings"), self.provider.calls)
        self.assertEqual(self.provider.calls.count(("PUT", "/api/plugins/v1/goals")), 3)
        self.assertEqual(run["status"], "succeeded")

    def test_provider_failure_logs_out_and_never_publishes_routes(self):
        self.provider.fail = "/api/v2/query"
        run = self.run_enable(False)
        self.assertEqual(run["status"], "failed")
        self.assertFalse(self.audit.exists())
        self.assertIn(("GET", "/logout"), self.provider.calls)
        self.assertEqual(run["jobs"][-1]["status"], "skipped")

    def test_wrong_goals_receipt_refuses_publication(self):
        self.site["goals"] = [
            {"goal_type": "Goal.CustomEvent", "goal": {"event_name": "Signup"}}
        ]
        self.provider.bad_goals = True
        self.run_enable(False)
        self.assertFalse(self.audit.exists())
        self.assertIn(("GET", "/logout"), self.provider.calls)

    def test_probe_failure_stops_ingress_dns_retirement_and_success(self):
        self.native_env["ANALYTICS_FAIL_CHILD"] = "cluster-router-probe"
        self.run_enable(False)
        self.assertEqual(
            self.audit.read_text().splitlines(),
            [
                "route-certificate-publish",
                "operation-route-caddy-delta",
                "cluster-router-probe",
            ],
        )

    def test_failed_provider_can_use_native_resume_from_workspace_to_reauthenticate(
        self,
    ):
        self.provider.fail = "/api/v2/query"
        run = self.run_enable(False)
        self.provider.fail = ""
        self.cli(
            "workflow",
            "resume",
            "operation-analytics-enable-direct",
            "--run",
            run["id"],
            "--from",
            "workspace",
        )
        history = json.loads(self.cli("workflow", "history", "get", run["id"]).stdout)
        self.assertEqual(history["status"], "succeeded")
        self.assertEqual(self.provider.calls.count(("POST", "/sites")), 1)
        self.assertEqual(self.provider.calls.count(("POST", "/login")), 2)

    def test_login_refusal_cleans_private_session_without_site_mutation(self):
        self.provider.fail = "/login"
        run = self.run_enable(False)
        self.assertEqual(run["status"], "failed")
        self.assertEqual(self.provider.sites, {})
        self.assertFalse(self.audit.exists())

    def test_host_failure_native_resume_does_not_repeat_provider_mutation(self):
        self.native_env["ANALYTICS_FAIL_CHILD"] = "route-certificate-remove"
        run = self.run_enable(False)
        self.native_env["ANALYTICS_FAIL_CHILD"] = ""
        before = list(self.provider.calls)
        self.cli(
            "workflow",
            "resume",
            "operation-analytics-enable-direct",
            "--run",
            run["id"],
        )
        history = json.loads(self.cli("workflow", "history", "get", run["id"]).stdout)
        self.assertEqual(history["status"], "succeeded")
        self.assertEqual(self.provider.calls, before)
        self.assertEqual(
            self.audit.read_text().splitlines().count("route-certificate-remove"), 2
        )

    def test_remote_worker_receives_manifest_scripts_and_whole_vault_environment(self):
        self.native_timeout = 120
        for path in (self.repo / "workflows").glob("*.yaml"):
            workflow = yaml.safe_load(path.read_text())
            for job in workflow["jobs"]:
                for step in job["steps"]:
                    step["target"] = "orbit-node-209"
            path.write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.cli(
            "access", "token", "mint", "fixture-admin", "--principal", "user:fixture"
        )
        admin = self.repo / "admin"
        admin.write_text(
            json.loads(
                self.cli("access", "token", "reveal", "fixture-admin", "--yes").stdout
            )["token"]
        )
        admin.chmod(0o600)
        with socket.socket() as listener:
            listener.bind(("127.0.0.1", 0))
            port = listener.getsockname()[1]
        url = f"ws://127.0.0.1:{port}"
        binary = os.environ.get("SWAMP_BIN", "swamp")
        env = dict(
            os.environ,
            **self.native_env,
            SWAMP_NO_UPDATE_CHECK="1",
            SWAMP_NO_TELEMETRY="1",
        )
        for key in (
            "SWAMP_SERVER_URL",
            "SWAMP_SERVE_URL",
            "SWAMP_SERVER_TOKEN",
            "SWAMP_SERVER_TOKEN_FILE",
            "SWAMP_REPO_DIR",
            "SWAMP_CA_CERT",
            "SWAMP_EXTENSIONS_DIR",
        ):
            env.pop(key, None)
        serve = worker = None
        with (
            (self.repo / "serve.log").open("w") as log,
            (self.repo / "worker.log").open("w") as worker_log,
        ):
            try:
                serve = subprocess.Popen(
                    [
                        binary,
                        "serve",
                        "--host",
                        "127.0.0.1",
                        "--port",
                        str(port),
                        "--auth-mode",
                        "token",
                        "--admins",
                        "user:fixture",
                        "--remote-only",
                    ],
                    cwd=self.repo,
                    env=env,
                    stdout=log,
                    stderr=log,
                )
                for _ in range(100):
                    try:
                        urllib.request.urlopen(
                            f"http://127.0.0.1:{port}/ready", timeout=1
                        ).close()
                        break
                    except OSError:
                        self.assertIsNone(
                            serve.poll(), (self.repo / "serve.log").read_text()[-2000:]
                        )
                        time.sleep(0.1)
                flags = ("--server", url, "--token-file", str(admin))
                enrollment = self.repo / "enrollment"
                enrollment.write_text(
                    json.loads(
                        self.cli(
                            "worker",
                            "token",
                            "create",
                            "orbit-node-209",
                            "--duration",
                            "10m",
                            *flags,
                        ).stdout
                    )["token"]
                )
                enrollment.chmod(0o600)
                self.cli(
                    "access",
                    "token",
                    "mint",
                    "fixture-worker",
                    "--principal",
                    "worker:orbit-node-209",
                    *flags,
                )
                token = self.repo / "worker-server"
                token.write_text(
                    json.loads(
                        self.cli(
                            "access",
                            "token",
                            "reveal",
                            "fixture-worker",
                            "--yes",
                        ).stdout
                    )["token"]
                )
                token.chmod(0o600)
                worker = subprocess.Popen(
                    [
                        binary,
                        "worker",
                        "connect",
                        url,
                        "--token-file",
                        str(enrollment),
                        "--server-token-file",
                        str(token),
                        "--cache-dir",
                        str(self.repo / "worker-cache"),
                        "--label",
                        "node=209",
                    ],
                    cwd=self.repo,
                    env=env,
                    stdout=worker_log,
                    stderr=worker_log,
                )
                result = json.loads(
                    self.cli(
                        "workflow",
                        "run",
                        "operation-analytics-enable-direct",
                        "--input",
                        json.dumps(self.inputs),
                        *flags,
                    ).stdout
                )
                history = json.loads(
                    self.cli("workflow", "history", "get", result["id"], *flags).stdout
                )
                self.assertEqual(history["status"], "succeeded")
                assets = history["jobs"][0]["steps"][0]["outputs"]
                self.assertIn("worker-cache", assets["login"])
                for action in (
                    "login_form",
                    "login",
                    "site_form",
                    "inspect",
                    "create",
                    "update",
                    "verify",
                    "goals",
                    "stats",
                    "logout",
                ):
                    self.assertTrue(
                        Path(assets[action]).is_file(), action + " script not shipped"
                    )
                self.assertTrue(Path(assets["login"]).with_name("common.py").is_file())
                self.assertEqual(self.provider.sites, {"shop.test": "UTC"})
                self.assert_private_absent(json.dumps(history))
            finally:
                for process in (worker, serve):
                    if process is not None:
                        process.terminate()
                        try:
                            process.wait(timeout=10)
                        except subprocess.TimeoutExpired:
                            process.kill()
                            process.wait(timeout=5)


if __name__ == "__main__":
    import unittest

    unittest.main()
