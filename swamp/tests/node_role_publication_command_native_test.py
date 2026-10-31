"""Role duty selection, empty fan-out and failure ordering on the official native runtime."""

import json
import os
import shutil
import subprocess

import yaml
from native_harness import ROOT, NativeTestCase


class RolePublicationNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.copy_behaviors("operations/routes/mutation-command")
        self.copy_behaviors("operations/routes/publication-command")
        # These children test parent composition. Canonical host effects have their own fixtures.
        for name in ("operation-route-php-delta", "operation-route-caddy-delta", "route-dns-publish", "route-dns-grace"):
            script = "printf '{\"ready\":true}\\n'"
            if name == "operation-route-caddy-delta":
                script = 'test "$FAIL_CADDY" != true\n' + script
            workflow = {
                "id": {
                    "operation-route-php-delta": "83bf717d-d45b-4e1a-8c31-416463aa6957",
                    "operation-route-caddy-delta": "de76428a-d677-49e0-b806-7bc05ebdfce8",
                    "route-dns-publish": "6e5b0bb9-d1f2-4544-9f85-e128bfcf59aa",
                    "route-dns-grace": "0f8c33b8-c6ac-4a5f-9623-5d31ade63a25",
                }[name],
                "name": name, "inputs": {"type": "object"},
                "jobs": [{"name": "effect", "steps": [{"name": "effect", "task": {
                    "type": "behavior", "behaviorType": "command/shell", "behaviorName": name + "-fixture",
                    "methodName": "execute", "inputs": {
                        "run": "set -eu\n" + script,
                        "env": {"FAIL_CADDY": "${{ string(inputs.intent.fail_caddy) }}"} if name == "operation-route-caddy-delta" else {},
                    },
                }}]}],
            }
            # The Caddy intent remains the production schema. Fault injection belongs only to the fixture.
            if name == "operation-route-caddy-delta":
                workflow["jobs"][0]["steps"][0]["task"]["inputs"]["env"] = {
                    "FAIL_CADDY": "${{ inputs.intent.before.content == 'fail-caddy' ? 'true' : 'false' }}",
                }
            (self.repo / ("workflows/workflow-" + name + ".yaml")).write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.parent()

    def parent(self, recovery=False):
        source = (ROOT / "extensions/behaviors/operations/node-role-converge-command/publication_steps.ts").as_uri()
        deno = os.environ.get("DENO_BIN", shutil.which("deno") or "deno")
        program = (
            "import {rolePublicationSteps} from " + json.dumps(source) + ";"
            "const steps=rolePublicationSteps('publish','inputs.intent','forward');"
            + ("steps.push(...rolePublicationSteps('restore','inputs.intent','restore','publish',"
               + json.dumps('steps.exists(key, key == "publish-0" && steps[key].status == "failed")') + "));" if recovery else "")
            + "console.log(JSON.stringify(steps));"
        )
        steps = json.loads(subprocess.run([deno, "eval", program], check=True, capture_output=True, text=True).stdout)
        for step in steps:
            self.assertEqual(step.pop("target"), "orbit-node-209")
            self.assertEqual(step.pop("labels"), {"node": "209"})
        workflow = {
            "id": "0a6b4b0c-f543-4c60-bcbf-05a4fcbfa977", "name": "role-publication-fixture",
            "inputs": {"type": "object", "properties": {
                "intent": {"type": "object"}, "operation_id": {"type": "integer"},
                "node_id": {"type": "integer"}, "kind": {"type": "string"},
            }, "required": ["intent", "operation_id", "node_id", "kind"]},
            "jobs": [{"name": "publication", "steps": steps}],
        }
        (self.repo / "workflows/workflow-role-publication-fixture.yaml").write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.cli("workflow", "validate", "role-publication-fixture")

    def row(self, caddy=None, php=None, dns=None):
        return {
            "node_id": 9, "control_node_id": 209,
            "connection": {"nodeUser": "orbit", "nodeHost": "10.44.0.9", "nodePort": 22, "nodeIdentityFile": "/identity"},
            "host_keys": ["public-fixture-pin"],
            "before": {"caddy": caddy, "php": php, "dns": dns, "certificate_scopes": []},
            "after": {"caddy": caddy, "php": php, "dns": dns, "certificate_scopes": []},
        }

    def run_publication(self, rows, success=True):
        path = self.repo / "inputs.json"
        path.write_text(json.dumps({"operation_id": 91, "node_id": 9, "kind": "node.role_converge", "intent": {"publications": rows}}))
        run = json.loads(self.cli("workflow", "run", "role-publication-fixture", "--input-file", str(path), success=success).stdout)
        return json.loads(self.cli("workflow", "history", "get", run["id"]).stdout)

    def test_empty_duties_finish_with_a_zero_contribution_proof(self):
        history = self.run_publication([])

        self.assertEqual(history["status"], "succeeded")
        steps = {step["name"]: step for step in history["jobs"][0]["steps"]}
        result = steps["publish-0"]["outputs"]
        self.assertTrue(result["ready"])
        self.assertEqual(result["contributions"], {"publish_php": 0, "publish_caddy": 0, "publish_dns": 0})

    def test_dns_only_participant_does_not_run_php_or_caddy(self):
        history = self.run_publication([self.row(dns={"records": "public", "catalog": "{}", "digest": "a" * 64})])

        self.assertEqual(history["status"], "succeeded")
        steps = {step["name"]: step for step in history["jobs"][0]["steps"]}
        self.assertNotIn("publish_php-0", steps)
        self.assertNotIn("publish_caddy-0", steps)
        self.assertEqual(steps["publish_dns-0"]["status"], "succeeded")
        self.assertTrue(steps["publish-0"]["outputs"]["ready"])

    def test_caddy_only_participant_does_not_withdraw_php_pools(self):
        history = self.run_publication([self.row(caddy={"content": "owned"})])

        self.assertEqual(history["status"], "succeeded")
        steps = {step["name"]: step for step in history["jobs"][0]["steps"]}
        self.assertNotIn("publish_php-0", steps)
        self.assertNotIn("publish_dns-0", steps)
        self.assertEqual(steps["publish_caddy-0"]["status"], "succeeded")

    def test_failed_caddy_prevents_dns_and_cannot_emit_a_complete_contribution(self):
        history = self.run_publication([self.row(caddy={"content": "fail-caddy"}, dns={"digest": "a" * 64})], success=False)

        self.assertEqual(history["status"], "failed")
        steps = {step["name"]: step for step in history["jobs"][0]["steps"]}
        self.assertEqual(steps["publish_caddy-0"]["status"], "failed")
        self.assertEqual(steps["publish_dns-0"]["status"], "skipped")
        self.assertEqual(steps["publish-0"]["status"], "failed")

    def test_restore_can_complete_after_failed_forward_and_keeps_its_dns_grace_order(self):
        self.parent(recovery=True)
        row = self.row(caddy={"content": "fail-caddy"}, dns={"digest": "a" * 64})
        row["after"] = {**row["after"], "caddy": {"content": "owned"}, "dns": {"digest": "b" * 64}}
        history = self.run_publication([row], success=False)

        steps = {step["name"]: step for step in history["jobs"][0]["steps"]}
        self.assertEqual(steps["publish_caddy-0"]["status"], "failed")
        for name in ("restore_prepare_caddy-0", "restore_dns-0", "restore_dns_grace-0", "restore_caddy-0", "restore-0"):
            self.assertIn(name, steps, {key: step["status"] for key, step in steps.items()})
            self.assertEqual(steps[name]["status"], "succeeded", name)
        self.assertTrue(steps["restore-0"]["outputs"]["restored"])
