"""Verify target ownership and route recovery at the AppProd publishing boundary."""
import importlib.util
import json
import os
import runpy
import shutil
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

    def test_the_preparer_refuses_a_dir_release_pool_before_it_creates_a_volume(self):
        module = runpy.run_path(str(ROOT / "ansible/files/instance-release-prepare.py"))
        prepare = module["prepare"]
        with tempfile.TemporaryDirectory() as temp:
            runtime = Path(temp).resolve() / "runtime"
            (runtime / "php/8.5/bin").mkdir(parents=True)
            (runtime / "php/8.5/bin/frankenphp").write_text("")
            config = Path(temp) / "production-release.json"
            config.write_text(json.dumps({"pool": "orbit-releases", "runtime": str(runtime), "network": "orbitinst0",
                                          "proxy": "http://10.0.0.1:3128"}))
            calls = []
            real_path = Path
            prepare.__globals__.update(
                ROOT=real_path(temp), Path=lambda value: config if value == "/etc/orbit/production-release.json" else real_path(value),
                query=lambda path, project=None: {"driver": "dir"}, inc=lambda *args, **kwargs: calls.append(args) or "[]")
            with self.assertRaisesRegex(ValueError, "release.pool_driver_unsupported: the release pool uses the dir driver"):
                prepare(4, "8.5")
            self.assertEqual(calls, [])

    def test_the_release_pool_is_btrfs_on_a_nested_node_and_zfs_on_a_host_and_never_dir(self):
        host = runpy.run_path(str(ROOT / "ansible/files/production-release-host.py"))

        class Admitted(Exception):
            pass

        def provision(nested, driver):
            pools = [] if driver is None else [{"name": "orbit-releases", "driver": driver}]

            def incus(*args):
                if args[:2] == ("storage", "list"):
                    return json.dumps(pools)
                if args[0] == "query":
                    return json.dumps(pools[0])
                raise Admitted(args)
            with patch.dict(host["provision"].__globals__, {"incus": incus}), self.assertRaises((Admitted, ValueError)) as caught:
                host["provision"](nested, "10.253.2.0/24")
            return caught.exception

        self.assertIsInstance(provision(True, "btrfs"), Admitted)
        self.assertIsInstance(provision(False, "zfs"), Admitted)
        for nested, driver, message in ((True, "dir", "require a btrfs pool; move the dir pool orbit-releases to btrfs"),
                                        (True, "zfs", "require a btrfs pool"), (False, "dir", "require a zfs pool"),
                                        (False, "btrfs", "require a zfs pool"), (True, None, "creates the nested orbit-releases btrfs pool")):
            with self.subTest(nested=nested, driver=driver):
                self.assertIn(message, str(provision(nested, driver)))

    @unittest.skipUnless(shutil.which("php"), "needs php")
    def test_release_php_ini_is_the_builds_production_ini_with_the_security_settings(self):
        script = runpy.run_path(str(ROOT / "ansible/files/instance-release-prepare.py"))["PHP_INI"]
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            folder = root / "runtime/php/8.5"
            folder.mkdir(parents=True)
            # php.ini-production's own values, which the Orbit lines must override or keep.
            (folder / "php.ini-production").write_text("zend.exception_ignore_args = On\nexpose_php = On\n"
                                                         "session.upload_progress.enabled = On\ndisplay_errors = Off\n")
            local = script.replace("/opt/orbit-runtime/current", str(root / "runtime")).replace("/etc/orbit/php", str(root / "php"))
            subprocess.run(["sh", "-ec", local, "sh", "8.5"], check=True)
            ini = root / "php/php.ini"
            self.assertEqual(oct(ini.stat().st_mode & 0o777), "0o644")
            code = 'foreach (["zend.exception_ignore_args", "session.upload_progress.enabled", "display_errors", "expose_php"] as $n) echo ini_get($n), ",";'
            read = subprocess.run(["php", "-n", "-c", str(ini), "-r", code], capture_output=True, text=True, check=True)
            self.assertEqual(read.stdout, "1,0,0,0,")
            # A runtime without the build's php.ini-production fails the preparation and leaves no php.ini.
            ini.unlink()
            with self.assertRaises(subprocess.CalledProcessError):
                subprocess.run(["sh", "-ec", local, "sh", "8.4"], check=True, capture_output=True)
            self.assertFalse(ini.exists())

    def test_preparer_gets_the_browser_files_and_a_fresh_linker_cache(self):
        module = runpy.run_path(str(ROOT / "ansible/files/instance-release-prepare.py"))
        install = module["install_browser_files"]
        calls = []
        install.__globals__["guest"] = lambda name, *args, **kwargs: calls.append((name, args, kwargs.get("stdin")))
        files = [{"path": "/etc/ld.so.conf.d/zz-orbit-browser.conf", "content": "/opt/orbit-runtime/current/lib\n"},
                 {"path": "/etc/fonts/fonts.conf", "content": "<fontconfig/>\n"}]
        install("prepare-instance-4", {"runtime": "/opt/orbit-runtime/a", "browser_files": files})
        self.assertEqual([(call[1][-1], call[2]) for call in calls[:2]], [(item["path"], item["content"]) for item in files])
        self.assertEqual(calls[-1], ("prepare-instance-4", ("ldconfig",), None))
        calls.clear()
        with self.assertRaises(ValueError):
            install("prepare-instance-4", {"browser_files": [{"path": "/etc/sudoers.d/x", "content": "x"}]})
        self.assertEqual(calls, [])

    def test_preparer_installs_no_browser_files_from_a_config_an_earlier_converge_wrote(self):
        module = runpy.run_path(str(ROOT / "ansible/files/instance-release-prepare.py"))
        install = module["install_browser_files"]
        calls = []
        install.__globals__["guest"] = lambda name, *args, **kwargs: calls.append((name, args))
        install("prepare-instance-4", {"node_id": 4, "pool": "orbit-releases", "network": "orbitinst0",
                                       "proxy": "http://10.0.0.1:3128", "runtime": "/opt/orbit-runtime/a"})
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
