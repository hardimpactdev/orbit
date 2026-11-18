"""Production releases isolate Instance state, restore failed Caddy reloads and retain recoverable builds."""
import importlib.util
import json
import os
import shutil
import socket
import subprocess
import sys
import tempfile
import time
import unittest
import urllib.error
import urllib.request
from contextlib import ExitStack
from pathlib import Path
from subprocess import CompletedProcess
from unittest.mock import MagicMock, patch

SWAMP = Path(__file__).resolve().parents[1]
CURRENT = "c" * 40
NEXT = "d" * 40
OLD = "b" * 40


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, SWAMP / path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


phase = load("production_phase", "ansible/files/instance-release-phase.py")
guest = load("production_guest", "ansible/files/instance-release-guest.py")


class ProductionReleaseTest(unittest.TestCase):
    def setUp(self):
        self.stack = ExitStack()
        self.addCleanup(self.stack.close)
        root = Path(self.stack.enter_context(tempfile.TemporaryDirectory()))
        config = root / "host.json"
        config.write_text(json.dumps({"node_id": 25, "pool": "selected"}))
        (root / "orbit-release-container").symlink_to(SWAMP / "scripts/release-container.py")
        self.stack.enter_context(patch.object(phase, "BASE", root / "instances"))
        self.stack.enter_context(patch.object(phase, "LIB", root))
        self.stack.enter_context(patch.object(phase, "CONFIG", config))
        self.r = phase.Release("21", NEXT, 300)
        self.r.private.mkdir(parents=True)
        self.r.inventory = {"live": CURRENT, "old": OLD}
        self.r.state = {"sha": NEXT, "operation": 300, "previous": CURRENT, "previous_old": OLD,
                        "legacy": None, "completed_steps": [], "units": [], "application_suffix": ""}
        self.r.link(CURRENT)
        self.upstream = self.r.root / "upstream.caddy"
        self.upstream.write_text("reverse_proxy 10.0.0.7:8080\n")
        self.plan = {
            "instance_id": 21, "operation_id": 300, "node_id": 25, "sha": NEXT, "expected_live": CURRENT,
            "action": "deploy", "php_version": "8.5", "routes": [{"id": 4, "domain": "nested.test", "web_root": "public"}],
            "shared_paths": ["storage"], "health_path": "/up", "application_suffix": "", "units": [], "steps": [],
        }

    def test_all_private_identities_are_bound_before_a_guest_operation(self):
        self.r.parse_plan(self.plan)
        for key, changed in [("node_id", 18), ("instance_id", 22), ("operation_id", 301), ("sha", OLD)]:
            with self.subTest(key=key), self.assertRaisesRegex(phase.Refusal, "admitted identity"):
                self.r.parse_plan({**self.plan, key: changed})
        with self.assertRaisesRegex(phase.Refusal, "first move"):
            self.r.parse_plan({**self.plan, "legacy": {"project_id": 2, "home": "/home/orbit-app-2", "units": [], "commit": CURRENT}})

    def test_shape_admits_only_the_owned_host_transaction(self):
        path = "ansible/files/instance-release-phase.py"
        source = (SWAMP / path).read_text()
        for code, allowed in [
            (source, True),
            (source.replace('Path("/var/lib/orbit/production")', 'Path("/tmp")'), False),
            (source.replace('os.O_NOFOLLOW | ', ''), False),
            (source.replace('not re.fullmatch(r"[.]capacity', 'not re.search(r"[.]capacity'), False),
        ]:
            with self.subTest(allowed=allowed):
                output = subprocess.run([sys.executable, SWAMP / "bin/swamp-shape-python.py", "[]", "--source-context"],
                                        input=json.dumps({"source": code, "sources": {path: code}}),
                                        text=True, capture_output=True, check=True)
                self.assertEqual(all(finding["rule"] != "custom-control" for finding in json.loads(output.stdout)), allowed)

    def test_guest_env_preserves_a_tracked_testing_file_in_a_monorepo(self):
        with tempfile.TemporaryDirectory() as base:
            workspace = Path(base)
            app = workspace / "current/apps/web"
            app.mkdir(parents=True)
            subprocess.run(["git", "init", "-q", str(workspace / "current")], check=True)
            testing = app / ".env.testing"
            testing.write_text("from source")
            subprocess.run(["git", "-C", str(app), "add", ".env.testing"], check=True)
            plan = {"routes": [{"web_root": "apps/web/public"}], "application_suffix": "/apps/web",
                    "environment": "managed", "testing_environment": "generated"}
            with patch.object(guest, "WORK", workspace):
                guest.environment(plan)
                self.assertEqual(testing.read_text(), "from source")
                subprocess.run(["git", "-C", str(app), "rm", "--cached", "-q", ".env.testing"], check=True)
                guest.environment(plan)
                self.assertEqual(testing.read_text(), "generated")

    def test_pinned_step_runs_from_the_checkout_root_before_and_after_activation(self):
        prefix = ["env", "VP_HOME=/home/orbit/.local/share/vite-plus", "/opt/orbit-runtime/current/bin/vp",
                  "env", "exec", "--node", "24.22.1", "--package-manager", "npm@11.10.1", "--"]
        self.r.state.update(application_suffix="/apps/web", vp=prefix)
        with patch.object(self.r, "user", return_value=CompletedProcess([], 0)) as command:
            for phase_name in ("before", "after"):
                self.r.steps(self.r.prep, [{"name": "js", "phase": phase_name + "_activation", "command": "cd apps/web && npm run build",
                                           "timeout_seconds": 30}], phase_name)
                self.assertEqual(command.call_args.kwargs["cwd"], phase.WORK)
                self.assertEqual(list(command.call_args.args[4:-4]), prefix)

    def test_a_changed_live_link_never_enters_preparation(self):
        self.r.link(OLD)
        with patch.object(self.r, "reserve") as reserve, self.assertRaisesRegex(phase.Refusal, "live link"):
            self.r.build(self.plan)
        reserve.assert_not_called()

    def test_reload_failure_restores_only_the_selected_instance_before_unlocking_caddy(self):
        other = phase.Release(22, OLD, 301)
        other.private.mkdir(parents=True)
        other.link(OLD)
        (other.root / "upstream.caddy").write_text("reverse_proxy 10.0.0.9:8080\n")
        self.r.state.update(saved_upstream=self.upstream.read_text(), address="10.0.0.8")
        reloads = []

        def command(args):
            if args[:2] == ["systemctl", "reload"]:
                reloads.append((self.upstream.read_text(), os.readlink(self.r.root / "live")))
                if len(reloads) == 1:
                    raise phase.Refusal("failed reload")

        with patch.object(self.r, "route_files") as route, patch.object(phase, "run", side_effect=command), self.assertRaises(phase.Refusal):
            self.r.route_switch()
        self.assertEqual([item.args[0] for item in route.call_args_list], ["select", "restore"])
        self.assertEqual(reloads, [("reverse_proxy 10.0.0.8:8080\n", self.r.name),
                                   ("reverse_proxy 10.0.0.7:8080\n", self.r.container(CURRENT))])
        self.assertEqual((other.root / "upstream.caddy").read_text(), "reverse_proxy 10.0.0.9:8080\n")
        other.check_selection(OLD)

    def test_the_earlier_rollback_survives_a_failed_swap_reclaim(self):
        self.r.link(NEXT)
        self.r.state["verified"] = True
        with patch.object(self.r, "guest"), patch.object(self.r, "identity"), \
                patch.object(self.r, "primitive", side_effect=ValueError("reclaim")), patch.object(self.r, "discard") as discard:
            with self.assertRaisesRegex(ValueError, "reclaim"):
                self.r.retire()
            discard.assert_not_called()

    def test_an_interrupted_non_idempotent_application_step_is_not_replayed(self):
        step = {"name": "migrate", "phase": "before_activation", "command": "echo migrated", "timeout_seconds": 15}
        with patch.object(self.r, "user", return_value=CompletedProcess([], 0)) as run:
            self.r.steps(self.r.prep, [step], "before")
            self.r.steps(self.r.prep, [step], "before")
            run.assert_called_once()
            self.r.persist(executing="after-0")
            with self.assertRaisesRegex(phase.Refusal, "interrupted"):
                self.r.steps(self.r.name, [step], "after")
            run.assert_called_once()

    def test_best_effort_steps_record_bounded_exit_status_on_both_sides_of_activation(self):
        first = {"name": "warm", "phase": "before_activation", "command": "exit 17", "timeout_seconds": 15, "required": False}
        second = {**first, "name": "publish", "phase": "after_activation"}
        with patch.object(self.r, "user", side_effect=[CompletedProcess([], 17), CompletedProcess([], 0)]) as command:
            self.r.steps(self.r.prep, [first], "before")
            self.r.steps(self.r.name, [second], "after")
            self.r.steps(self.r.prep, [first], "before")
            self.assertEqual(command.call_count, 2)
        self.r.link(None)
        receipt = self.r.readback()
        self.assertEqual(receipt["best_effort_steps"],
                         [{"name":"warm", "phase":"before_activation", "exit_code":17},
                          {"name":"publish", "phase":"after_activation", "exit_code":0}])
        self.r.operation += 1
        self.assertNotIn("best_effort_steps", self.r.readback())

    def test_frankenphp_serves_public_assets_and_denies_private_files_at_both_web_roots(self):
        php = shutil.which("frankenphp") or "/opt/orbit-runtime/current/php/8.5/bin/frankenphp"
        if not os.access(php, os.X_OK):
            self.skipTest("requires the pinned FrankenPHP runtime")
        root = self.r.root / "site"
        for name in (".env", ".env.production", ".git/HEAD", "config/private.php", "storage/logs/test.log",
                     "private.yaml", "public/.env", "public/.git/HEAD"):
            path = root / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text("private fixture contents")
        for name in ("index.html", "assets/site.css", "public/index.html", "public/storage/visible.txt"):
            path = root / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text("public fixture")
        plan = {"routes": [{"domain":"root.test", "web_root":"."}, {"domain":"public.test", "web_root":"public"}],
                "shared_paths": ["storage"]}
        with patch.object(self.r, "guest") as write, patch.object(phase, "WORK", str(root)):
            self.r.write_service(plan)
        with socket.socket() as sock:
            sock.bind(("127.0.0.1", 0))
            port = sock.getsockname()[1]
        caddy = self.r.root / "Caddyfile"
        caddy.write_text(write.call_args.kwargs["data"].replace(":8080", f":{port}"))
        env = {**os.environ, "XDG_DATA_HOME":str(self.r.root), "XDG_CONFIG_HOME":str(self.r.root)}
        with subprocess.Popen([php, "run", "--config", caddy, "--adapter", "caddyfile"], env=env, stdout=subprocess.DEVNULL,
                              stderr=subprocess.DEVNULL) as child:
            try:
                deadline = time.monotonic() + 10
                while time.monotonic() < deadline:
                    try:
                        with socket.create_connection(("127.0.0.1", port), timeout=.2):
                            break
                    except OSError:
                        self.assertIsNone(child.poll())
                        time.sleep(.05)
                opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
                for host, paths in {"root.test": {"/":200,"/assets/site.css":200,"/.env":404,"/.env.production":404,"/.git/HEAD":404,
                                                  "/config/private.php":404,"/storage/logs/test.log":404,"/private.yaml":404},
                                    "public.test": {"/":200,"/.env":404,"/.git/HEAD":404,"/storage/visible.txt":200}}.items():
                    for path, status in paths.items():
                        req = urllib.request.Request(f"http://127.0.0.1:{port}{path}", headers={"Host":host})
                        with self.subTest(host=host, path=path):
                            if status == 200:
                                with opener.open(req, timeout=3) as response:
                                    self.assertEqual(response.read(), b"public fixture")
                            else:
                                with self.assertRaises(urllib.error.HTTPError) as refused:
                                    opener.open(req, timeout=3)
                                with refused.exception as response:
                                    self.assertEqual(response.code, status)
                                    self.assertNotIn(b"private fixture contents", response.read())
            finally:
                child.terminate()

    def test_detached_checkout_accepts_only_the_admitted_branch_first_parent_chain(self):
        source = self.r.root / "source"
        checkout = self.r.root / "checkout"
        for directory in (source, checkout):
            directory.mkdir()
            subprocess.run(["git", "-C", directory, "init", "-q", "-b", "main"], check=True)
        git = ["git", "-C", source, "-c", "user.name=Fixture", "-c", "user.email=fixture@example.test"]
        def commit(message):
            subprocess.run([*git, "commit", "-q", "--allow-empty", "-m", message], check=True)
            return subprocess.check_output([*git, "rev-parse", "HEAD"], text=True).strip()
        ancestor = commit("base")
        subprocess.run([*git, "switch", "-qc", "side"], check=True)
        side = commit("side")
        subprocess.run([*git, "switch", "-q", "main"], check=True)
        commit("main")
        subprocess.run([*git, "merge", "--no-ff", "-qm", "merge side", "side"], check=True)
        tip = subprocess.check_output([*git, "rev-parse", "HEAD"], text=True).strip()
        commands = ["git", "-C", checkout]
        for sha in (ancestor, tip):
            guest.select_commit(commands, str(source), "main", sha, os.environ)
            self.assertEqual(subprocess.check_output([*commands, "rev-parse", "HEAD"], text=True).strip(), sha)
            self.assertEqual(subprocess.run([*commands, "symbolic-ref", "-q", "HEAD"], check=False).returncode, 1)
        with self.assertRaises(guest.CommitOutsideBranch):
            guest.select_commit(commands, str(source), "main", side, os.environ)
        with self.assertRaises(subprocess.CalledProcessError):
            guest.select_commit(commands, str(source), "invalid:ref", tip, os.environ)

    def test_first_move_accepts_writes_on_one_backing_across_an_interrupted_commit(self):
        self.r.state.update(legacy={"project_id":7, "commit": NEXT}, previous=None, previous_old=None, previous_work=[],
                            address="10.0.0.8", verify_token="f"*64)
        legacy_data = ["write-before-fence"]
        candidate = []
        def transfer():
            self.assertTrue(self.marker.exists())
            candidate[:] = legacy_data
            self.r.link(NEXT)
        self.marker = phase.BASE / "legacy-schedules/7/adopted"
        events = []
        def fence(enabled):
            if not enabled:
                self.assertTrue(self.r.state["migration_committed"])
                candidate.append("write-after-opening")
            events.append(enabled)
        with patch.object(self.r, "legacy_commit", return_value=NEXT), \
                patch.object(self.r, "fence", side_effect=fence), patch.object(self.r, "check_legacy_quiet"), \
                patch.object(self.r, "stop_work"), patch.object(self.r, "route_switch", side_effect=transfer) as copied, \
                patch.object(self.r, "probe"), patch.object(self.r, "identity"), patch.object(self.r, "primitive"), \
                patch.object(self.r, "enable_candidate", side_effect=[OSError("interruption"), None]):
            with self.assertRaises(OSError):
                self.r.move_legacy()
            self.assertEqual(events, [True])
            self.assertTrue(phase.read(self.r.operation_file)["migration_committed"])
            self.r.state = phase.read(self.r.operation_file)
            with patch.object(self.r, "public_ready") as verified:
                self.r.reverse()
                verified.assert_called_once()
            copied.assert_called_once()
            self.assertEqual(events, [True, False])
            self.assertEqual(candidate, ["write-before-fence", "write-after-opening"])
            self.assertEqual(self.marker.read_text(), "21\n")

    def test_changed_physical_legacy_head_refuses_before_source_copy_and_before_commit(self):
        self.r.state.update(legacy={"project_id":7,"commit":NEXT}, previous=None, previous_work=[], address="10.0.0.8",
                            verify_token="f"*64)
        for heads, copied in [([OLD], False), ([NEXT, OLD], True)]:
            with self.subTest(copied=copied), patch.object(self.r, "legacy_commit", side_effect=heads), \
                    patch.object(self.r, "fence"), patch.object(self.r, "stop_work"), patch.object(self.r, "check_legacy_quiet"), \
                    patch.object(self.r, "route_switch") as transfer, patch.object(self.r, "check_selection"), \
                    patch.object(self.r, "probe"), patch.object(self.r, "enable_candidate") as enable, \
                    self.assertRaisesRegex(phase.Refusal, "physical legacy checkout"):
                try:
                    self.r.move_legacy()
                finally:
                    self.assertEqual(transfer.called, copied)
                    self.assertFalse(self.r.state.get("migration_committed"))
                    enable.assert_not_called()

    def test_unverified_previous_route_keeps_its_recovery_journal(self):
        self.r.state.update(switch_started=True, previous_work=[], saved_upstream=self.upstream.read_text())
        phase.save(self.r.private / "inventory.json", {"live":CURRENT, "old":OLD})
        phase.save(self.r.record(CURRENT), {"routes": [], "health_path":"/up"})
        self.r.state["health_path"] = "/up"
        with patch.object(self.r, "identity"), patch.object(self.r, "stop_work"), patch.object(self.r, "guest"), \
                patch.object(self.r, "incus"), patch.object(self.r, "primitive"), patch.object(self.r, "restore_work"), \
                patch.object(self.r, "route_switch"), patch.object(self.r, "readback"), \
                patch.object(self.r, "probe", side_effect=phase.Refusal("unhealthy")), self.assertRaisesRegex(phase.Refusal, "unhealthy"):
            self.r.reverse()
        journal = phase.read(self.r.operation_file)
        self.assertTrue(journal["reversing"])
        self.assertFalse(journal.get("reversed"))

    def test_swap_and_pool_reservations_cover_other_active_instances(self):
        self.r.ctl["instance"] = MagicMock(return_value=None)
        self.r.ctl["memory_use"] = MagicMock(return_value=(10, 10, 0))
        self.r.ctl["capacity_estimate"] = MagicMock(return_value={
            "available_bytes": {"ram": 3 * 1024**3, "swap": 100, "pool": 10 * 1024**3 + 100},
            "required_bytes": {"ram": 2 * 1024**3, "swap": 60, "pool": 10 * 1024**3},
        })
        with patch.object(self.r, "identity"):
            self.r.reserve(self.plan)
            other = phase.Release(22, OLD, 301)
            other.private.mkdir(parents=True)
            other.inventory = self.r.inventory
            other.ctl = self.r.ctl
            with patch.object(other, "identity"), self.assertRaisesRegex(phase.Refusal, "swap"):
                other.reserve(self.plan)
            self.r.free_capacity()
            with patch.object(other, "identity"):
                other.reserve(self.plan)

    def test_untrusted_checkout_paths_cannot_traverse_the_persistent_volume(self):
        with tempfile.TemporaryDirectory() as root:
            root = Path(root)
            (root / "linked").symlink_to(root.parent)
            with self.assertRaisesRegex(ValueError, "symlink"):
                guest.beneath(root, guest.relative("linked/data"))
            for path in ("../data", "/data", "foo/../bar", ".git/config"):
                with self.subTest(path=path), self.assertRaises(ValueError):
                    guest.relative(path)

    def test_seal_accounts_for_file_content_and_executability_but_excludes_external_storage(self):
        app = self.r.root / "current"
        app.mkdir()
        source = app / "artisan"
        source.write_text("one")
        store = self.r.private / "upload"
        store.write_text("old upload")
        (app / "upload").symlink_to(store)
        with patch.object(guest, "WORK", self.r.root):
            before = guest.content_digest()
            store.write_text("new upload")
            self.assertEqual(guest.content_digest(), before)
            source.chmod(0o755)
            changed = guest.content_digest()
            self.assertNotEqual(changed, before)
            source.write_text("two")
            self.assertNotEqual(guest.content_digest(), changed)


class LiveEnvironment(unittest.TestCase):
    def test_sync_checks_actual_keys_and_never_overwrites_tracked_testing_environment(self):
        import runpy
        import subprocess

        worker = runpy.run_path(str(Path(__file__).parents[1] / "ansible/files/instance-control-environment.py"))
        apply = worker["apply"]
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory).resolve()
            subprocess.run(["git", "init", "-q", root], check=True)
            target = root / ".env"
            target.write_text("OLD=value\nFOREIGN=keep\n")
            testing = root / ".env.testing"
            testing.write_text("tracked\n")
            subprocess.run(["git", "-C", root, "add", "-f", ".env.testing"], check=True)
            plan = {"base": str(root), "routes": [], "force": False, "managed_keys": ["OLD", "NEW"],
                    "content": "NEW=after\n", "testing": "changed", "laravel": False}
            with self.assertRaisesRegex(ValueError, "would_drop_keys"):
                apply(plan, root)
            self.assertIn("FOREIGN", target.read_text())
            plan["force"] = True
            self.assertEqual(apply(plan, root), {"environment_changed": True, "testing_status": "skipped_tracked"})
            self.assertEqual(testing.read_text(), "tracked\n")
            self.assertEqual(target.read_text(), "NEW=after\n")
            self.assertEqual(target.stat().st_mode & 0o777, 0o600)
            self.assertFalse(apply(plan, root)["environment_changed"])
            self.assertEqual(worker["read_environment"](root, root), b"NEW=after\n")
            target.unlink()
            target.symlink_to(testing)
            with self.assertRaises(OSError):
                worker["read_environment"](root, root)
            with self.assertRaises(ValueError):
                worker["read_environment"](root.parent, root)


if __name__ == "__main__":
    unittest.main()
