"""Run the actual inline command workflow on the pinned native runtime, locally."""
import base64
import json
import os
import shutil
import tempfile
from pathlib import Path

import yaml
from native_harness import NativeTestCase

ROOT = Path(__file__).resolve().parents[1]


class NetworkPeerCommandNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        host = tempfile.TemporaryDirectory(prefix="orbit-network-host-", dir=os.environ.get("TMPDIR"))
        self.addCleanup(host.cleanup)
        self.host = Path(host.name)
        self.wireguard = self.host / "wireguard"
        self.wireguard.mkdir(mode=0o700)
        self.config = self.host / "config.toml"
        self.config.write_text("[swamp_worker]\nnode_id = 13\nuser = 'orbit'\n")
        self.private = base64.b64encode(os.urandom(32)).decode()
        self.public = base64.b64encode(b"q" * 32).decode()
        self.key_source = self.host / "fixture-private"
        self.key_source.write_text(self.private)
        self.key_source.chmod(0o600)
        self.secrets = (self.private,)
        tools = self.host / "bin"
        tools.mkdir()
        self.native_env = {"PATH": str(tools) + os.pathsep + os.environ["PATH"]}
        sudo = tools / "sudo"
        sudo.write_text('#!/bin/sh\n[ "$1" = -n ] && shift\n[ "$1" = -- ] && shift\nexec "$@"\n')
        sudo.chmod(0o755)
        wg = tools / "wg"
        wg.write_text('#!/bin/sh\ncase "$1" in\n genkey) cat ' + str(self.key_source) + ';;\n pubkey) cat >/dev/null; printf "%s\\n" ' + self.public + ';;\n *) exit 2;;\nesac\n')
        wg.chmod(0o755)
        destination = self.repo / "extensions/behaviors/operations/node"
        destination.mkdir(parents=True)
        shutil.copy(ROOT / "extensions/behaviors/operations/node/network_command_inputs.ts", destination)
        self.workflow = yaml.safe_load((ROOT / "workflows/workflow-node-network-peer-prepare.yaml").read_text())
        for job in self.workflow["jobs"]:
            for step in job["steps"]:
                step.pop("target", None)
                step.pop("labels", None)
                if step["task"].get("behaviorType") == "command/shell":
                    inputs = step["task"]["inputs"]
                    inputs["run"] = inputs["run"].replace('/etc/orbit/agent/config.toml', str(self.config)).replace('/etc/wireguard', str(self.wireguard)).replace('/usr/bin/wg', str(wg))
                    inputs["run"] = inputs["run"].replace("metadata.st_uid != 0", f"metadata.st_uid != {os.getuid()}")
        (self.repo / "workflows/workflow-node-network-peer-prepare.yaml").write_text(yaml.safe_dump(self.workflow, sort_keys=False))
        self.intent = {"schema_version": 1, "node_id": 13, "hub_id": 1, "agent_sha256": "a" * 64,
                       "existing_key": None, "architecture": "x86_64",
                       "peer": {"server_public_key": base64.b64encode(b"h" * 32).decode(),
                                "endpoint": "vpn.example.test:51820", "address": "10.44.0.13/24", "subnet": "10.44.0.0/24",
                                "dns_server": "10.44.0.1", "domains": ["orbit"], "operator_dns": None, "retain": False},
                       "trust": {"desired": [], "remove": [], "enable": False}}

    def run_peer(self, success=True):
        return self.cli("workflow", "run", "node-network-peer-prepare", "--stdin",
                        input=json.dumps({"node_id": 13, "intent": self.intent}), success=success)

    def test_real_inline_key_step_and_public_result_without_gateway_http(self):
        result = self.run_peer()
        self.assertEqual(json.loads(result.stdout)["status"], "succeeded")
        public = self.cli("data", "query", 'model("node-network-13-public-key") && name == "key"', "--select", "content")
        self.assertIn(self.public, public.stdout)
        self.assertEqual((self.wireguard / "orbit.key").read_text().strip(), self.private)
        self.run_peer()
        self.assertEqual((self.wireguard / "orbit.key").read_text().strip(), self.private)

    def test_wrong_physical_worker_refuses_before_generating_key(self):
        self.config.write_text("[swamp_worker]\nnode_id = 209\n")
        self.run_peer(success=False)
        self.assertFalse((self.wireguard / "orbit.key").exists())

    def test_absent_journal_restoration_skips_effects_and_empty_native_foreach(self):
        workflow = yaml.safe_load((ROOT / "workflows/workflow-node-network-peer-restore.yaml").read_text())
        for job in workflow["jobs"]:
            for step in job["steps"]:
                step.pop("target", None)
                step.pop("labels", None)
                if step["task"].get("behaviorType") == "command/shell":
                    source = step["task"]["inputs"]["run"]
                    source = source.replace("/etc/wireguard", str(self.wireguard))
                    source = source.replace("metadata.st_uid != 0", f"metadata.st_uid != {os.getuid()}")
                    step["task"]["inputs"]["run"] = source
        (self.repo / "workflows/workflow-node-network-peer-restore.yaml").write_text(yaml.safe_dump(workflow, sort_keys=False))
        result = self.cli("workflow", "run", "node-network-peer-restore", "--stdin", input=json.dumps({
            "operation_id": 7, "transaction_id": "6c2df98a-e671-48e2-adee-16704e558786",
            "node_id": 13, "intent": self.intent,
        }))
        self.assertEqual(json.loads(result.stdout)["status"], "succeeded")
        self.assertEqual(list(self.wireguard.iterdir()), [])
