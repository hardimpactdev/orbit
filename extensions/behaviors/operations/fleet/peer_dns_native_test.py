"""Pinned native peer DNS workflow; optional real Node-local inspection canary."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[4]
SWAMP = os.environ.get("SWAMP_BIN")


@unittest.skipUnless(SWAMP, "requires the pinned native Swamp binary")
class PeerDnsNative(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="orbit-peer-dns-direct-")
        self.addCleanup(self.temp.cleanup)
        self.repo = Path(self.temp.name)
        shutil.copy(ROOT / ".swamp.yaml", self.repo)
        for family in ("automatic-deploy", "operation-runner", "operations/_lib", "operations/fleet"):
            shutil.copytree(ROOT / "extensions/behaviors" / family,
                            self.repo / "extensions/behaviors" / family)
        module = self.repo / "extensions/behaviors/operations/fleet/peer_dns.ts"
        source = module.read_text().replace(
            '...peerDns()',
            '...peerDns(() => fixtureHttp, fixtureAgent)')
        module.write_text('import { fixtureHttp, fixtureAgent } from "./peer_dns_native_fixture.ts";\n' + source)
        workflow = ROOT / "workflows/workflow-operation-fleet-peer-dns.yaml"
        target = self.repo / "workflows" / workflow.name
        target.parent.mkdir()
        # The dev-host process owns this local canary. Shipped placement stays exact.
        target.write_text("\n".join(line for line in workflow.read_text().splitlines()
                                    if not line.lstrip().startswith(("target:", "labels:"))) + "\n")
        self.input = {"node_id": 2, "agent_sha256": "a" * 64, "server": "10.44.0.1",
                      "dns_server": "10.44.0.1", "public_key": "A" * 43 + "=", "domains": ["orbit"]}
        healthy = {"state": "orbit\n10.44.0.1\norbit", "hooks": "PostUp = resolvectl dns %i 10.44.0.1; resolvectl domain %i \\~orbit",
                   "dns": "Link 2 (orbit): 10.44.0.1", "domains": "Link 2 (orbit): ~orbit",
                   "default_route": "Link 2 (orbit): no", "uplink": True}
        self.state = {"operation": {"id": 7, "kind": "fleet.peer_dns", "node_id": 2,
                                   "input": self.input, "status": "queued", "result": None, "cancel_requested": False},
                      "healthy": healthy, "observed": {**healthy, "state": "drift"},
                      "calls": [], "projections": [], "lose": False, "deny": False, "real": False}
        self.save()

    def save(self):
        (self.repo / "peer-native.json").write_text(json.dumps(self.state))

    def read(self):
        return json.loads((self.repo / "peer-native.json").read_text())

    def cli(self, *args, success=True):
        env = {key: value for key, value in os.environ.items() if key not in {"SWAMP_SERVE_URL", "SWAMP_SERVER_URL", "SWAMP_SERVER_TOKEN", "SWAMP_SERVER_TOKEN_FILE", "SWAMP_REPO_DIR", "SWAMP_CA_CERT"}}
        env.update(SWAMP_NO_UPDATE_CHECK="1", SWAMP_NO_TELEMETRY="1", SWAMP_NO_INBOX_NOTICE="1")
        result = subprocess.run([SWAMP, *args, "--json"], cwd=self.repo, env=env,
                                text=True, capture_output=True, timeout=60)
        self.assertEqual(result.returncode == 0, success, result.stdout + result.stderr)
        return json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]

    def run_workflow(self, success=True):
        self.cli("workflow", "validate", "operation-fleet-peer-dns")
        return self.cli("workflow", "run", "operation-fleet-peer-dns", "--input", json.dumps({
            "operation_id": 7, "node_id": self.state["operation"]["node_id"], "kind": "fleet.peer_dns"}), success=success)["id"]

    def test_repairs_and_projects_without_a_claim_or_gateway_execution(self):
        self.run_workflow()
        state = self.read()
        self.assertEqual(state["operation"]["status"], "succeeded")
        self.assertEqual([call["name"] for call in state["calls"]], [
            "wireguard.inspect-peer-dns", "wireguard.repair-peer-dns", "wireguard.inspect-peer-dns"])

    def test_lost_projection_resumes_without_another_repair(self):
        self.state["lose"] = True
        self.save()
        run = self.run_workflow(success=False)
        self.cli("workflow", "resume", "operation-fleet-peer-dns", "--run", run)
        self.assertEqual(sum(call["mutates"] for call in self.read()["calls"]), 1)
        self.assertEqual(self.read()["operation"]["status"], "succeeded")

    def test_foreign_binding_has_no_local_action(self):
        self.state["deny"] = True
        self.save()
        self.run_workflow(success=False)
        self.assertEqual(self.read()["calls"], [])

    def test_actual_refusal_envelopes_terminalize_without_diagnostics(self):
        for name, code in (("wireguard.inspect-peer-dns", "wireguard.peer_dns_unreadable"),
                           ("wireguard.repair-peer-dns", "wireguard.peer_dns_repair_failed")):
            with self.subTest(code=code):
                self.state["refusal"] = {"name": name, "code": code}
                self.save()
                self.run_workflow(success=False)
                state = self.read()
                self.assertEqual(state["operation"]["status"], "failed")
                self.assertEqual(state["operation"]["result"], {"error_code": code.replace("wireguard.", "vpn.")})
                self.assertNotIn("private native diagnostic", json.dumps(state["projections"]))

    @unittest.skipUnless(os.environ.get("ORBIT_PEER_DNS_REAL_INTENT"), "explicit real dev-node canary input required")
    def test_real_node_inspection_of_its_current_admitted_policy(self):
        self.state["operation"]["input"] = json.loads(Path(os.environ["ORBIT_PEER_DNS_REAL_INTENT"]).read_text())
        self.state["operation"]["node_id"] = self.state["operation"]["input"]["node_id"]
        self.state["real"] = True
        self.save()
        run = self.run_workflow()
        state = self.read()
        self.assertEqual(state["operation"]["status"], "succeeded")
        self.assertEqual(state["operation"]["result"]["uplink"], True)
        self.assertEqual([call["mutates"] for call in state["calls"]], [False])
        output = os.environ.get("ORBIT_PEER_DNS_CANARY_PROOF")
        if output:
            Path(output).write_text(json.dumps({"native_run_id": run, "node_id": state["operation"]["node_id"],
                                               "calls": state["calls"], "status": state["operation"]["status"],
                                               "result": state["operation"]["result"],
                                               "gateway_boundary": "disposable fixture; live worker/Gateway cutover still required"}, indent=2) + "\n")
