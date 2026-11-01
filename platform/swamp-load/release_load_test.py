"""Publication and custody regressions; no live host, credentials or native runs."""
import copy
import fcntl
import io
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
            "gateway_release_record": 42, "target_node_id": 209,
            "attempt_id": "release42_attempt_0123456789", "action": action}


class FakeHost:
    def __init__(self):
        self.calls = []
        self.failure = None
        self.changed = False
        self.evidence = {"workflows": [{"id": "workflow", "equal": True}],
                         "extension_audit": {"checked": 1, "matched": 1, "deleted": ["retired/type"]}}

    def call(self, name):
        self.calls.append(name)
        if self.failure == name:
            raise RuntimeError("private-input-must-not-be-returned")

    def runtime(self):
        self.call("runtime")
        return {"pid": 1234, "invocation": "same-runtime"}

    def preflight(self, sha):
        self.call("preflight")
        return {"pid": 1234, "invocation": "same-runtime"}, "c" * 40, OTHER

    def stage(self, sha):
        self.call("stage:" + sha)

    def reload(self):
        self.call("reload")
        return {"success": True, "errors": []}

    def grants(self, sha, directory):
        self.call("grants")
        return {"source_sha": sha, "hash": "d" * 64, "scope_count": 4, "created_count": 0, "applied": True}

    def verify(self, old_sha, sha):
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
        return {**request("accept"), "receipt_hash": prepared["receipt_hash"]}


    def test_prepare_loads_only_pinned_source_and_keeps_custody_without_a_drain(self):
        receipt = self.prepare()
        self.assertEqual(receipt["state"], "awaiting_gateway_verification")
        self.assertEqual(receipt["disk_sha"], SHA)
        self.assertEqual(receipt["restarts"], 0)
        self.assertEqual(receipt["worker_restarts"], 0)
        self.assertNotIn("drain", receipt)
        self.assertEqual(self.host.calls, ["preflight", "stage:" + SHA, "reload", "grants", "verify", "health", "unchanged"])
        self.assertTrue((self.state / "pending.json").is_file())
        self.assertNotIn("configured_token_proof", receipt)

    def test_accept_binds_exact_receipt_and_rechecks_host_before_releasing_custody(self):
        prepared = self.prepare()
        accepted = loader.process(self.accept(prepared), self.host, self.state)
        self.assertEqual(accepted["state"], "verified")
        self.assertFalse((self.state / "pending.json").exists())
        calls = self.host.calls.copy()
        self.assertEqual(loader.process(self.accept(prepared), self.host, self.state), accepted)
        self.assertEqual(self.host.calls, calls)

    def test_invalid_identity_changes_nothing(self):
        for changes in ({"release_sha": "main"}, {"release_id": OTHER[:12]}, {"target_node_id": 13},
                        {"gateway_release_record": 0}, {"attempt_id": "../../other"}, {"action": "restart"}):
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

    def test_wrong_accept_identity_or_hash_cannot_clear_marker(self):
        prepared = self.prepare()
        valid = self.accept(prepared)
        for invalid in ({"receipt_hash": "wrong"}, {"gateway_release_record": 43}):
            with self.subTest(invalid=invalid), self.assertRaises(loader.Refused):
                loader.process({**valid, **invalid}, self.host, self.state)
            self.assertTrue((self.state / "pending.json").is_file())

    def test_no_effect_failure_leaves_no_marker_and_is_retryable(self):
        self.host.failure = "preflight"
        with self.assertRaises(loader.Refused) as caught:
            self.prepare()
        self.assertTrue(caught.exception.retry_safe)
        self.assertFalse((self.state / "pending.json").exists())
        self.assertEqual(self.host.calls, ["preflight"])
        self.host.failure = None
        self.assertEqual(self.prepare()["state"], "awaiting_gateway_verification")

    def test_changed_runtime_before_accept_stays_pending(self):
        prepared = self.prepare()
        self.host.changed = True
        with self.assertRaisesRegex(loader.Refused, "unexpected_runtime_change"):
            loader.process(self.accept(prepared), self.host, self.state)
        self.assertTrue((self.state / "pending.json").is_file())


class DefinitionVerificationTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.host = loader.Host()
        self.host.checkout = Path(self.temporary.name)
        self.path = self.host.checkout / "swamp/workflows/changed.yaml"
        self.path.parent.mkdir(parents=True)
        self.path.write_text("id: 'changed-id'\nname: '@nckrtl/settings'\n")
        self.rows = [{"id": "changed-id", "name": "@nckrtl/settings"}, {"id": "unchanged-id", "name": "unchanged"}]
        self.disk = {"id": "changed-id", "name": "@nckrtl/settings", "jobs": [{"steps": []}], "path": "/disk/local"}
        self.served = {**self.disk, "path": "/serve/elsewhere"}
        self.audit = {"checked": 2, "matched": 2, "errors": [], "mismatches": [], "scope": "source versions", "limit": "not bytes"}
        self.calls = []
        self.bad_inventory = False

    def native(self, *args, remote=False):
        self.calls.append((args, remote))
        if args[:2] == ("workflow", "list"):
            return {"results": self.rows[:-1] if remote and self.bad_inventory else self.rows}
        if args[:2] == ("workflow", "get"):
            self.assertEqual(args[2], "changed-id")
            return self.served if remote else self.disk
        raise AssertionError(args)

    def verify(self):
        from types import SimpleNamespace
        audit_module = SimpleNamespace(audit=lambda app, describe: self.audit)
        spec = SimpleNamespace(loader=SimpleNamespace(exec_module=lambda module: None))
        with patch.object(loader.Host, "native", side_effect=self.native), \
             patch.object(loader.Host, "git", return_value="swamp/workflows/changed.yaml"), \
             patch.object(loader.importlib.util, "spec_from_file_location", return_value=spec), \
             patch.object(loader.importlib.util, "module_from_spec", return_value=audit_module):
            return self.host.verify(OTHER, SHA)

    def test_quoted_names_and_ids_parse_and_only_changed_definition_gets_run(self):
        result = self.verify()
        self.assertTrue(result["workflow_inventory"]["equal"])
        self.assertEqual(result["workflow_inventory"]["disk_count"], 2)
        self.assertEqual(result["workflow_inventory"]["cli_get_calls"], 2)
        self.assertEqual([call for call in self.calls if call[0][:2] == ("workflow", "get")],
                         [(("workflow", "get", "changed-id"), False), (("workflow", "get", "changed-id"), True)])
        self.assertEqual(result["workflows"][0]["disk_hash"], result["workflows"][0]["served_hash"])

    def test_mismatched_full_inventory_and_stale_changed_definition_fail_closed(self):
        self.bad_inventory = True
        with self.assertRaisesRegex(loader.Refused, "served_workflow_inventory_mismatch"):
            self.verify()
        self.bad_inventory = False
        self.served["jobs"] = [{"steps": [{"name": "stale"}]}]
        with self.assertRaisesRegex(loader.Refused, "served_workflow_mismatch"):
            self.verify()

    def test_hot_reload_verification_never_depends_on_active_run_history(self):
        result = self.verify()
        self.assertNotIn("drain", result)
        self.assertFalse(any(call[0][:2] == ("run", "history") for call in self.calls))

    def test_failed_existing_version_audit_fails_closed(self):
        self.audit["matched"] = 1
        with self.assertRaisesRegex(loader.Refused, "served_extension_version_mismatch"):
            self.verify()


class HostCommandTest(unittest.TestCase):
    def test_new_commit_is_fetched_before_it_is_resolved(self):
        calls = []
        fetched = False

        def git(*args):
            nonlocal fetched
            calls.append(args)
            if args == ("rev-parse", "HEAD"):
                return OTHER
            if args[:2] == ("fetch", "--no-tags"):
                self.assertEqual(args, ("fetch", "--no-tags", "origin", SHA))
                fetched = True
                return ""
            if args[:1] == ("rev-parse",):
                self.assertTrue(fetched, "A release commit need not exist locally before fetch")
                return SHA if args[1].endswith("^{commit}") else OTHER
            raise AssertionError(args)

        with patch.object(loader.Host, "clean"), patch.object(loader.Host, "runtime", return_value={"pid": 1}), \
             patch.object(loader.Host, "git", side_effect=git), \
             patch.object(Path, "read_text", return_value=json.dumps({"loaded_sha": OTHER, "runtime_after": {"pid": 1}})):
            loader.Host().preflight(SHA)
        self.assertLess(calls.index(("fetch", "--no-tags", "origin", SHA)),
                        calls.index(("rev-parse", SHA + "^{commit}")))

    def test_split_starting_source_refuses_before_fetch_or_effects(self):
        with patch.object(loader.Host, "clean"), patch.object(loader.Host, "runtime", return_value={"pid": 1}), \
             patch.object(loader.Host, "git", return_value=SHA) as git, \
             patch.object(Path, "read_text", return_value=json.dumps({"loaded_sha": OTHER, "runtime_after": {"pid": 1}})), \
             self.assertRaisesRegex(loader.Refused, "starting_source_requires_recovery"):
                loader.Host().preflight(SHA)
        self.assertEqual(git.call_args_list, [unittest.mock.call("rev-parse", "HEAD")])

    def test_grants_cannot_start_native_commands_after_the_load_deadline(self):
        host = loader.Host()
        host.deadline = 0
        raw = b'{"version":2,"principal":"user:orbit-gateway","workflows":[{"workflow":"test","actions":["run","read"]}],"vaults":[]}'

        def reconcile(raw, sha, directory, native):
            native(["access", "grant", "list"])

        with patch.object(Path, "read_bytes", return_value=raw), \
             patch.object(loader.manifest_grants, "reconcile", side_effect=reconcile), \
             patch.object(loader.manifest_grants, "native") as native, \
             self.assertRaisesRegex(loader.Refused, "load_deadline_exceeded"):
                host.grants(SHA, Path("unused"))
        native.assert_not_called()

    def test_partial_native_reload_is_not_success(self):
        with patch.object(loader.Host, "command", return_value='{"success":true,"errors":["private detail"]}'), \
             self.assertRaisesRegex(loader.Refused, "native_reload_unconfirmed"):
                loader.Host().reload()

    def test_clean_environment_uses_absolute_privilege_tool_without_inheriting_credentials(self):
        result = subprocess.CompletedProcess([], 0, "public result", "")
        with patch.object(loader.subprocess, "run", return_value=result) as run:
            self.assertEqual(loader.Host().command(["git", "status"], owner=True), "public result")
        args = run.call_args.args[0]
        self.assertEqual(args[:6], ["/usr/sbin/runuser", "-u", "orbit", "--", "env", "-i"])
        self.assertNotIn("SWAMP_SERVER_TOKEN", repr(run.call_args.kwargs))
        self.assertNotIn("SWAMP_API_KEY", repr(run.call_args.kwargs))


class EntryLockTest(unittest.TestCase):
    def test_busy_lock_is_retryable_without_creating_custody(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "publication.lock"
            path.touch()
            output = io.StringIO()
            with path.open("r") as held:
                fcntl.flock(held, fcntl.LOCK_EX | fcntl.LOCK_NB)
                with patch.object(loader, "LOCK", path), patch.object(loader, "STATE", Path(directory) / "state"), \
                     patch.object(loader.os, "geteuid", return_value=0), \
                     patch.object(loader.sys, "argv", ["entry", "--stdin-json"]), \
                     patch.object(loader.sys, "stdin", io.StringIO(json.dumps(request()))), \
                     patch.object(loader.sys, "stdout", output):
                    self.assertEqual(loader.main(), 1)
            result = json.loads(output.getvalue())
            self.assertEqual(result["code"], "publication_busy")
            self.assertTrue(result["retry_safe"])
            self.assertFalse((Path(directory) / "state/pending.json").exists())

    def test_lock_open_never_creates_or_follows_a_symlink(self):
        for symlink in (False, True):
            with self.subTest(symlink=symlink), tempfile.TemporaryDirectory() as directory:
                path = Path(directory) / "publication.lock"
                target = Path(directory) / "preserved"
                target.write_text("preserve")
                if symlink:
                    path.symlink_to(target)
                with patch.object(loader, "LOCK", path), patch.object(loader, "STATE", Path(directory) / "state"), \
                     patch.object(loader.os, "geteuid", return_value=0), \
                     patch.object(loader.sys, "argv", ["entry", "--stdin-json"]), \
                     patch.object(loader.sys, "stdin", io.StringIO(json.dumps(request()))), \
                     patch.object(loader.sys, "stdout", io.StringIO()):
                    self.assertEqual(loader.main(), 1)
                self.assertEqual(target.read_text(), "preserve")
                if not symlink:
                    self.assertFalse(path.exists())


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



if __name__ == "__main__":
    unittest.main()
