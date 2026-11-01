"""Route child effects retain the root signer binding over official SSH."""

import importlib.util
import json
import sys
from pathlib import Path

import yaml

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from tests.route_ssh_fixture import PIN

ROOT = Path(__file__).resolve().parents[1]
_spec = importlib.util.spec_from_file_location(
    "certificate_fixture",
    ROOT / "extensions/behaviors/operations/certificates/native_test.py",
)
_fixture = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(_fixture)
PARENT_RUN = "43e367e0-0760-41a8-a169-84e6e0933e4f"


class RouteCertificateNative(_fixture.Certificates):
    # Standalone root cases remain under their owner; reuse only its TLS host fixture.
    test_fresh_current_and_removal_use_official_ssh_and_one_parent_signer = None
    test_signer_refusal_makes_no_publication_or_hidden_callback = None
    test_native_inspection_resume_preserves_the_key_without_signing_again = None

    def setUp(self):
        _fixture.Certificates.setUp(self)
        self.copy_behaviors("operations/routes/mutation-command")
        dependency = (
            self.repo
            / "extensions/behaviors/operations/routes/publication-command/sections.ts"
        )
        dependency.parent.mkdir(parents=True)
        dependency.write_text(
            (
                ROOT
                / "extensions/behaviors/operations/routes/publication-command/sections.ts"
            ).read_text()
        )
        root = yaml.safe_load(
            (
                self.repo / "workflows/workflow-operation-certificate-converge.yaml"
            ).read_text()
        )
        signer = next(
            s["task"]["inputs"]["run"]
            for s in root["jobs"][0]["steps"]
            if s["task"]["behaviorType"] == "command/shell"
        )
        for mode in ("publish", "remove"):
            workflow = yaml.safe_load(
                (ROOT / f"workflows/workflow-route-certificate-{mode}.yaml").read_text()
            )
            for step in workflow["jobs"][0]["steps"]:
                self.assertEqual(step.pop("target"), "orbit-node-209")
                self.assertNotIn("labels", step)
                task = step["task"]
                if task["behaviorType"] == "@swamp/ssh":
                    task["globalArgs"].update(
                        sshBinary=str(self.tools / "ssh"),
                        scpBinary=str(self.tools / "scp"),
                    )
                if step["name"] == "sign":
                    task["inputs"]["run"] = signer
            raw = yaml.safe_dump(workflow, sort_keys=False).replace(
                "/tmp/orbit-route-", str(self.fixture / "staging-")
            )
            (
                self.repo / f"workflows/workflow-route-certificate-{mode}.yaml"
            ).write_text(raw)

    def run_child(self, mode="publish", restoring=False, success=True):
        leaf = (
            self.leaf
            if mode == "publish"
            else {
                **{
                    k: self.leaf[k]
                    for k in ("node_id", "scope", "user", "agent_sha256")
                },
                "in_use": False,
            }
        )
        value = {
            "operation_id": 19,
            "node_id": 9,
            "kind": "node.add",
            "parent_run_id": PARENT_RUN,
            "restoring": restoring,
            "execution_secrets": {},
            "intent": {
                "leaf": leaf,
                "control_node_id": 209,
                "connection": {
                    "nodeHost": "fixture",
                    "nodeUser": "orbit",
                    "nodePort": 22,
                    "nodeIdentityFile": "/fixture/approved-key",
                    "become": True,
                },
                "host_keys": [PIN],
            },
        }
        reply = json.loads(
            self.cli(
                "workflow",
                "run",
                "route-certificate-" + mode,
                "--input",
                json.dumps(value),
                success=success,
            ).stdout
        )
        history = json.loads(self.cli("workflow", "history", "get", reply["id"]).stdout)
        return history

    def test_publish_current_remove_keep_parent_signer_binding_and_clean_assets(self):
        first = self.run_child(restoring=True)
        self.assertEqual(first["status"], "succeeded")
        self.assertNotEqual(first["id"], PARENT_RUN)
        self.assertEqual(self.signs[0][1]["run_id"], PARENT_RUN)
        self.assertTrue(self.signs[0][1]["restoring"])
        self.assertTrue(first["jobs"][0]["steps"][-1]["outputs"]["ready"])
        self.run_child()
        self.assertEqual(len(self.signs), 1)
        removed = self.run_child("remove")
        self.assertTrue(removed["jobs"][0]["steps"][-1]["outputs"]["ready"])
        self.assertFalse((self.home / ".orbit/certificates/route-7-router").exists())
        self.assertEqual(list(self.fixture.glob("staging-*")), [])

    def test_signer_refusal_skips_publication_and_still_cleans_assets(self):
        self.refuse = True
        failed = self.run_child(success=False)
        self.assertEqual(failed["status"], "failed")
        self.assertEqual(self.signs[0][1]["run_id"], PARENT_RUN)
        self.assertFalse(
            (self.home / ".orbit/certificates/route-7-router/current").exists()
        )
        self.assertEqual(list(self.fixture.glob("staging-*")), [])


class CertificateSignerIsolation(_fixture.harness.NativeTestCase):
    workflow_name = "route-certificate-publish"

    def test_parallel_certificate_children_enter_signer_without_sharing_model_lock(
        self,
    ):
        self.real_certificate_signing = True
        production = yaml.safe_load(
            (ROOT / ("workflows/workflow-" + self.workflow_name + ".yaml")).read_text()
        )
        signer = next(
            s["task"] for s in production["jobs"][0]["steps"] if s["name"] == "sign"
        )
        markers = self.repo / "markers"
        markers.mkdir()
        # A rendezvous proves both commands entered concurrently. A shared model
        # identity makes the first child fail before the second can enter.
        signer["inputs"] = {
            "run": "python3 - <<'BARRIER'\n"
            + """import os,time,json
from pathlib import Path
markers=Path(os.environ['MARKERS'])
(markers/os.environ['CHILD']).touch()
deadline=time.monotonic()+4
while len(list(markers.iterdir()))<2:
    if time.monotonic()>deadline:raise SystemExit('certificate signer serialized')
    time.sleep(0.01)
print(json.dumps({'ready':True,'child':os.environ['CHILD']}))
"""
            + "BARRIER",
            "env": {"MARKERS": str(markers), "CHILD": "${{ run.id }}"},
        }
        child = {
            "id": "1368dd69-d7ac-48d5-9ed4-71d3817c7214",
            "name": "signer-isolation-child",
            "jobs": [{"name": "sign", "steps": [{"name": "sign", "task": signer}]}],
        }
        parent = {
            "id": "5c8783c2-0416-4649-a375-265c045ed37c",
            "name": "signer-isolation-parent",
            "jobs": [
                {
                    "name": "fanout",
                    "steps": [
                        {
                            "name": "leaf",
                            "forEach": {"item": "leaf", "in": "${{ [1,2] }}"},
                            "task": {
                                "type": "workflow",
                                "workflowIdOrName": "signer-isolation-child",
                            },
                        }
                    ],
                }
            ],
        }
        for workflow in (child, parent):
            (
                self.repo / "workflows" / ("workflow-" + workflow["name"] + ".yaml")
            ).write_text(yaml.safe_dump(workflow, sort_keys=False))
        run = json.loads(self.cli("workflow", "run", "signer-isolation-parent").stdout)
        history = json.loads(self.cli("workflow", "history", "get", run["id"]).stdout)
        self.assertEqual(history["status"], "succeeded")
        self.assertEqual(len(list(markers.iterdir())), 2)
        self.assertTrue(
            all(s["status"] == "succeeded" for s in history["jobs"][0]["steps"])
        )


class RoleCertificateSignerIsolation(CertificateSignerIsolation):
    workflow_name = "node-role-certificate-publish"
