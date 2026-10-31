"""Real Git and filesystem regressions; only the system account boundary is fake."""

import base64
import contextlib
import copy
import grp
import io
import json
import os
import pwd
import runpy
import subprocess
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import identity

ROOT = Path(__file__).parent


def program(step_name):
    return (ROOT / (step_name.replace("-", "_") + ".py")).read_text()


class RemovalTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="instance-removal-")
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.home_root = self.root / "home"
        self.home_root.mkdir()
        self.state_root = self.root / "states"
        self.state_root.mkdir(mode=0o700)
        self.member = {
            "instance_id": 71, "project_id": 11, "environment": "production",
            "user": "orbit-app-11", "production_home": str(self.home_root / "orbit-app-11"),
            "checkout_path": str(self.home_root / "orbit-app-11/releases/initial"),
            "source_layout": "checkout", "failed_creation": False,
            "starting_commit": None, "branch": "main",
            "repository_identity": "github.com/hardimpactdev/fixture",
        }
        self.intent = {
            "removal_id": "1699c2db-8119-4439-a685-7e555f6a0641",
            "repository": "https://github.com/hardimpactdev/fixture.git",
            "force": False, "members": [self.member], "protected_source_roots": [],
        }
        self.owner = pwd.getpwuid(os.geteuid())
        self.patchers = [
            patch.object(identity, "HOME_ROOT", str(self.home_root)),
            patch.object(identity, "STATE_ROOT", str(self.state_root)),
            patch.object(identity, "account", return_value=self.owner),
        ]
        for item in self.patchers:
            item.start()
            self.addCleanup(item.stop)

    def git(self, *args):
        subprocess.run(["git", *args], check=True, stdin=subprocess.DEVNULL,
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                       env={**os.environ, "GIT_CONFIG_NOSYSTEM": "1", "GIT_CONFIG_GLOBAL": "/dev/null"})

    def production(self, release=True):
        home = Path(self.member["production_home"])
        home.mkdir()
        if release:
            source = Path(self.member["checkout_path"])
            source.mkdir(parents=True)
            self.git("init", "-q", "-b", "main", str(source))
            self.git("-C", str(source), "config", "user.email", "test@example.invalid")
            self.git("-C", str(source), "config", "user.name", "Removal test")
            self.git("-C", str(source), "remote", "add", "origin", self.intent["repository"])
            (source / "storage").mkdir()
            (source / "storage/.gitignore").write_text("*\n!.gitignore\n")
            self.git("-C", str(source), "add", ".")
            self.git("-C", str(source), "commit", "-qm", "fixture")
            (home / "current").symlink_to("releases/initial")
        marker = self.state_root / "71"
        marker.mkdir(mode=0o700)
        leaf = marker / "release-layout"
        leaf.write_bytes((self.intent["repository"] + "\0orbit-app-11\0" + str(home) + "\0initial\0").encode())
        leaf.chmod(0o600)
        return home

    def action(self, name, capture):
        value = {"intent": self.intent, "member": self.member, "capture": capture}
        output = io.StringIO()
        path = self.root / "inline-action.py"
        path.write_text(program(name))
        with patch.dict(os.environ, {"ORBIT_REMOVAL_MEMBER": json.dumps(value)}), contextlib.redirect_stdout(output):
            runpy.run_path(str(path), run_name="__main__")
        return json.loads(output.getvalue())

    def test_nonforce_laravel_shared_storage_is_a_deploy_artifact(self):
        home = self.production()
        source = Path(self.member["checkout_path"])
        (source / "storage/.gitignore").unlink()
        (source / "storage").rmdir()
        (home / "shared/storage").mkdir(parents=True)
        (source / "storage").symlink_to("../../shared/storage")
        capture = identity.capture_member(self.member, self.intent)
        self.assertRegex(capture["source_commit"], "^[a-f0-9]{40}$")
        self.assertTrue(identity.verify_source(self.member, capture, self.intent)["source_present"])

    def test_partial_production_without_current_is_capturable(self):
        self.production(release=False)
        capture = identity.capture_member(self.member, self.intent)
        self.assertIsNone(capture["release"])
        self.assertFalse(identity.verify_source(self.member, capture, self.intent)["source_present"])

    def test_absent_account_and_home_is_capturable(self):
        with patch.object(identity, "account", return_value=None):
            capture = identity.capture_member(self.member, self.intent)
        self.assertEqual(capture["source_identity"], "absent")
        self.assertIsNone(capture["uid"])

    def test_no_marker_cannot_authorize_a_nonempty_home(self):
        home = Path(self.member["production_home"])
        home.mkdir()
        (home / "private").write_text("data")
        with self.assertRaises(identity.Refusal):
            identity.capture_member(self.member, self.intent)

    def test_origin_mismatch_refuses_before_any_effect(self):
        self.production()
        source = self.member["checkout_path"]
        self.git("-C", source, "remote", "set-url", "origin", "https://github.com/other/fixture.git")
        with self.assertRaisesRegex(identity.Refusal, "instance.source_origin_mismatch"):
            identity.capture_member(self.member, self.intent)
        self.assertTrue(Path(source).is_dir())

    def test_replacement_home_cannot_be_quarantined(self):
        home = self.production()
        capture = identity.capture_member(self.member, self.intent)
        home.rename(str(home) + "-original")
        home.mkdir()
        with self.assertRaises(SystemExit):
            self.action("quarantine", capture)
        self.assertTrue(home.is_dir())

    def test_quarantine_and_partial_delete_resume_without_a_custom_journal(self):
        self.production()
        capture = identity.capture_member(self.member, self.intent)
        self.assertTrue(self.action("quarantine", capture)["quarantined"])
        target = Path(identity.quarantine_path(self.member, capture, self.intent["removal_id"]))
        (target / "releases/initial/storage/.gitignore").unlink()
        self.assertTrue(self.action("quarantine", capture)["quarantined"])
        self.assertTrue(self.action("delete-tree", capture)["source_absent"])
        self.assertTrue(self.action("delete-tree", capture)["source_absent"])
        self.assertFalse(target.exists())

    def test_replacement_marker_is_not_deleted(self):
        self.production()
        capture = identity.capture_member(self.member, self.intent)
        self.action("quarantine", capture)
        self.action("delete-tree", capture)
        marker = Path(capture["marker"]["path"])
        marker.rename(str(marker) + "-original")
        marker.mkdir(mode=0o700)
        with patch.object(identity, "account", return_value=None), self.assertRaises(SystemExit):
            self.action("delete-marker", capture)
        self.assertTrue(marker.exists())

    def test_production_capture_keeps_original_inventory_and_origin(self):
        self.production()
        capture = identity.capture_member(self.member, self.intent)
        before = copy.deepcopy(capture)
        self.action("quarantine", capture)
        self.action("delete-tree", capture)
        self.assertEqual(capture, before)
        self.assertEqual(capture["origin"], self.intent["repository"])
        self.assertTrue(base64.b64decode(capture["worktree_inventory"]).endswith(b"\0\0"))

    def test_changed_unadmitted_common_checkout_cannot_be_ignored_during_worktree_retry(self):
        source_root = self.root / 'apps'
        primary = source_root / 'fixture/main'
        primary.mkdir(parents=True)
        self.git('init', '-q', '-b', 'main', str(primary))
        self.git('-C', str(primary), 'config', 'user.email', 'test@example.invalid')
        self.git('-C', str(primary), 'config', 'user.name', 'Removal test')
        self.git('-C', str(primary), 'remote', 'add', 'origin', self.intent['repository'])
        (primary / 'file').write_text('one')
        self.git('-C', str(primary), 'add', '.')
        self.git('-C', str(primary), 'commit', '-qm', 'one')
        linked = source_root / 'fixture/feature'
        self.git('-C', str(primary), 'worktree', 'add', '-qb', 'feature', str(linked))
        member = {**self.member, 'environment': 'development', 'source_root': str(source_root),
                  'source_layout': 'worktree', 'checkout_path': str(linked), 'branch': 'feature'}
        intent = {**self.intent, 'force': True, 'members': [member]}
        capture = identity.capture_member(member, intent)
        intent['captured_sources'] = {'members': [capture]}
        (primary / 'file').write_text('two')
        self.git('-C', str(primary), 'commit', '-am', 'two')
        with self.assertRaises(identity.Refusal):
            identity.verify_source(member, capture, intent, allow_quarantine=True)
        self.assertTrue(linked.is_dir())

    def test_retained_capture_adds_metadata_without_replacing_original_facts(self):
        self.production()
        full = identity.capture_member(self.member, self.intent)
        fields = ['instance_id', 'user', 'group', 'source_root', 'source_digest', 'source_identity',
                  'source_commit', 'common_repository_path', 'linked_worktree_paths', 'worktree_inventory', 'origin']
        retained = {key: full[key] for key in fields}
        retained['source_digest'] = 'a' * 64
        intent = {**self.intent, 'captured_sources': {'members': [retained]}}
        output = io.StringIO()
        with patch.dict(os.environ, {'ORBIT_REMOVAL_INTENT': json.dumps(intent)}), contextlib.redirect_stdout(output):
            runpy.run_path(str(ROOT / 'inspect_sources.py'), run_name='__main__')
        result = json.loads(output.getvalue())
        for key in fields:
            self.assertEqual(result['source_inspection']['members'][0][key], retained[key])
        self.assertEqual(result['source_inspection']['members'][0]['source'], full['source'])

    def test_schedule_service_with_a_missing_file_and_loaded_unit_refuses(self):
        schedule = '1699c2db-8119-4439-a685-7e555f6a0641'
        loaded = subprocess.CompletedProcess([], 0, b'loaded\n')
        with patch.dict(os.environ, {'ORBIT_SCHEDULE_ID': schedule}), patch.object(identity, 'owned_unit', return_value=False), patch.object(subprocess, 'run', return_value=loaded) as calls, self.assertRaises(SystemExit):
            runpy.run_path(str(ROOT / 'stop_schedule_service.py'), run_name='__main__')
        self.assertEqual(calls.call_count, 1)


class AccountTests(unittest.TestCase):
    def test_canonical_production_account_policy(self):
        member = {"user": "orbit-app-11", "project_id": 11, "production_home": "/home/orbit-app-11"}
        group = grp.struct_group(("orbit-app-11", "x", 500, []))
        valid = ("orbit-app-11", "x", 500, 500, "", "/home/orbit-app-11", "/usr/sbin/nologin")
        for index, replacement in [(2, 1001), (5, "/home/other"), (6, "/bin/sh")]:
            fields = list(valid)
            fields[index] = replacement
            with patch.object(pwd, "getpwnam", return_value=pwd.struct_passwd(fields)), patch.object(grp, "getgrgid", return_value=group), self.assertRaises(identity.Refusal):
                identity.account(member, True)
        with patch.object(pwd, "getpwnam", return_value=pwd.struct_passwd(valid)), patch.object(grp, "getgrgid", return_value=group):
            self.assertEqual(identity.account(member, True).pw_uid, 500)


if __name__ == "__main__":
    unittest.main()
