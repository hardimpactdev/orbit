"""Exercise the exact inline peer-key action against a disposable filesystem."""
import base64
import contextlib
import io
import json
import os
import re
import runpy
import stat
import subprocess
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import yaml

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / "workflows/workflow-node-network-peer-prepare.yaml"
PRIVATE = base64.b64encode(b"p" * 32).decode()
PUBLIC = base64.b64encode(b"q" * 32).decode()


class PeerKeyAction(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.wireguard = self.root / "wireguard"
        self.wireguard.mkdir(mode=0o700)
        self.config = self.root / "config.toml"
        self.config.write_text("[swamp_worker]\nnode_id = 13\nuser = 'orbit'\n")
        workflow = yaml.safe_load(WORKFLOW.read_text())
        steps = workflow["jobs"][0]["steps"]
        action = next(step for step in steps if step["name"] == "prepare_key")
        shell = action["task"]["inputs"]["run"]
        self.script = re.search(r"<<'PYTHON'\n(.*)\nPYTHON", shell, re.DOTALL).group(1)
        self.script = self.script.replace('Path("/etc/wireguard")', f"Path({str(self.wireguard)!r})")
        self.script = self.script.replace('"/etc/orbit/agent/config.toml"', repr(str(self.config)))
        self.script = self.script.replace("metadata.st_uid != 0", f"metadata.st_uid != {os.getuid()}")
        self.script_path = self.root / "prepare.py"
        self.script_path.write_text(self.script)
        self.intent = {"node_id": 13, "existing_key": None}
        self.calls = []

    def execute(self):
        def command(argv, **kwargs):
            self.calls.append(argv)
            self.assertIn(argv, [["/usr/bin/wg", "genkey"], ["/usr/bin/wg", "pubkey"]])
            if argv[-1] == "pubkey":
                self.assertEqual(kwargs["input"].strip(), PRIVATE)
            return subprocess.CompletedProcess(argv, 0, (PRIVATE if argv[-1] == "genkey" else PUBLIC) + "\n", "")
        output = io.StringIO()
        with patch("subprocess.run", command), patch("sys.argv", ["-", json.dumps(self.intent)]), contextlib.redirect_stdout(output):
            runpy.run_path(str(self.script_path), run_name="__main__")
        self.assertNotIn(PRIVATE, output.getvalue())
        return json.loads(output.getvalue())

    def test_generated_key_is_private_and_resume_reuses_it(self):
        self.assertEqual(self.execute(), {"public_key": PUBLIC})
        path = self.wireguard / "orbit.key"
        self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o600)
        self.assertEqual(path.read_text().strip(), PRIVATE)
        self.assertEqual(self.execute(), {"public_key": PUBLIC})
        self.assertEqual(self.calls.count(["/usr/bin/wg", "genkey"]), 1)

    def test_exact_existing_key_is_retained(self):
        self.execute()
        self.calls.clear()
        self.intent["existing_key"] = PUBLIC
        self.assertEqual(self.execute(), {"public_key": PUBLIC})
        self.assertEqual(self.calls, [["/usr/bin/wg", "pubkey"]])

    def test_foreign_worker_refuses_before_key_generation(self):
        self.config.write_text("[swamp_worker]\nnode_id = 209\n")
        with self.assertRaisesRegex(SystemExit, "worker_mismatch"):
            self.execute()
        self.assertEqual(self.calls, [])

    def test_existing_identity_does_not_generate_missing_key(self):
        self.intent["existing_key"] = PUBLIC
        with self.assertRaisesRegex(SystemExit, "key_missing"):
            self.execute()
        self.assertEqual(self.calls, [])

    def test_pending_transaction_refuses_before_mutation(self):
        (self.wireguard / ".orbit.native-network-transaction").write_text("{}")
        with self.assertRaisesRegex(SystemExit, "recovery_pending"):
            self.execute()
        self.assertEqual(self.calls, [])

    def test_key_symlink_or_unsafe_mode_refuses_without_reading(self):
        outside = self.root / "foreign-key"
        outside.write_text("must-not-be-read")
        key = self.wireguard / "orbit.key"
        key.symlink_to(outside)
        with self.assertRaisesRegex(SystemExit, "state_unsupported"):
            self.execute()
        key.unlink()
        key.write_text(PRIVATE)
        key.chmod(0o644)
        with self.assertRaisesRegex(SystemExit, "state_unsupported"):
            self.execute()
        self.assertEqual(self.calls, [])

    def test_existing_key_mismatch_refuses_without_replacing(self):
        self.execute()
        before = (self.wireguard / "orbit.key").read_bytes()
        self.intent["existing_key"] = base64.b64encode(b"x" * 32).decode()
        with self.assertRaisesRegex(SystemExit, "peer_key_mismatch"):
            self.execute()
        self.assertEqual((self.wireguard / "orbit.key").read_bytes(), before)


class PeerPublicationActions(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.wireguard = self.root / "wireguard"
        self.wireguard.mkdir(mode=0o700)
        self.old_config = "[Interface]\nPrivateKey = prior-secret-sentinel\n"
        self.write("orbit.conf", self.old_config)
        self.write("orbit.key", PRIVATE + "\n")
        self.write("orbit.dns-link", "orbit\n10.44.0.1\norbit\n")
        self.settings = {"dns": "10.44.0.1", "domain": "~orbit", "default-route": "no",
                         "llmnr": "no", "mdns": "no", "dnssec": "no", "dnsovertls": "no", "nta": ""}
        self.intent = {"node_id": 13, "public_key": PUBLIC,
                       "peer": {"server_public_key": base64.b64encode(b"h" * 32).decode(),
                                "address": "10.44.0.13/24", "endpoint": "vpn.example.test:51820",
                                "subnet": "10.44.0.0/24", "dns_server": "10.44.0.1",
                                "domains": ["orbit"], "retain": True, "operator_dns": None}}
        workflow = yaml.safe_load((ROOT / "workflows/workflow-node-network-peer-publish.yaml").read_text())
        self.scripts = {}
        for step in (step for job in workflow["jobs"] for step in job["steps"]):
            if step["task"].get("behaviorType") != "command/shell":
                continue
            shell = step["task"]["inputs"]["run"]
            source = re.search(r"<<'PYTHON'\n(.*)\nPYTHON", shell, re.DOTALL).group(1)
            source = source.replace('Path("/etc/wireguard")', f"Path({str(self.wireguard)!r})")
            source = source.replace("metadata.st_uid != 0", f"metadata.st_uid != {os.getuid()}")
            path = self.root / (step["name"] + ".py")
            path.write_text(source)
            self.scripts[step["name"]] = path
        self.config = self.root / "config.toml"
        self.config.write_text("[swamp_worker]\nnode_id = 13\n")
        self.uplink = True
        self.active = True
        self.runtime_masked = False
        for path in self.scripts.values():
            path.write_text(path.read_text().replace('"/etc/orbit/agent/config.toml"', repr(str(self.config))))
        commit = yaml.safe_load((ROOT / "workflows/workflow-node-network-peer-commit.yaml").read_text())
        for step in commit["jobs"][0]["steps"]:
            if step["task"].get("behaviorType") != "command/shell":
                continue
            source = re.search(r"<<'PYTHON'\n(.*)\nPYTHON", step["task"]["inputs"]["run"], re.DOTALL).group(1)
            source = source.replace('Path("/etc/wireguard")', f"Path({str(self.wireguard)!r})")
            source = source.replace("metadata.st_uid != 0", f"metadata.st_uid != {os.getuid()}")
            path = self.root / (step["name"] + ".py")
            path.write_text(source)
            self.scripts[step["name"]] = path
        self.calls = []
        self.transaction_id = "cfc4f421-79a4-45d4-9d1d-94b5f9bed402"

    def write(self, name, text):
        path = self.wireguard / name
        path.write_text(text)
        path.chmod(0o600)

    def execute(self, step):
        def command(argv, **kwargs):
            self.calls.append(argv)
            output = ""
            if argv[:2] == ["/usr/bin/systemctl", "is-enabled"]:
                output = "enabled-runtime\n"
            if argv[:2] == ["/usr/bin/systemctl", "unmask"] and "--runtime" in argv:
                self.runtime_masked = False
            if argv[0] == "/usr/bin/systemctl" and argv[1] in ("enable", "start") and self.runtime_masked:
                raise subprocess.CalledProcessError(1, argv)

            if argv[0] == "/usr/bin/resolvectl":
                output = ("Global: 1.1.1.1\n" if self.uplink else "Global: 10.44.0.1\n") if len(argv) == 2 else ("Link 4 (orbit): " + self.settings[argv[1]] + "\n")
                if len(argv) > 3:
                    self.settings[argv[1]] = " ".join(argv[3:])
            if argv[0] == "/usr/bin/wg":
                output = PUBLIC + "\n"
            status = 1 if argv[:3] == ["/usr/bin/systemctl", "is-active", "--quiet"] and not self.active else 0
            return subprocess.CompletedProcess(argv, status, output, "")
        output = io.StringIO()
        with patch("subprocess.run", command), patch("sys.argv", ["-", json.dumps(self.intent), "7", self.transaction_id]), contextlib.redirect_stdout(output):
            runpy.run_path(str(self.scripts[step]), run_name="__main__")
        self.assertNotIn(PRIVATE, output.getvalue())
        self.assertNotIn("prior-secret-sentinel", output.getvalue())
        return json.loads(output.getvalue())

    def test_readiness_refuses_wrong_physical_worker_and_missing_public_uplink(self):
        self.config.write_text("[swamp_worker]\nnode_id = 209\n")
        with self.assertRaisesRegex(SystemExit, "worker_mismatch"):
            self.execute("inspect_peer_readiness")
        self.assertEqual(self.calls, [])
        self.config.write_text("[swamp_worker]\nnode_id = 13\n")
        self.uplink = False
        with self.assertRaisesRegex(SystemExit, "uplink_missing"):
            self.execute("inspect_peer_readiness")
        self.assertFalse((self.wireguard / ".orbit.native-network-transaction").exists())

    def test_fresh_inactive_peer_does_not_query_a_nonexistent_tunnel_link(self):
        self.active = False
        (self.wireguard / "orbit.conf").unlink()
        (self.wireguard / "orbit.dns-link").unlink()
        self.execute("capture_before_image")
        self.assertFalse(any(call[0] == "/usr/bin/resolvectl" for call in self.calls))
        saved = json.loads((self.wireguard / ".orbit.native-network-transaction").read_text())
        self.assertIsNone(saved["config"])
        self.assertIsNone(saved["dns_file"])
        self.assertEqual(saved["resolver_settings"], {})

    def test_prior_underlay_dns_is_released_in_separate_native_actions(self):
        self.write("orbit.dns-link", "eth0\n10.44.0.1\norbit\n")
        self.assertEqual(self.execute("capture_before_image"), {"captured": True, "legacy_link": "eth0"})
        self.calls.clear()
        for step in ("clear_legacy_dns_server", "clear_legacy_dns_domains", "restore_legacy_dns_default_route", "reconfigure_legacy_uplink"):
            self.execute(step)
        self.assertEqual(self.calls, [["/usr/bin/resolvectl", "dns", "eth0", ""],
                                    ["/usr/bin/resolvectl", "domain", "eth0", ""],
                                    ["/usr/bin/resolvectl", "default-route", "eth0", "yes"],
                                    ["/usr/bin/networkctl", "reconfigure", "eth0"]])

    def test_snapshot_remains_private_and_resume_preserves_original(self):
        self.assertEqual(self.execute("capture_before_image"), {"captured": True, "legacy_link": None})
        journal = self.wireguard / ".orbit.native-network-transaction"
        before = journal.read_bytes()
        self.assertEqual(stat.S_IMODE(journal.stat().st_mode), 0o600)
        self.write("orbit.conf", "partially-published-secret")
        self.execute("capture_before_image")
        self.assertEqual(journal.read_bytes(), before)
        self.assertEqual(json.loads(before)["config"], self.old_config)

    def test_publication_writes_real_newlines_and_never_returns_private_configuration(self):
        self.execute("capture_before_image")
        self.assertEqual(self.execute("publish_peer_configuration"), {"published": True})
        config = (self.wireguard / "orbit.conf").read_text()
        self.assertIn("[Interface]\nAddress = 10.44.0.13/24\n", config)
        self.assertIn("PrivateKey = " + PRIVATE + "\n", config)
        self.assertIn("PostUp = resolvectl dns %i 10.44.0.1; resolvectl domain %i \\~orbit\n", config)
        self.assertNotIn("prior-secret-sentinel", config)
        self.assertEqual(stat.S_IMODE((self.wireguard / "orbit.conf").stat().st_mode), 0o600)


    def test_operator_mode_uses_dns_line_and_withdraws_managed_marker(self):
        self.intent["peer"]["operator_dns"] = "10.44.0.1"
        self.execute("capture_before_image")
        self.execute("publish_peer_configuration")
        config = (self.wireguard / "orbit.conf").read_text()
        self.assertIn("DNS = 10.44.0.1\n", config)
        self.assertNotIn("PostUp", config)
        self.execute("withdraw_operator_dns_metadata")
        self.assertFalse((self.wireguard / "orbit.dns-link").exists())
        self.assertTrue(self.execute("verify_peer_publication")["peer_published"])

    def test_another_run_cannot_reuse_the_before_image_or_mutate_service(self):
        self.execute("capture_before_image")
        self.calls.clear()
        self.transaction_id = "8b856c38-d6b2-4226-9b5b-d153245bcad3"
        for step in ("capture_before_image", "stop_peer_tunnel", "publish_peer_configuration"):
            with self.assertRaisesRegex(SystemExit, "recovery_pending"):
                self.execute(step)
        self.assertEqual(self.calls, [])
        self.assertEqual((self.wireguard / "orbit.conf").read_text(), self.old_config)

    def test_runtime_mask_is_removed_before_persistent_enable_and_start(self):
        self.execute("capture_before_image")
        self.runtime_masked = True
        self.execute("stop_peer_tunnel")
        self.execute("publish_peer_configuration")
        self.execute("unmask_peer_tunnel")
        with self.assertRaises(subprocess.CalledProcessError):
            self.execute("enable_peer_tunnel")
        self.execute("unmask_peer_tunnel_runtime")
        self.execute("enable_peer_tunnel")
        self.execute("start_peer_tunnel")
        self.assertFalse(self.runtime_masked)

    def test_service_actions_are_distinct_native_steps(self):
        self.execute("capture_before_image")
        for step, action in (("stop_peer_tunnel", "stop"), ("unmask_peer_tunnel", "unmask"),
                             ("enable_peer_tunnel", "enable"), ("start_peer_tunnel", "start")):
            self.calls.clear()
            self.execute(step)
            self.assertEqual(self.calls, [["/usr/bin/systemctl", action, "wg-quick@orbit"]])

    def test_commit_retires_only_its_own_before_image_and_is_resumable(self):
        self.execute("capture_before_image")
        self.execute("publish_peer_configuration")
        self.execute("persist_peer_dns_metadata")
        expected = {"network_proven": True, "public_key": PUBLIC, "retained": True}
        self.assertEqual(self.execute("commit_peer_transaction"), expected)
        self.assertFalse((self.wireguard / ".orbit.native-network-transaction").exists())
        self.assertEqual(self.execute("commit_peer_transaction"), expected)

    def test_publication_preserves_journal_until_parent_firewall_and_commit(self):
        self.execute("capture_before_image")
        self.execute("publish_peer_configuration")
        self.execute("persist_peer_dns_metadata")
        result = self.execute("verify_peer_publication")
        self.assertEqual(result, {"peer_published": True, "public_key": PUBLIC, "retained": True})
        self.assertTrue((self.wireguard / ".orbit.native-network-transaction").exists())


class PeerRestorationActions(unittest.TestCase):
    execute = PeerPublicationActions.execute
    write = PeerPublicationActions.write

    def setUp(self):
        PeerPublicationActions.setUp(self)
        workflow = yaml.safe_load((ROOT / "workflows/workflow-node-network-peer-restore.yaml").read_text())
        for step in (step for job in workflow["jobs"] for step in job["steps"]):
            if step["task"].get("behaviorType") != "command/shell":
                continue
            shell = step["task"]["inputs"]["run"]
            source = re.search(r"<<'PYTHON'\n(.*)\nPYTHON", shell, re.DOTALL).group(1)
            source = source.replace('Path("/etc/wireguard")', f"Path({str(self.wireguard)!r})")
            source = source.replace("metadata.st_uid != 0", f"metadata.st_uid != {os.getuid()}")
            path = self.root / (step["name"] + ".py")
            path.write_text(source)
            self.scripts[step["name"]] = path

    def test_absent_transaction_has_no_claim_of_restoration(self):
        self.assertEqual(self.execute("inspect_before_image"), {"present": False})
        self.assertEqual(self.calls, [])

    def test_exact_private_before_images_restore_without_public_secret_output(self):
        self.execute("capture_before_image")
        self.execute("publish_peer_configuration")
        self.execute("persist_peer_dns_metadata")
        self.execute("restore_peer_configuration")
        self.execute("restore_peer_dns_metadata")
        self.assertEqual((self.wireguard / "orbit.conf").read_text(), self.old_config)
        self.assertEqual(self.execute("verify_peer_restoration"), {"peer_restored": True})
        self.execute("retire_restored_before_image")
        self.assertFalse((self.wireguard / ".orbit.native-network-transaction").exists())

    def test_foreign_transaction_cannot_restore_or_retire_another_runs_journal(self):
        self.execute("capture_before_image")
        before = (self.wireguard / ".orbit.native-network-transaction").read_bytes()
        self.transaction_id = "e865399d-b6dc-43e8-a05f-850ef6d4719f"
        for step in ("inspect_before_image", "restore_peer_configuration", "retire_restored_before_image"):
            with self.assertRaisesRegex(SystemExit, "recovery_pending"):
                self.execute(step)
        self.assertEqual((self.wireguard / ".orbit.native-network-transaction").read_bytes(), before)


if __name__ == "__main__":
    unittest.main()
