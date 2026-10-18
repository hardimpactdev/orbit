"""Isolated behavior tests of the maintained guest control; never invokes root tools."""
import base64
import copy
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location("control", sys.argv.pop(1))
control = importlib.util.module_from_spec(spec)
spec.loader.exec_module(control)


class MemoryFixture(control.Fixture):
    def __init__(self, directory):
        super().__init__({"operator_rules": []})
        self.directory = directory
        self.live_rules = []
        self.events = []
        self.valid_lease = True
        self.failure = None
        self.extra = ""
        self.foreign_added = []
        self.files = [directory / "user.rules", directory / "user6.rules"]
        for path in self.files:
            path.write_text("# Updated on before\noriginal operator rule\n")
        self.saved_files = set(control.USER_FILES)
        control.USER_FILES = {str(p) for p in self.files}
        self.state = {"owner": control.OWNER, "phase": "prepared", "rules": [], "resources": {},
                      "operator_rules": [], "intent": None}
        self.state["baseline"] = self.snapshot()
        self.state["expected"] = self.snapshot()

    def guard(self):
        if not self.valid_lease:
            raise RuntimeError("lease changed")

    def snapshot(self):
        files = {}
        for path in self.files:
            info = path.stat()
            files[str(path)] = {"data": base64.b64encode(path.read_bytes()).decode(), "mode": info.st_mode & 0o777,
                                "uid": info.st_uid, "gid": info.st_gid, "mtime_ns": info.st_mtime_ns, "sha256": "test"}
        rules = "\n".join(self.live_rules) + self.extra
        status = "Status: active\n" + "\n".join(
            f"[{i}] test-rule # orbit-e2e:1791:8b819011:{kind}" for i, kind in enumerate(self.live_rules, 1)) + self.extra
        added = "\n".join([*(control.shlex.join(control.added_rule(kind)) + " comment 'orbit-e2e:1791:8b819011:" + kind + "'" for kind in self.live_rules), *self.foreign_added])
        return {"runtime": {"iptables-save": rules}, "persistent": files, "status": status, "added": added}

    def save(self):
        self.guard()
        self.events.append(copy.deepcopy(self.state))

    def mutate(self, argv):
        self.guard()
        if self.failure:
            raise RuntimeError(self.failure)
        if "delete" in argv:
            self.live_rules.pop(int(argv[-1]) - 1)
        else:
            self.live_rules.append(argv[-1].rsplit(":", 1)[-1])
        self.files[0].write_text("# Updated on after\noriginal operator rule\n" + "".join(x + "\n" for x in self.live_rules))

    def public_key_pin(self):
        return "sha256//" + base64.b64encode(b"a" * 32).decode()

    def health(self):
        return {"healthy": True}

    def cleanup(self):
        self.state["resources"] = {}


class ControlTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.path = Path(self.temp.name)
        root = self.path / "control"
        root.mkdir()
        (root / "cert.pem").write_text("public test certificate")
        self.root_patch = patch.object(control, "ROOT", root)
        self.root_patch.start()
        self.fixture = MemoryFixture(self.path)

    def tearDown(self):
        control.USER_FILES = self.fixture.saved_files
        self.root_patch.stop()
        self.temp.cleanup()

    def test_narrow_rollback_and_cleanup_restore_order_and_bytes(self):
        original = copy.deepcopy(self.fixture.state["baseline"])
        self.fixture.rule("bridge-udp", True)
        self.fixture.rule("bridge-tcp", True)
        self.fixture.operate("overlap")
        overlap = copy.deepcopy(self.fixture.state["overlap"])
        self.fixture.operate("narrow")
        self.assertEqual(self.fixture.live_rules, ["bridge-udp", "bridge-tcp"])
        self.fixture.operate("rollback")
        self.assertEqual(self.fixture.snapshot(), overlap)
        self.fixture.operate("restore")
        self.assertEqual(self.fixture.snapshot(), original)
        self.assertEqual(self.fixture.state["phase"], "restored")
        self.assertEqual(self.fixture.state["resources"], {})
        self.assertTrue(any(event["intent"] == ["add", "overlap-udp"] for event in self.fixture.events))

    def test_intervening_operator_rule_refuses_restore_without_mutation(self):
        self.fixture.rule("bridge-udp", True)
        self.fixture.extra = "foreign-rule"
        before = self.fixture.snapshot()
        with self.assertRaisesRegex(RuntimeError, "intervening"):
            self.fixture.operate("restore")
        self.assertEqual(self.fixture.snapshot(), before)
        self.assertEqual(self.fixture.state["rules"], ["bridge-udp"])

    def test_changed_operator_records_refuse_narrow(self):
        self.fixture.operate("overlap")
        self.fixture.plan["operator_rules"] = [{"name": "new-operator-rule"}]
        with self.assertRaisesRegex(RuntimeError, "Operator records"):
            self.fixture.operate("narrow")
        self.assertEqual(self.fixture.live_rules, ["overlap-udp", "overlap-tcp"])

    def test_primitive_rechecks_lease_before_every_mutation(self):
        fixture = control.Fixture({})
        calls = []
        fixture.guard = lambda: (_ for _ in ()).throw(RuntimeError("lease changed"))
        with patch.object(control, "run", side_effect=lambda argv: calls.append(argv)):
            with self.assertRaisesRegex(RuntimeError, "lease changed"):
                fixture.mutate(["ufw", "allow", "53"])
        self.assertEqual(calls, [])

    def test_failed_delete_keeps_baseline_and_intent(self):
        self.fixture.rule("bridge-udp", True)
        baseline = copy.deepcopy(self.fixture.state["baseline"])
        self.fixture.failure = "delete failed"
        with self.assertRaisesRegex(RuntimeError, "delete failed"):
            self.fixture.operate("restore")
        self.assertEqual(self.fixture.state["baseline"], baseline)
        self.assertEqual(self.fixture.state["intent"], ["delete", "bridge-udp"])
        self.assertEqual(self.fixture.live_rules, ["bridge-udp"])

    def test_checkpoint_never_overwrites_different_scope_or_order(self):
        self.fixture.operate("overlap")
        target = copy.deepcopy(self.fixture.state["overlap"])
        self.fixture.live_rules.reverse()
        before = self.fixture.files[0].read_bytes()
        with self.assertRaisesRegex(RuntimeError, "inventory/order"):
            self.fixture.checkpoint(target)
        self.assertEqual(self.fixture.files[0].read_bytes(), before)

    def test_bridge_rules_require_interface_destination_and_exact_client(self):
        for transport in ("udp", "tcp"):
            argv = control.rule_arguments("bridge-" + transport)
            self.assertEqual(argv, ["allow", "in", "on", control.BRIDGE, "proto", transport,
                                   "from", "198.18.179.2", "to", "198.18.179.1", "port", "53",
                                   "comment", "orbit-e2e:1791:8b819011:bridge-" + transport])
        with self.assertRaises(RuntimeError):
            control.rule_arguments("operator-command")

    def test_bind_overlap_refused_but_loopback_dns_allowed(self):
        fixture = control.Fixture({"router": "10.232.1.11"})
        def answer(argv, **kwargs):
            if argv[0] == "ss":
                return subprocess.CompletedProcess(argv, 0, "LISTEN 0 128 0.0.0.0:53 0.0.0.0:*\n", "")
            raise AssertionError("must refuse before later controls")
        with patch.object(control.shutil, "which", return_value="installed"), patch.object(control, "run", side_effect=answer):
            with self.assertRaisesRegex(RuntimeError, "Bind overlap"):
                fixture.preflight()

    def test_lease_and_mount_token_are_checked_from_actual_files(self):
        mount = self.path / "mount"
        (mount / ".e2e").mkdir(parents=True)
        lease = {"issue": control.ISSUE, "attempt_id": control.ATTEMPT}
        (mount / ".e2e/attempt.json").write_text(json.dumps(lease))
        (mount / ".e2e/topology.json").write_text(json.dumps({"attempt_id": control.ATTEMPT, "instances": {"app-dev": "leased-router"}}))
        (mount / ".e2e/mount-token").write_bytes(b"token")
        plan = {"issue": control.ISSUE, "attempt": control.ATTEMPT, "router": "10.232.1.11", "member": "10.232.1.12",
                "nonmember": "10.232.1.14", "vpn": "10.44.0.1", "instance": "leased-router", "lease": lease,
                "mount_hash": control.hashlib.sha256(b"token").hexdigest()}
        fixture = control.Fixture(plan)
        addresses = [{"addr_info": [{"local": "10.232.1.11"}]}]
        with patch.object(control, "MOUNT", mount), patch.object(control, "ROOT", Path("/nonexistent-orbit-fixture/child")), patch.object(
                control, "run", return_value=subprocess.CompletedProcess([], 0, json.dumps(addresses), "")):
            fixture.guard()
            (mount / ".e2e/mount-token").write_bytes(b"changed")
            with self.assertRaisesRegex(RuntimeError, "identity changed"):
                fixture.guard()

    def test_foreign_unit_and_link_are_not_cleaned(self):
        fixture = control.Fixture({})
        fixture.guard = lambda: None
        fixture.state = {"resources": {control.UNITS["dns"]: "intended"}}
        fixture.save = lambda: None
        calls = []
        fixture.mutate = lambda argv: calls.append(argv)
        with patch.object(control, "run", side_effect=lambda argv, **kw: subprocess.CompletedProcess(
                argv, 0, "foreign" if "--property=Description" in argv else "loaded", "")):
            with self.assertRaisesRegex(RuntimeError, "Foreign endpoint"):
                fixture.cleanup()
        self.assertEqual(calls, [])

    def prepare_model(self, fail_second=False):
        (control.ROOT / "cert.pem").unlink()
        control.ROOT.rmdir()
        self.fixture.state = None
        self.fixture.plan.update({"router": "10.232.1.11", "vpn": "10.44.0.1"})
        self.fixture.preflight = lambda: None
        calls = []
        def answer(argv, **kwargs):
            self.assertIn("baseline", self.fixture.state)
            calls.append(argv)
            if argv[0] == "ufw":
                if fail_second and argv[-1].endswith("bridge-tcp"):
                    raise RuntimeError("injected apply failure")
                MemoryFixture.mutate(self.fixture, argv)
            elif argv[0] == "openssl":
                (control.ROOT / "cert.pem").write_text("public certificate")
                (control.ROOT / "key.pem").write_text("private test material")
            return subprocess.CompletedProcess(argv, 0, "", "")
        self.fixture.mutate = lambda argv: control.Fixture.mutate(self.fixture, argv)
        with patch.object(control, "run", side_effect=answer), patch.object(control, "namespace_identity", return_value=[1, 2]):
            self.fixture.prepare()
        return calls

    def test_prepare_uses_fixed_endpoints_and_journals_before_mutation(self):
        calls = self.prepare_model()
        self.assertEqual(self.fixture.state["phase"], "prepared")
        self.assertEqual(self.fixture.state["rules"], ["bridge-udp", "bridge-tcp"])
        dns = next(argv for argv in calls if "dnsmasq" in argv)
        self.assertIn("--listen-address=10.232.1.11,198.18.179.1", dns)
        self.assertIn("--no-resolv", dns)
        self.assertIn("--conf-file=/dev/null", dns)
        https = next(argv for argv in calls if "s_server" in argv)
        self.assertIn("10.232.1.11:443", https)
        self.assertEqual(self.fixture.state["resources"][control.NAMESPACE], [1, 2])
        self.assertFalse(any("install" in argv or "flush" in argv or "reset" in argv for argv in calls))

    def test_prepare_failure_retains_exact_baseline_and_owned_partial_resources(self):
        baseline = self.fixture.snapshot()
        with self.assertRaisesRegex(RuntimeError, "injected apply failure"):
            self.prepare_model(fail_second=True)
        self.assertEqual(self.fixture.state["baseline"], baseline)
        self.assertEqual(self.fixture.state["phase"], "preparing")
        self.assertEqual(self.fixture.state["rules"], ["bridge-udp"])
        self.assertEqual(self.fixture.state["intent"], ["add", "bridge-tcp"])
        self.assertIn(control.NAMESPACE, self.fixture.state["resources"])

    def test_loopback_listener_is_not_restarted_or_treated_as_bind_overlap(self):
        fixture = control.Fixture({"router": "10.232.1.11"})
        def answer(argv, **kwargs):
            output = "[]"
            if argv[0] == "ss":
                output = "UNCONN 0 0 127.0.0.53:53 0.0.0.0:*\n"
            elif argv[0] == "systemctl":
                output = "not-found"
            return subprocess.CompletedProcess(argv, 0, output, "")
        with patch.object(control.shutil, "which", return_value="installed"), patch.object(control, "run", side_effect=answer), patch.object(control, "namespace_identity", return_value=None):
            fixture.preflight()

    def test_inspect_reports_drift_and_dead_listener_without_writing(self):
        self.fixture.extra = "foreign-rule"
        self.fixture.health = lambda: (_ for _ in ()).throw(RuntimeError("dead listener"))
        before = len(self.fixture.events)
        report = self.fixture.operate("inspect")
        self.assertEqual(report["health"], {"error": "dead listener"})
        self.assertFalse(report["inventory_matches"])
        self.assertEqual(len(self.fixture.events), before)

    def test_foreign_namespace_is_not_deleted(self):
        fixture = control.Fixture({})
        fixture.state = {"resources": {control.NAMESPACE: [1, 2]}}
        calls = []
        fixture.mutate = lambda argv: calls.append(argv)
        with patch.object(control, "namespace_identity", return_value=[9, 10]):
            with self.assertRaisesRegex(RuntimeError, "Foreign namespace"):
                fixture.cleanup()
        self.assertEqual(calls, [])

    def test_https_pin_is_hash_of_generated_certificate_public_key(self):
        fixture = control.Fixture({})
        with patch.object(control, "run", return_value=subprocess.CompletedProcess([], 0, "PUBLIC PEM", "")), patch.object(
                control.subprocess, "run", return_value=subprocess.CompletedProcess([], 0, b"PUBLIC DER", b"")) as convert:
            pin = fixture.public_key_pin()
        self.assertEqual(pin, "sha256//" + base64.b64encode(control.hashlib.sha256(b"PUBLIC DER").digest()).decode())
        self.assertEqual(convert.call_args.args[0], ["openssl", "pkey", "-pubin", "-outform", "DER"])
        self.assertEqual(convert.call_args.kwargs["input"], b"PUBLIC PEM")

    def test_identical_operator_rule_is_never_adopted_or_deleted(self):
        self.fixture.foreign_added = ["ufw allow 53/udp comment 'operator-owned'"]
        self.fixture.state["expected"] = self.fixture.snapshot()
        before = self.fixture.snapshot()
        with self.assertRaisesRegex(RuntimeError, "adoption refused"):
            self.fixture.rule("overlap-udp", True)
        self.assertEqual(self.fixture.snapshot(), before)
        self.assertEqual(self.fixture.state["rules"], [])
        self.assertEqual(self.fixture.events, [])

    def test_skipped_ufw_add_never_becomes_owned(self):
        self.fixture.mutate = lambda argv: None
        with self.assertRaisesRegex(RuntimeError, "absent or ambiguous"):
            self.fixture.rule("overlap-udp", True)
        self.assertEqual(self.fixture.state["rules"], [])
        self.assertEqual(self.fixture.state["intent"], ["add", "overlap-udp"])

    def test_runtime_counters_do_not_hide_rule_changes(self):
        self.assertEqual(control.canonical_runtime("# Generated yesterday\n:INPUT DROP [2:120]\n-A INPUT -j DROP"),
                         control.canonical_runtime("# Generated today\n:INPUT DROP [9:540]\n-A INPUT -j DROP"))
        self.assertNotEqual(control.canonical_runtime("-A INPUT -j DROP"), control.canonical_runtime("-A INPUT -j ACCEPT"))
        self.assertNotEqual(control.canonical_runtime('-A INPUT -m comment --comment "[2:120]" -j ACCEPT'),
                            control.canonical_runtime('-A INPUT -m comment --comment "[9:540]" -j ACCEPT'))


if __name__ == "__main__":
    unittest.main()
