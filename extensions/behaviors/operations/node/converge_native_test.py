"""Exercise the pinned native runner, node workflow and fresh-process recovery."""
import json
from pathlib import Path
import sys
import unittest

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / "tests"))
from native_harness import NativeTestCase


class NodeConvergeWorkflowTest(NativeTestCase):
    native_timeout = 45

    def setUp(self):
        super().setUp()
        self.copy_behaviors("automatic-deploy", "operation-runner", "operations/node")
        for name, factory, relative in (
            ("operation-runner", "operationDiscovery", "../operations/node/converge_native_fixture.ts"),
            ("operations/node", "node", "./converge_native_fixture.ts"),
        ):
            path = self.repo / "extensions/behaviors" / name / "mod.ts"
            source = path.read_text().replace(f"{factory}()", f"{factory}(() => fixtureHttp)")
            path.write_text(f'import {{ fixtureHttp }} from "{relative}";\n' + source)
        for name in ("operation-node",):
            source = (ROOT / "workflows" / f"workflow-{name}.yaml").read_text()
            # Process recovery is local; the shipped workflow keeps worker placement.
            source = "\n".join(line for line in source.splitlines()
                               if not line.startswith(("labels:", "queueTimeout:"))) + "\n"
            (self.repo / "workflows" / f"workflow-{name}.yaml").write_text(source)
        self.state = {"operation": {
            "id": 7, "kind": "node.settings", "node_id": 2,
            "status": "queued", "cancel_requested": False, "step_results": {}, "steps": [],
            "input": {"node_id": 2, "root": "/srv/apps", "user": "orbit", "group": "orbit", "create_missing": True},
        }, "runs": 0, "keys": [], "completions": [], "receipt": None,
            "loseRunReply": False, "loseCompletionReply": False}
        self.save()


    def save(self):
        (self.repo / "native-node.json").write_text(json.dumps(self.state))


    def read(self):
        return json.loads((self.repo / "native-node.json").read_text())


    def swamp(self, *args, success=True):
        if args[:2] == ("workflow", "resume"):
            args = (*args[:3], "--run", self.last_run_id)
        result = self.cli(*args, success=success)
        if args[:2] == ("workflow", "run"):
            value = json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]
            self.last_run_id = value.get("id")
        return result


    def footprint(self, artifact=None, child=None):
        self.state["operation"]["kind"] = "node.converge"
        self.state["operation"]["input"] = {"force_artifacts": False, "first_visit": False, "_footprint": {
            "agent_pin": "a" * 64, "artifacts": [{"name": artifact, "digest": "digest", "required": True, "repair": False}] if artifact else [],
            "payloads": {"tmpfiles": {"rule": "managed rule"}} if artifact == "tmpfiles" else {},
            "children": {artifact: {"kind": child, "node_id": 2, "input": {"removed_node_id": None}}} if child else {},
        }}
        self.state["operation"]["state"] = {"child_intents": [{"key": "footprint-" + name, **intent}
            for name, intent in self.state["operation"]["input"]["_footprint"]["children"].items()]}
        self.state["footprintResult"] = {"footprint_proven": True, "artifacts": [], "skipped": [], "digest": "digest", "changed": False}


    def test_protected_agent_recovery_precedes_terminal_unavailable_collector(self):
        self.footprint()
        snapshot = self.state["operation"]["input"]["_footprint"]
        snapshot["artifacts"] = [
            {"name": "agent", "digest": "agent", "required": True, "repair": False},
            {"name": "ai-router", "digest": "collector", "required": True, "repair": False,
             "error_code": "operation.kind_unavailable"},
            {"name": "tmpfiles", "digest": "tmp", "required": True, "repair": False},
        ]
        snapshot["payloads"] = {"agent": {
            "secret_hash": {"$secret": "node.agent_secret_hash", "id": 2},
            "worker_credentials": {"$secret": "node.worker_credentials", "id": 2},
        }}
        self.state["footprintResult"] = {
            "footprint_proven": True,
            "artifacts": [{"name": "agent", "status": "applied", "reason": None}],
            "skipped": [], "error_code": "operation.kind_unavailable",
        }
        self.state["loseRunReplyKey"] = "footprint-agent"
        self.save()
        self.swamp("workflow", "run", "operation-node", success=False)
        self.assertEqual(self.read()["runs"], 2)
        self.assertEqual(self.read()["completions"], [])
        self.swamp("workflow", "resume", "operation-node", "--from", "execute")
        state = self.read()
        self.assertEqual(state["runs"], 2)
        self.assertEqual(state["keys"].count("footprint-agent"), 1)
        self.assertNotIn("footprint-tmpfiles", state["keys"])
        self.assertEqual(state.get("children", []), [])
        self.assertEqual(state["operation"]["status"], "failed")
        self.assertEqual(state["operation"]["error_code"], "operation.kind_unavailable")
        self.assertEqual(state["operation"]["result"], self.state["footprintResult"])


    def test_pending_child_releases_parent_worker_and_resumes_in_a_new_process(self):
        self.footprint("route-residue", "route.offline_residue_clean")
        self.state["childStatus"] = "waiting"
        self.save()
        self.swamp("workflow", "run", "operation-node")
        state = self.read()
        self.assertEqual(state["operation"]["status"], "waiting")
        self.assertEqual(state["waiting_for"], ["footprint-route-residue"])
        self.assertEqual(state["completions"], [])
        self.assertEqual(len(state["children"]), 1)
        # Simulate Kit B admitting a new lease after its exact child settles.
        state["children"][0]["status"] = "succeeded"
        state["operation"]["status"] = "queued"
        self.state = state
        self.save()
        self.swamp("workflow", "run", "operation-node")
        state = self.read()
        self.assertEqual(state["operation"]["status"], "succeeded")
        self.assertEqual(len(state["children"]), 1)
        self.assertEqual(len(state["completions"]), 1)


    def test_converge_recovers_a_child_admission_reply_from_parent_evidence(self):
        self.footprint("route-residue", "route.offline_residue_clean")
        self.state["loseChildReply"] = True
        self.save()
        self.swamp("workflow", "run", "operation-node", success=False)
        self.assertEqual(len(self.read()["children"]), 1)
        self.swamp("workflow", "resume", "operation-node", "--from", "execute")
        state = self.read()
        self.assertEqual(len(state["children"]), 1)
        self.assertEqual(state["children"][0]["key"], "footprint-route-residue")
        self.assertEqual(state["operation"]["status"], "succeeded")


    def test_converge_reads_a_target_receipt_after_a_lost_reply(self):
        self.footprint("tmpfiles")
        self.state["loseRunReplyKey"] = "footprint-tmpfiles"
        self.save()
        self.swamp("workflow", "run", "operation-node", success=False)
        self.assertEqual(self.read()["runs"], 1)
        self.swamp("workflow", "resume", "operation-node", "--from", "execute")
        state = self.read()
        self.assertEqual(state["runs"], 1)
        self.assertEqual(state["keys"].count("footprint-tmpfiles"), 1)
        self.assertEqual(state["operation"]["status"], "succeeded")


    def test_private_dns_waits_for_proven_listener_setup_after_a_lost_reply(self):
        self.footprint("private-dns", "route.private_dns_publish")
        snapshot = self.state["operation"]["input"]["_footprint"]
        snapshot["payloads"]["private-dns-listener"] = {"release_id": "rendered"}
        snapshot["children"]["private-dns"]["input"] = {"reason": "native-proof"}
        self.state["operation"]["state"]["child_intents"][0]["input"] = {"reason": "native-proof"}
        self.state["loseRunReplyKey"] = "footprint-private-dns-listener"
        self.save()
        self.swamp("workflow", "run", "operation-node", success=False)
        self.assertEqual(self.read().get("children", []), [])
        self.swamp("workflow", "resume", "operation-node", "--from", "execute")
        state = self.read()
        self.assertEqual(state["runs"], 1)
        self.assertEqual(len(state["children"]), 1)
        self.assertEqual(state["operation"]["status"], "succeeded")


    def test_php_versions_recover_lost_reply_in_a_fresh_process(self):
        self.footprint("php-runtime")
        self.state["operation"]["input"]["_footprint"]["payloads"]["php-runtime"] = {
            "configurations": [{"version": version, "runtime_ini": "Gateway-rendered module",
                                "release": {"id": "ubuntu", "codenames": ["resolute"]}}
                               for version in ["8.4", "8.5"]]}
        self.state["loseRunReplyKey"] = "footprint-php-runtime-8.5"
        self.save()
        self.swamp("workflow", "run", "operation-node", success=False)
        self.assertEqual(self.read()["runs"], 2)
        self.swamp("workflow", "resume", "operation-node", "--from", "execute")
        state = self.read()
        self.assertEqual(state["runs"], 2)
        self.assertEqual(state["keys"].count("footprint-php-runtime-8.4"), 1)
        self.assertEqual(state["keys"].count("footprint-php-runtime-8.5"), 1)
        self.assertEqual(state["operation"]["status"], "succeeded")


    def test_bootstrap_installer_recovers_a_lost_reply_without_a_second_install(self):
        self.state["operation"]["kind"] = "node.bootstrap_install"
        self.state["operation"]["input"] = {
            "bootstrap_host": "192.0.2.8", "bootstrap_port": 2222,
            "bootstrap_fingerprint": "SHA256:" + "A" * 43, "bootstrap_user": "root",
            "bootstrap_identity": {"$secret": "node.bootstrap_ssh_identity", "id": 2},
            "agent_pin": "a" * 64, "command_input": []}
        self.state["loseRunReplyKey"] = "agent-install"
        self.save()
        self.swamp("workflow", "run", "operation-node", success=False)
        self.assertEqual(self.read()["runs"], 1)
        self.swamp("workflow", "resume", "operation-node", "--from", "execute")
        state = self.read()
        self.assertEqual(state["runs"], 1)
        self.assertEqual(state["keys"].count("agent-install"), 1)
        self.assertEqual(state["operation"]["status"], "succeeded")


if __name__ == "__main__":
    unittest.main()
