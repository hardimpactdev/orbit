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
    ROOT / "extensions/behaviors/operations/certificates/native_fixture.py",
)
_fixture = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(_fixture)
PARENT_RUN = "43e367e0-0760-41a8-a169-84e6e0933e4f"


class RouteCertificateNative(_fixture.Certificates):
    def setUp(self):
        _fixture.Certificates.setUp(self)
        # Official copy returns the public CSR file as well as staging scripts.
        (self.tools / "scp").write_text(
            "#!"
            + sys.executable
            + "\n"
            + """import shutil,sys
from pathlib import Path
def local(value):return Path(value.split(':',1)[-1])
src,dst=map(local,sys.argv[-2:])
if src.is_dir():shutil.copytree(src,dst/src.name,dirs_exist_ok=True)
else:shutil.copyfile(src,dst)
"""
        )
        ssh = self.tools / "ssh"
        ssh.write_text(
            ssh.read_text().replace(
                "any(str(a).endswith('/inspect.py') for a in argv)",
                "(any(str(a).endswith('/inspect.py') for a in argv) or (any(str(a).endswith('/node_batch.py') for a in argv) and 'inspect' in argv))",
            )
        )
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
            raw = yaml.safe_dump(workflow, sort_keys=False).replace(
                "/tmp/orbit-route-", str(self.fixture / "staging-")
            )
            (
                self.repo / f"workflows/workflow-route-certificate-{mode}.yaml"
            ).write_text(raw)

    def run_child(
        self,
        mode="publish",
        restoring=False,
        success=True,
        leaves=None,
        host_keys=None,
        raw=False,
    ):
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
                **(
                    {
                        "node_id": leaf["node_id"],
                        "leaves": leaves or [leaf, {**leaf, "scope": "route-8-router"}],
                    }
                    if mode == "publish"
                    else {"leaf": leaf}
                ),
                "control_node_id": 209,
                "connection": {
                    "nodeHost": "fixture",
                    "nodeUser": "orbit",
                    "nodePort": 22,
                    "nodeIdentityFile": "/fixture/approved-key",
                    "become": True,
                },
                "host_keys": [PIN] if host_keys is None else host_keys,
            },
        }
        response = self.cli(
            "workflow",
            "run",
            "route-certificate-" + mode,
            "--input",
            json.dumps(value),
            success=success,
        )
        if raw:
            return response
        reply = json.loads(response.stdout)
        history = json.loads(self.cli("workflow", "history", "get", reply["id"]).stdout)
        return history

    def test_missing_pins_refuse_before_any_canonical_child_effect(self):
        result = self.run_child(success=False, host_keys=[], raw=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(self.signs, [])
        self.assertEqual(list(self.fixture.glob("staging-*")), [])

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

    def test_signer_refusal_skips_publication_and_retains_assets_for_resume(self):
        self.refuse = True
        failed = self.run_child(success=False)
        self.assertEqual(failed["status"], "failed")
        self.assertEqual(self.signs[0][1]["run_id"], PARENT_RUN)
        self.assertFalse(
            (self.home / ".orbit/certificates/route-7-router/current").exists()
        )
        self.assertTrue(list(self.fixture.glob("staging-*")))

    def test120_real_csrs_use_one_signing_request_without_large_environment_values(
        self,
    ):
        leaves = [
            {**self.leaf, "scope": f"route-{index + 1}-router"} for index in range(120)
        ]
        result = self.run_child(leaves=leaves)
        self.assertEqual(result["status"], "succeeded")
        self.assertEqual(len(self.signs), 1)
        self.assertEqual(len(self.signs[0][1]["requests"]), 120)
        self.assertGreater(len(json.dumps(self.signs[0][1])), 131072)
        steps = result["jobs"][0]["steps"]
        self.assertEqual(
            len([step for step in steps if step["name"] == "copy-assets"]), 1
        )
        self.assertEqual(len(steps[-1]["outputs"]["leaves"]), 120)

    def test_partial_publication_refuses_parent_success_and_resumes_without_signing_again(
        self,
    ):
        marker = self.fixture / "fail-second-publish"
        marker.touch()
        script = (
            self.repo
            / "extensions/behaviors/operations/certificates/scripts/publish.py"
        )
        script.write_text(
            script.read_text().replace(
                "def publish(leaf):",
                "def publish(leaf):\n    if leaf['scope'] == 'route-8-router' and __import__('pathlib').Path("
                + repr(str(marker))
                + ").exists():\n        raise ValueError('certificate.fixture_publish_failed')",
            )
        )
        failed = self.run_child(success=False)
        self.assertEqual(failed["status"], "failed")
        publication = next(
            step for step in failed["jobs"][0]["steps"] if step["name"] == "publish"
        )
        self.assertIn("route-8-router", publication["error"])
        self.assertIn("certificate.fixture_publish_failed", publication["error"])
        self.assertFalse(
            any(
                step["name"] == "result" and step["status"] == "succeeded"
                for step in failed["jobs"][0]["steps"]
            )
        )
        first = self.home / ".orbit/certificates/route-7-router/current/key.pem"
        before = first.read_bytes()
        self.assertFalse(
            (self.home / ".orbit/certificates/route-8-router/current").exists()
        )
        marker.unlink()
        result = json.loads(
            self.cli(
                "workflow",
                "resume",
                "route-certificate-publish",
                "--run",
                failed["id"],
                "--from",
                "publish",
            ).stdout
        )
        self.assertEqual(result["status"], "succeeded")
        self.assertEqual(first.read_bytes(), before)
        self.assertEqual(len(self.signs), 1)
        history = json.loads(
            self.cli("workflow", "history", "get", result["id"]).stdout
        )
        terminal = next(
            step for step in history["jobs"][0]["steps"] if step["name"] == "result"
        )
        self.assertEqual(len(terminal["outputs"]["leaves"]), 2)

    def test_inspection_resume_reuses_published_keys_and_does_not_sign_again(self):
        marker = self.fixture / "fail-inspect"
        marker.touch()
        failed = self.run_child(success=False)
        key = self.home / ".orbit/certificates/route-7-router/current/key.pem"
        before = key.read_bytes()
        self.assertTrue(list(self.fixture.glob("staging-*")))
        self.assertEqual(len(self.signs), 1)
        self.assertEqual(len(self.signs[0][1]["requests"]), 2)
        marker.unlink()
        resumed = json.loads(
            self.cli(
                "workflow",
                "resume",
                "route-certificate-publish",
                "--run",
                failed["id"],
                "--from",
                "inspect",
            ).stdout
        )
        self.assertEqual(resumed["status"], "succeeded")
        self.assertEqual(key.read_bytes(), before)
        self.assertEqual(len(self.signs), 1)
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
