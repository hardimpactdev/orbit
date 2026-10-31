"""Role-root fan-out through the canonical real SSH/OpenSSL/HTTPS certificate child."""

import http.server
import importlib.util
import json
import os
import shutil
import subprocess
from unittest.mock import patch

import yaml
from native_harness import ROOT

certificate_spec = importlib.util.spec_from_file_location(
    "role_certificate_fixture",
    ROOT / "extensions/behaviors/operations/certificates/role_native_test.py",
)
fixture = importlib.util.module_from_spec(certificate_spec)
certificate_spec.loader.exec_module(fixture)


class RoleCertificatesNative(fixture.Roles):
    def setUp(self):
        # The inherited signer writes one fixed CSR path; serialize fixture HTTP requests.
        with patch("http.server.ThreadingHTTPServer", http.server.HTTPServer):
            super().setUp()
        (self.target / "role-keys").mkdir(mode=0o700)
        self.copy_behaviors("operations/node-role-converge-command")
        source = (ROOT / "extensions/behaviors/operations/node-role-converge-command/certificate_steps.ts").as_uri()
        deno = os.environ.get("DENO_BIN", shutil.which("deno") or "deno")
        program = "import {roleCertificatesSteps} from " + json.dumps(source) + ";console.log(JSON.stringify(roleCertificatesSteps('certificates','inputs.intent')));"
        steps = json.loads(subprocess.run([deno, "eval", program], capture_output=True, text=True, check=True).stdout)
        for step in steps:
            self.assertEqual(step.pop("target"), "orbit-node-209")
            self.assertEqual(step.pop("labels"), {"node": "209"})
            if step["task"]["type"] == "workflow":
                step["task"]["inputs"]["ssh"]["sshBinary"] = str(self.tools / "ssh")
        parent = {
            "id": "5a1775e8-412b-4532-97f0-72c40f72bc41", "name": "role-root-certificates-fixture",
            "inputs": {"type": "object", "properties": {
                "operation_id": {"type": "integer"}, "node_id": {"type": "integer"},
                "kind": {"type": "string"}, "intent": {"type": "object"},
            }},
            "jobs": [{"name": "certificates", "steps": steps}],
        }
        (self.repo / "workflows/workflow-role-root-certificates-fixture.yaml").write_text(yaml.safe_dump(parent, sort_keys=False))
        self.cli("workflow", "validate", "role-root-certificates-fixture")

    def run_leaves(self, leaves, success=True):
        descriptor = {
            "control_node_id": 209, "identity_file": str(self.fixture / "identity"),
            "target": {"name": "target", "host": "fixture", "user": "orbit", "port": 22,
                       "host_keys": self.globals["hosts"][0]["transport"]["hostKeys"]},
        }
        path = self.repo / "role-input.json"
        path.write_text(json.dumps({
            "operation_id": 19, "node_id": 9, "kind": "node.role_converge",
            "intent": {"certificates": {"leaves": leaves, "withdrawals": []},
                       "targets": {str(leaf["node_id"]): descriptor for leaf in leaves}},
        }))
        run = json.loads(self.cli("workflow", "run", "role-root-certificates-fixture", "--input-file", str(path), success=success).stdout)
        return run, json.loads(self.cli("workflow", "history", "get", run["id"]).stdout)

    def test_two_leaves_keep_real_root_identity_and_nested_contributions(self):
        run, history = self.run_leaves([self.leaf, {**self.leaf, "scope": "role-websocket", "node_id": 10}])
        self.assertEqual(history["status"], "succeeded")
        self.assertEqual(len(self.signs), 2)
        self.assertTrue(all(body["run_id"] == run["id"] for _, body in self.signs))
        steps = {step["name"]: step for step in history["jobs"][0]["steps"]}
        for index in range(2):
            self.assertTrue(steps[f"certificates_leaf-{index}"]["outputs"]["result"]["ready"])
        self.assertEqual(steps["certificates-0"]["outputs"], {
            "ready": True, "publication_phases": [
                {"phase": "certificates_published", "node_id": 9},
                {"phase": "certificates_published", "node_id": 10},
            ],
        })

    def test_empty_leaf_plan_proves_zero_without_a_child_or_signer_call(self):
        _, history = self.run_leaves([])
        self.assertEqual(history["status"], "succeeded")
        self.assertEqual(self.signs, [])
        steps = {step["name"]: step for step in history["jobs"][0]["steps"]}
        self.assertEqual(set(steps), {"certificates-0"})
        self.assertEqual(steps["certificates-0"]["outputs"], {"ready": True, "publication_phases": []})

    def test_failed_leaf_cannot_emit_publication_contribution(self):
        self.refuse = True
        _, history = self.run_leaves([self.leaf], success=False)
        self.assertEqual(history["status"], "failed")
        steps = {step["name"]: step for step in history["jobs"][0]["steps"]}
        self.assertEqual(steps["certificates_leaf-0"]["status"], "failed")
        self.assertEqual(steps["certificates-0"]["status"], "failed")
        self.assertFalse(self.current.exists())

    # Canonical child cases are covered by the supplier's own native fixture.
    test_role_fresh_current_and_actual_parent_signing = None
    test_actual_parent_composes_child_and_receives_flat_readiness = None
    test_activation_failure_restores_prior_pair_and_does_not_claim_ready = None
    test_pending_pair_fresh_admission_does_not_skip_activation = None
    test_role_signing_refusal_and_missing_pins_prevent_publication = None
