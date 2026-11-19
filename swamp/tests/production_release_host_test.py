"""Verify target ownership and route recovery at the AppProd publishing boundary."""
import importlib.util
import os
import runpy
import sqlite3
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "ansible/module_utils"))
spec = importlib.util.spec_from_file_location("release_routes", ROOT / "ansible/files/instance-release-routes.py")
routes = importlib.util.module_from_spec(spec)
spec.loader.exec_module(routes)


def site(role, scope, listener, home):
    return f"# orbit: {role} {scope}\n{listener} {{\n    tls internal\n    root * {home}/current/public\nphp_fastcgi unix//tmp/php.sock\nfile_server\n}}\n"


class ProductionReleaseHostTest(unittest.TestCase):
    def test_stock_image_refresh_can_only_replace_a_verified_unserved_preparer(self):
        helper = runpy.run_path(str(ROOT / "ansible/files/instance-release-prepare.py"))["refresh_image"]
        original = { "config": {"user.orbit.release.target": "instance-4", "user.orbit.release.role": "prepare",
                                "boot.autostart": "false", "volatile.base_image": "a" * 64}, "profiles": []}
        updated = {**original, "config": {**original["config"], "volatile.base_image": "b" * 64}}
        calls = []
        def command(*args, **kwargs):
            calls.append(args)
        def read(path):
            return [] if path.endswith("/snapshots") else updated
        with patch.dict(helper.__globals__, inc=command, query=read):
            self.assertIs(helper("prepare-instance-4", "instance-4", "a" * 64, original), original)
            for wrong in ("instance-4-aaaaaaaaaaaa", "prepare-instance-5"):
                with self.assertRaises(ValueError):
                    helper(wrong, "instance-4", "b" * 64, original)
            self.assertEqual(calls, [])
            self.assertIs(helper("prepare-instance-4", "instance-4", "b" * 64, original), updated)
            self.assertEqual(calls, [("rebuild", "b" * 64, "prepare-instance-4", "--force")])

    def test_preparer_gets_the_browser_files_and_a_fresh_linker_cache(self):
        module = runpy.run_path(str(ROOT / "ansible/files/instance-release-prepare.py"))
        install = module["install_browser_files"]
        calls = []
        install.__globals__["guest"] = lambda name, *args, **kwargs: calls.append((name, args, kwargs.get("stdin")))
        files = [{"path": "/etc/ld.so.conf.d/zz-orbit-browser.conf", "content": "/opt/orbit-runtime/current/lib\n"},
                 {"path": "/etc/fonts/fonts.conf", "content": "<fontconfig/>\n"}]
        install("prepare-instance-4", files)
        self.assertEqual([(call[1][-1], call[2]) for call in calls[:2]], [(item["path"], item["content"]) for item in files])
        self.assertEqual(calls[-1], ("prepare-instance-4", ("ldconfig",), None))
        calls.clear()
        with self.assertRaises(ValueError):
            install("prepare-instance-4", [{"path": "/etc/sudoers.d/x", "content": "x"}])
        self.assertEqual(calls, [])

    def test_public_and_private_routes_bind_to_the_same_approved_instance(self):
        private = site("app-prod", "app-instance-19", "https://one.test", "/home/orbit-app-2")
        public = site("ingress", "route-14-ingress", "one.test", "/home/orbit-app-2")
        neighbor = site("ingress", "route-24-ingress", "two.test", "/home/orbit-app-7")
        declaration = [{"id":14,"domain":"one.test"}]
        before = private + public + neighbor
        after = routes.with_import(before, 19, declaration, "/home/orbit-app-2")
        self.assertEqual(after.count("import /var/lib/orbit/production/19/selected/*.caddy"), 2)
        self.assertTrue(after.endswith(neighbor))
        self.assertEqual(routes.with_import(after, 19, declaration, "/home/orbit-app-2"), after)
        with self.assertRaises(ValueError):
            routes.with_import(public, 19, declaration, "/home/orbit-app-7")
        with self.assertRaises(ValueError):
            routes.with_import(private, 19, [{"id":14,"domain":"another.test"}], "/home/orbit-app-2")

    def test_restore_removes_first_selection_and_preserves_an_existing_container_upstream(self):
        with tempfile.TemporaryDirectory() as temp, patch.object(routes, "BASE", Path(temp)):
            root = Path(temp) / "31"
            directory = root / "selected"
            directory.mkdir(parents=True)
            (root/"live").symlink_to("instance-31-" + "f"*12)
            selected = directory / "upstream.caddy"
            selected.symlink_to("../upstream.caddy")
            routes.execute("restore", 31, 8, {"sha":"f"*40, "previous":"b"*40})
            self.assertTrue(selected.is_symlink())
            routes.execute("restore", 31, 8, {"sha":"f"*40, "previous":None})
            self.assertFalse(selected.is_symlink())

    def test_shared_path_cannot_escape_the_admitted_home(self):
        for value in ["../other", "database.sqlite", ".ssh", "folder//window", "/srv/tmp", "foo/../../bar"]:
            with self.subTest(value=value), self.assertRaises(ValueError):
                list(routes.local_paths([value]))
        self.assertEqual(list(routes.local_paths(["storage/app"])), [Path("storage/app")])

    def test_first_move_detects_deletion_and_backdated_overwrite_and_resets_aborted_copy(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            home, volume, marker = root / "source", root / "target", root / "witness"
            uploads = home / "storage" / "uploads"
            uploads.mkdir(parents=True)
            volume.mkdir()
            paths = ["storage/uploads", "empty"]
            (volume / "neighbor").write_text("retained")
            source = uploads / "first"
            source.write_bytes(b"abc")
            (uploads / "second").write_text("second")
            def prepare():
                routes.shared_snapshot([], home, paths, marker, guest=[], destination=str(volume))
            def check():
                routes.shared_snapshot([], home, paths, marker)
            prepare()
            check()
            uploaded = volume / "storage/uploads"
            (uploaded / "built").write_text("migration ran in the candidate")
            original = source.stat()
            source.write_bytes(b"xyz")
            os.utime(source, ns=(original.st_atime_ns, original.st_mtime_ns))
            with self.assertRaisesRegex(ValueError, "changed after preparation"):
                check()
            self.assertEqual((uploaded / "first").read_bytes(), b"abc")
            self.assertTrue((uploaded / "built").exists())
            prepare()
            check()
            self.assertFalse((uploaded / "built").exists())
            self.assertEqual((uploaded / "first").read_bytes(), b"xyz")
            (uploads / "second").unlink()
            with self.assertRaisesRegex(ValueError, "changed after preparation"):
                check()
            prepare()
            self.assertFalse((uploaded / "second").exists())
            self.assertEqual((volume / "neighbor").read_text(), "retained")
            check()

    def test_repreparation_discards_abandoned_sqlite_sidecars_before_installing_the_source(self):
        with tempfile.TemporaryDirectory() as temp:
            home = Path(temp)
            source, target = home / "legacy.sqlite", home / "database.sqlite"
            with sqlite3.connect(source) as db:
                db.execute("CREATE TABLE events(value TEXT)")
                db.execute("INSERT INTO events VALUES ('authoritative')")
            target.write_bytes(b"previous attempt")
            for suffix in ("-wal", "-shm", "-journal"):
                (home / (target.name + suffix)).write_bytes(b"obsolete journal")
            with source.open("rb") as snapshot:
                subprocess.run([sys.executable, "-I", "-c", routes.INSTALL_SQLITE, str(target)], stdin=snapshot, check=True)
            for suffix in ("-wal", "-shm", "-journal"):
                self.assertFalse((home / (target.name + suffix)).exists())
            with sqlite3.connect(target) as db:
                self.assertEqual(db.execute("SELECT value FROM events").fetchall(), [("authoritative",)])


if __name__ == "__main__":
    unittest.main()
