"""The native one-action retirement DAG uses pinned SSH, vault env and fail-stop dependencies."""

import base64
import hashlib
import json
import os
import shutil
import sys
import unittest
from pathlib import Path

import yaml

sys.path.insert(0, str(Path(__file__).resolve().parent))
from native_harness import ROOT, NativeTestCase


class NodeRemovalRetirementNativeTest(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.identity = "synthetic-retirement-" + os.urandom(16).hex()
        self.secrets = (self.identity,)
        self.calls = self.repo / "ssh-calls.ndjson"
        assets = ROOT / "extensions/behaviors/operations/node-removal"
        copied = self.repo / "extensions/behaviors/operations/node-removal"
        shutil.copytree(assets, copied)
        executable = ROOT / "tests/fixtures/node-removal/ssh.py"
        common = copied / "scripts/ssh_common.py"
        common.write_text(common.read_text().replace("/usr/bin/ssh", str(executable)))
        workflow = yaml.safe_load(
            (ROOT / "workflows/workflow-node-removal-retirement.yaml").read_text()
        )
        for step in workflow["jobs"][0]["steps"]:
            step.pop("target")
            step.pop("labels")
            args = step["task"]["inputs"]
            if "run" not in args:
                continue
            args["env"]["FIXTURE_SSH_CALLS"] = str(self.calls)
            args["env"]["FIXTURE_KEY_SHA256"] = hashlib.sha256(
                self.identity.encode()
            ).hexdigest()
        self.definition = self.repo / "workflows/workflow-node-removal-retirement.yaml"
        self.definition.write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.cli("vault", "create", "local_encryption", "orbit-control-inputs")
        self.cli(
            "vault",
            "put",
            "orbit-control-inputs",
            "bootstrap-identity-node-14",
            input=self.identity,
        )
        self.public = {
            "operation_id": 7,
            "executor_node_id": 14,
            "target": {
                "node_id": 212,
                "host": "dev.example.test",
                "port": 2222,
                "user": "orbit",
                "fingerprint": "SHA256:"
                + base64.b64encode(hashlib.sha256(b"public-host-key").digest())
                .decode()
                .rstrip("="),
            },
            "host_key": "ssh-ed25519 " + base64.b64encode(b"public-host-key").decode(),
            "execution_secrets": {
                "bootstrap_identity": {
                    "vault": "orbit-control-inputs",
                    "key": "bootstrap-identity-node-14",
                }
            },
        }

    def run_workflow(self, success=True):
        self.cli("workflow", "validate", "node-removal-retirement")
        result = self.cli(
            "workflow",
            "run",
            "node-removal-retirement",
            "--input",
            json.dumps(self.public),
            success=success,
        )
        run = json.loads(result.stdout)
        history = json.loads(self.cli("workflow", "history", "get", run["id"]).stdout)
        return history

    def test_retires_each_service_in_order_without_private_argv_or_persisted_values(
        self,
    ):
        history = self.run_workflow()
        self.assertEqual(history["status"], "succeeded")
        calls = [json.loads(row) for row in self.calls.read_text().splitlines()]
        self.assertEqual(len(calls), 10)
        self.assertIn("config.toml", calls[0]["program"])
        self.assertEqual(
            [row["remote"] for row in calls[1:7]],
            [
                "sudo -n /usr/bin/systemctl disable orbit-node-worker.service",
                "sudo -n /usr/bin/systemctl stop orbit-node-worker.service",
                "sudo -n /usr/bin/systemctl is-active orbit-node-worker.service",
                "sudo -n /usr/bin/systemctl stop orbit-agent.service",
                "sudo -n /usr/bin/systemctl disable orbit-agent.service",
                "sudo -n /usr/bin/systemctl is-active orbit-agent.service",
            ],
        )
        self.assertTrue(all(row["target"] == "orbit@dev.example.test" for row in calls))
        self.assertTrue(all(not Path(row["key"]).exists() for row in calls))
        steps = history["jobs"][0]["steps"]
        self.assertTrue(
            all(Path(path).is_file() for path in steps[0]["outputs"].values())
        )
        self.assertTrue(
            all(
                (
                    json.loads(step["outputs"]["stdout"])
                    if "stdout" in step["outputs"]
                    else step["outputs"]
                )["ready"]
                for step in steps[1:]
            )
        )

    def test_failed_worker_stop_skips_every_later_retirement_action(self):
        self.native_env["FIXTURE_FAIL_STOP"] = "1"
        history = self.run_workflow(success=False)
        calls = [json.loads(row) for row in self.calls.read_text().splitlines()]
        self.assertEqual(len(calls), 3)
        self.assertEqual(history["status"], "failed")
        self.assertTrue(
            all(step["status"] == "skipped" for step in history["jobs"][0]["steps"][6:])
        )

    def test_empty_host_skips_mutations_and_keeps_the_run_idempotent(self):
        self.native_env["FIXTURE_EMPTY_HOST"] = "1"
        history = self.run_workflow()
        self.assertEqual(history["status"], "succeeded")
        self.assertEqual(len(self.calls.read_text().splitlines()), 1)
        self.assertTrue(
            all(step["status"] == "skipped" for step in history["jobs"][0]["steps"][4:])
        )

    def test_bad_public_fingerprint_refuses_before_resolving_missing_private_key(self):
        self.public["target"]["fingerprint"] = "SHA256:" + "A" * 43
        self.public["execution_secrets"]["bootstrap_identity"]["key"] = (
            "bootstrap-identity-node-15"
        )
        history = self.run_workflow(success=False)
        self.assertEqual(history["jobs"][0]["steps"][1]["status"], "failed")
        self.assertFalse(self.calls.exists())
        self.assertTrue(
            all(step["status"] == "skipped" for step in history["jobs"][0]["steps"][2:])
        )


if __name__ == "__main__":
    unittest.main()
