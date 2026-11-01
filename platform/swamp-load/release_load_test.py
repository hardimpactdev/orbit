"""Publication and custody regressions; no live host, credentials or native runs."""
import copy
import json
import os
import subprocess
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import install_release_load as installer
import release_load as loader

SHA = "a" * 40
OTHER = "b" * 40


def request(action="prepare"):
    return {"schema": loader.SCHEMA, "release_sha": SHA, "release_id": SHA[:12],
            "gateway_release_record": 42, "active_release_record": 42, "target_node_id": 209,
            "attempt_id": "release42_attempt_0123456789", "action": action,
            "gateway_release_lock": "GatewayReleaseLock::run"}


class FakeHost:
    def __init__(self):
        self.calls = []
        self.failure = None
        self.changed = False
        self.evidence = {"workflows": [{"id": "workflow", "equal": True}],
                         "extension_audit": {"checked": 1, "matched": 1, "deleted": ["retired/type"]},
                         "drain": {"queried_at": "now", "active_running_run_ids": ["live-root"]}}

    def call(self, name):
        self.calls.append(name)
        if self.failure == name:
            raise RuntimeError("private-input-must-not-be-returned")

    def runtime(self):
        self.call("runtime")
        return {"pid": 1234, "invocation": "same-runtime"}

    def stage(self, sha):
        self.call("stage:" + sha)
        return "c" * 40

    def reload(self):
        self.call("reload")
        return {"success": True, "errors": []}

    def grants(self, sha, directory):
        self.call("grants")
        return {"source_sha": sha, "hash": "d" * 64, "scope_count": 4, "created_count": 0, "applied": True}

    def verify(self):
        self.call("verify")
        return copy.deepcopy(self.evidence)

    def health(self):
        self.call("health")
        return {"loopback_status": 200, "configured_ca_status": 200}

    def unchanged(self, sha, runtime):
        self.call("unchanged")
        loader.require(not self.changed, "unexpected_runtime_change")


class PublicationTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.state = Path(self.temporary.name) / "state"
        self.host = FakeHost()

    def prepare(self):
        return loader.process(request(), self.host, self.state)

    def accept(self, prepared):
        return {**request("accept"), "receipt_hash": prepared["receipt_hash"],
                "configured_token_proof": {"principal": "user:orbit-gateway", "verified": True,
                                           "check_count": 8, "manifest_hash": prepared["manifest"]["hash"]}}

    def test_prepare_loads_only_pinned_source_and_keeps_custody_with_live_runs(self):
        receipt = self.prepare()
        self.assertEqual(receipt["state"], "awaiting_gateway_verification")
        self.assertEqual(receipt["disk_sha"], SHA)
        self.assertEqual(receipt["restarts"], 0)
        self.assertEqual(receipt["worker_restarts"], 0)
        self.assertEqual(receipt["drain"]["active_running_run_ids"], ["live-root"])
        self.assertEqual(self.host.calls, ["runtime", "stage:" + SHA, "reload", "grants", "verify", "health", "unchanged"])
        self.assertTrue((self.state / "pending.json").is_file())
        self.assertNotIn("configured_token_proof", receipt)

    def test_accept_requires_gateway_proof_and_rechecks_host_before_releasing_custody(self):
        prepared = self.prepare()
        accepted = loader.process(self.accept(prepared), self.host, self.state)
        self.assertEqual(accepted["state"], "verified")
        self.assertEqual(accepted["configured_token_proof"]["principal"], "user:orbit-gateway")
        self.assertFalse((self.state / "pending.json").exists())
        calls = self.host.calls.copy()
        self.assertEqual(loader.process(self.accept(prepared), self.host, self.state), accepted)
        self.assertEqual(self.host.calls, calls)

    def test_invalid_identity_changes_nothing(self):
        for changes in ({"release_sha": "main"}, {"release_id": OTHER[:12]}, {"target_node_id": 13},
                        {"active_release_record": 43}, {"gateway_release_lock": ""},
                        {"gateway_release_record": 0}, {"attempt_id": "../../other"}):
            with self.subTest(changes=changes), self.assertRaises(loader.Refused):
                loader.process({**request(), **changes}, self.host, self.state)
            self.assertFalse(self.state.exists())
            self.assertEqual(self.host.calls, [])

    def test_no_blind_prepare_retry_even_after_a_completed_native_load(self):
        self.prepare()
        calls = self.host.calls.copy()
        with self.assertRaisesRegex(loader.Refused, "attempt_requires_inspection"):
            self.prepare()
        with self.assertRaisesRegex(loader.Refused, "attempt_requires_inspection"):
            loader.process({**request(), "attempt_id": "a_different_attempt_key"}, self.host, self.state)
        self.assertEqual(self.host.calls, calls)

    def test_every_failed_or_uncertain_phase_keeps_marker_and_stops_later_effects(self):
        for failure in ("stage:" + SHA, "reload", "grants", "verify", "health", "unchanged"):
            with self.subTest(failure=failure):
                host = FakeHost()
                host.failure = failure
                directory = self.state / failure.replace(":", "-")
                with self.assertRaises(RuntimeError):
                    loader.process(request(), host, directory)
                marker = json.loads((directory / "pending.json").read_text())
                self.assertEqual(marker["state"], "outcome_uncertain")
                self.assertNotIn("private-input", json.dumps(marker))
                self.assertEqual(host.calls[-1], failure)

    def test_wrong_accept_identity_hash_or_grant_manifest_cannot_clear_marker(self):
        prepared = self.prepare()
        valid = self.accept(prepared)
        invalid = [{**valid, "receipt_hash": "wrong"},
                   {**valid, "gateway_release_record": 43, "active_release_record": 43},
                   {**valid, "configured_token_proof": {**valid["configured_token_proof"], "manifest_hash": "wrong"}},
                   {**valid, "configured_token_proof": {**valid["configured_token_proof"], "check_count": 1}}]
        for candidate in invalid:
            with self.subTest(candidate=candidate), self.assertRaises(loader.Refused):
                loader.process(candidate, self.host, self.state)
            self.assertTrue((self.state / "pending.json").is_file())

    def test_changed_runtime_or_definition_between_prepare_and_accept_stays_pending(self):
        prepared = self.prepare()
        self.host.changed = True
        with self.assertRaisesRegex(loader.Refused, "unexpected_runtime_change"):
            loader.process(self.accept(prepared), self.host, self.state)
        self.host.changed = False
        self.host.evidence["workflows"][0]["id"] = "different"
        with self.assertRaisesRegex(loader.Refused, "prepared_definition_changed"):
            loader.process(self.accept(prepared), self.host, self.state)
        self.assertTrue((self.state / "pending.json").is_file())


class DefinitionVerificationTest(unittest.TestCase):
    def setUp(self):
        self.host = loader.Host()
        self.disk = {"id": "a-workflow", "name": "public", "jobs": [{"steps": []}], "path": "/disk/local"}
        self.served = {**self.disk, "path": "/serve/elsewhere"}
        self.replies = [{"data": self.served}, {"data": {"version": "1.0.0"}},
                        {"data": {"results": [{"normalized": "@test/live"}, {"normalized": "@test/deleted"}]}},
                        {"runs": [{"id": "still-running", "status": "running"}]}]
        self.rows = [{"type": "@test/live", "expected": "1.0.0"}]

    def verify(self):
        with patch.object(loader.Host, "workflows", return_value=[{"id": "a-workflow", "name": "public", "disk": self.disk}]), \
             patch.object(loader.Host, "rpc", return_value=self.replies), \
             patch.object(loader.release_source_inventory, "inventory", return_value=self.rows):
            return self.host.verify()

    def test_normalizes_only_transport_path_and_reports_deleted_entries_without_registry_claim(self):
        result = self.verify()
        self.assertTrue(result["workflows"][0]["equal"])
        self.assertEqual(result["workflows"][0]["disk_hash"], result["workflows"][0]["served_hash"])
        self.assertEqual(result["extension_audit"]["deleted"], ["@test/deleted"])

    def test_stale_exposed_workflow_field_blocks_acceptance(self):
        self.served["jobs"] = [{"steps": [{"name": "stale"}]}]
        with self.assertRaisesRegex(loader.Refused, "served_workflow_mismatch"):
            self.verify()

    def test_stale_current_extension_blocks_acceptance(self):
        self.replies[1]["data"]["version"] = "0.9.0"
        with self.assertRaisesRegex(loader.Refused, "served_extension_version_mismatch"):
            self.verify()

    def test_unknown_source_version_and_unreadable_native_drain_fail_closed(self):
        self.rows[0]["error"] = "ambiguous"
        with self.assertRaisesRegex(loader.Refused, "source_version_ambiguous"):
            self.verify()
        self.rows[0].pop("error")
        self.replies[-1] = {}
        with self.assertRaisesRegex(loader.Refused, "native_drain_unreadable"):
            self.verify()


class HostCommandTest(unittest.TestCase):
    def test_clean_environment_uses_absolute_privilege_tool_without_inheriting_credentials(self):
        result = subprocess.CompletedProcess([], 0, "public result", "")
        with patch.object(loader.subprocess, "run", return_value=result) as run:
            self.assertEqual(loader.Host().command(["git", "status"], owner=True), "public result")
        args = run.call_args.args[0]
        self.assertEqual(args[:6], ["/usr/sbin/runuser", "-u", "orbit", "--", "env", "-i"])
        self.assertNotIn("SWAMP_SERVER_TOKEN", repr(run.call_args.kwargs))
        self.assertNotIn("SWAMP_API_KEY", repr(run.call_args.kwargs))


class InstallationTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.base = Path(self.temporary.name)
        self.checkout = self.base / "checkout"
        self.source = self.checkout / "platform/swamp-load"
        self.source.mkdir(parents=True)
        self.destination = self.base / "libexec"
        self.destination.mkdir(mode=0o755)
        (self.destination / "orbit-swamp-main-load.py").write_text(
            "def main():\n    with LOCK.open('a') as lock:\n        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)\n"
            + installer.ANCHOR + "        consume(ready)\n")
        self.content = {name: ("public pinned " + name).encode() for name in installer.FILES}
        for name, data in self.content.items():
            (self.source / name).write_bytes(data)

    def git(self, *args):
        if args == ("rev-parse", "HEAD"):
            return (SHA + "\n").encode()
        if args[0] == "status":
            return b""
        if args[0] == "show":
            return self.content[args[1].rsplit("/", 1)[-1]]
        raise AssertionError(args)

    def install(self):
        return installer.install(SHA, self.checkout, self.destination, self.git, owner=os.geteuid())

    def test_installs_pinned_immutable_package_without_load_restart_or_new_dependency(self):
        receipt = self.install()
        self.assertEqual(receipt["state"], "installed")
        self.assertEqual(receipt["loads"], 0)
        self.assertFalse(receipt["release_dependency_enabled"])
        publisher = (self.destination / "orbit-swamp-main-load.py").read_text()
        self.assertEqual(publisher.count(installer.PENDING_GUARD), 1)
        entry = Path(receipt["entry"])
        self.assertEqual(entry.resolve().read_bytes(), self.content["release_load.py"])
        self.assertEqual(self.install(), receipt)

    def test_modified_checkout_bytes_refuse_before_any_entry_is_published(self):
        (self.source / "release_load.py").write_bytes(b"modified")
        with self.assertRaises(RuntimeError):
            self.install()
        self.assertFalse((self.destination / "orbit-swamp-release-load.py").exists())

    def test_foreign_entry_link_is_preserved(self):
        foreign = self.base / "foreign"
        foreign.write_text("keep")
        entry = self.destination / "orbit-swamp-release-load.py"
        entry.symlink_to(foreign)
        with self.assertRaises(RuntimeError):
            self.install()
        self.assertEqual(entry.resolve(), foreign)

    def test_unknown_publisher_revision_refuses_without_changing_publisher_or_entry(self):
        publisher = self.destination / "orbit-swamp-main-load.py"
        publisher.write_text("# unknown publisher; preserve me\n")
        with self.assertRaises(RuntimeError):
            self.install()
        self.assertEqual(publisher.read_text(), "# unknown publisher; preserve me\n")
        self.assertFalse((self.destination / "orbit-swamp-release-load.py").exists())

    def test_retry_with_existing_package_keeps_publisher_executable(self):
        self.install()
        publisher = self.destination / "orbit-swamp-main-load.py"
        publisher.write_text(publisher.read_text().replace(installer.PENDING_GUARD, ""))
        publisher.chmod(0o755)
        self.install()
        self.assertEqual(publisher.stat().st_mode & 0o777, 0o755)
        self.assertEqual(publisher.read_text().count(installer.PENDING_GUARD), 1)


if __name__ == "__main__":
    unittest.main()
