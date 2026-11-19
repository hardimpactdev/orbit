"""Production releases isolate Instance state, restore failed Caddy reloads and retain recoverable builds."""
import hashlib
import importlib.util
import json
import os
import re
import shutil
import socket
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.request
from contextlib import ExitStack, nullcontext
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


def guest_answers(sockets):
    """A guest whose web server stops when asked and whose `ss` prints the given lines."""
    def answer(machine, *args, **kwargs):
        if args[:1] == ("ss",):
            return sockets if isinstance(sockets, CompletedProcess) else CompletedProcess([], 0, stdout=sockets)
        if "is-active" in args:
            return CompletedProcess([], 3, stdout="")
        return CompletedProcess([], 0, stdout="")
    return answer


class ProductionReleaseTest(unittest.TestCase):
    def setUp(self):
        self.stack = ExitStack()
        self.addCleanup(self.stack.close)
        root = Path(self.stack.enter_context(tempfile.TemporaryDirectory()))
        config = root / "host.json"
        config.write_text(json.dumps({"node_id": 25, "pool": "selected"}))
        (root / "orbit_route_withdraw.py").symlink_to(SWAMP / "ansible/module_utils/orbit_route_withdraw.py")
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

    def test_only_the_owned_host_resource_can_take_a_checked_shared_or_exclusive_lock(self):
        if not shutil.which("flock"):
            self.skipTest("requires util-linux flock")
        import fcntl
        with self.assertRaisesRegex(phase.Refusal, "unrecognized production resource"), phase.lock(self.r.root / "foreign"):
            pass
        path = self.r.private / "lock"
        with self.assertRaisesRegex(phase.Refusal, "shared access"), phase.lock(path, shared=True):
            pass
        with phase.legacy_schedule_lock(17, shared=True) as marker, (marker.parent / "lock").open("rb") as peer:
            fcntl.flock(peer, fcntl.LOCK_SH | fcntl.LOCK_NB)
            with self.assertRaises(BlockingIOError):
                fcntl.flock(peer, fcntl.LOCK_EX | fcntl.LOCK_NB)
        with phase.lock(path), path.open("rb") as peer, self.assertRaises(BlockingIOError):
            fcntl.flock(peer, fcntl.LOCK_SH | fcntl.LOCK_NB)
        path.unlink()
        path.symlink_to(self.r.operation_file)
        with self.assertRaisesRegex(phase.Refusal, "cannot acquire"), phase.lock(path):
            pass

    def test_distributed_role_contains_the_guest_resolvers_entire_local_import_tree(self):
        files = (SWAMP / "manifest.yaml").read_text().split("additionalFiles:", 1)[1].splitlines()
        shipped = {line.strip()[2:] for line in files if line.startswith("  - ")}
        pending = [SWAMP / "ansible/files/instance-release-vp.ts"]
        seen = set()
        while pending:
            module = pending.pop()
            if module in seen:
                continue
            seen.add(module)
            self.assertIn(str(module.relative_to(SWAMP)), shipped)
            imports = re.findall(r'from\s+["\']([.][^"\']+)["\']', module.read_text())
            pending.extend((module.parent / specifier).resolve() for specifier in imports)
        self.assertEqual(len(seen), 3)

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

    def test_resume_measures_the_git_layout_of_the_prepared_checkout(self):
        encoded = json.dumps(self.plan, sort_keys=True, separators=(",", ":")).encode()
        self.r.state.update(checkout_ready=True, plan_digest=hashlib.sha256(encoded).hexdigest(), step_outcomes=[])
        for receipt in (None, {"scope": "current", "inputs": "inputs"}):
            with self.subTest(receipt=receipt):
                self.r.state.pop("built", None)
                paths = [phase.WORK + "/"]
                if receipt:
                    self.r.state["dependency_receipt"] = receipt
                    paths.append("/var/lib/orbit-release-git/")
                with (patch.object(self.r, "reserve"), patch.object(self.r, "converge_checkout") as checkout,
                      patch.object(self.r, "guest_action", return_value="sha256:" + "f" * 64),
                      patch.object(self.r, "user", return_value=CompletedProcess([], 0, "517 total")) as measure):
                    self.r.build(self.plan)
                checkout.assert_not_called()
                measure.assert_called_once_with(self.r.prep, "du", "-scxb", "--", *paths)
                self.assertEqual(self.r.state["build_bytes"], 517)
                self.assertTrue(self.r.state["built"])

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

        native_run = phase.run
        def command(args, **kwargs):
            if args[0] == 'flock':
                return native_run(args, **kwargs)
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

    def test_the_earlier_rollback_survives_a_failed_stop_of_the_previous_live(self):
        self.r.link(NEXT)
        self.r.state["verified"] = True
        with patch.object(self.r, "guest"), patch.object(self.r, "identity"), patch.object(self.r, "response_gate"), \
                patch.object(self.r, "primitive", side_effect=ValueError("release.container_not_stopped")), patch.object(self.r, "discard") as discard:
            with self.assertRaisesRegex(ValueError, "not_stopped"):
                self.r.retire()
            discard.assert_not_called()

    def test_a_retire_retry_records_an_already_stopped_former_live_as_parked(self):
        self.r.link(NEXT)
        self.r.state["verified"] = True
        stopped = {"status": "Stopped", "config": {"boot.autostart": "false"}}
        with patch.object(self.r, "guest") as guest, patch.object(self.r, "identity", return_value=stopped), \
                patch.object(self.r, "primitive") as primitive, patch.object(self.r, "discard") as discard, \
                patch.object(self.r, "route_files"), patch.object(self.r, "free_capacity"):
            self.r.retire()
        guest.assert_not_called()
        primitive.assert_not_called()
        discard.assert_called_once_with(OLD)
        self.assertTrue(self.r.state["parked_previous"])

    def test_quiesce_stops_the_previous_web_server_once_and_retire_then_only_stops_the_container(self):
        self.r.link(NEXT)
        self.r.state["verified"] = True
        running = {"status": "Running", "config": {"boot.autostart": "false"}}
        with patch.object(self.r, "guest", side_effect=guest_answers("")) as guest, patch.object(self.r, "identity", return_value=running), \
                patch("builtins.print") as printed:
            self.r.quiesce()
            self.r.quiesce()
        self.assertEqual(guest.call_args_list[0].args, (self.r.container(CURRENT), "systemctl", "disable", "--now", "orbit-frankenphp.service"))
        self.assertEqual(len(guest.call_args_list), 2)
        printed.assert_called_with(self.r.container(CURRENT))
        self.assertTrue(self.r.state["quiesced_previous"])
        with patch.object(self.r, "identity", return_value=running), \
                patch.object(self.r, "primitive") as primitive, patch.object(self.r, "discard"), \
                patch.object(self.r, "route_files"), patch.object(self.r, "free_capacity"), patch.object(self.r, "drain") as drain:
            self.r.retire()
        drain.assert_called_once_with(self.r.container(CURRENT))
        primitive.assert_called_once_with("retire", CURRENT[:12], self.r.operation)

    def test_recovery_retire_waits_for_queued_response_bytes_up_to_the_bound(self):
        self.r.link(NEXT)
        self.r.state.update(verified=True, quiesced_previous=True)
        running = {"status": "Running", "config": {"boot.autostart": "false"}}
        waits = self.r.root / "waits"
        busy = guest_answers("LISTEN 0 4096 a:8080 *\nLAST-ACK 0 2048 a:8080 b:1\nTIME-WAIT 0 9 a:8080 b:2\n")
        idle = guest_answers("LISTEN 0 4096 a:8080 *\nTIME-WAIT 0 9 a:8080 b:2\n")
        with patch.object(phase, "RESPONSE_WAITS", waits), patch.object(self.r, "identity", return_value=running), \
                patch.object(self.r, "discard"), patch.object(self.r, "route_files"), patch.object(self.r, "free_capacity"), \
                patch.object(self.r, "primitive") as primitive:
            with patch.object(self.r, "guest", side_effect=busy), self.assertRaisesRegex(phase.Refusal, "queued response bytes"):
                self.r.retire()
            primitive.assert_not_called()
            # A retry after the bytes drained records the outcome and stops the container.
            with patch.object(self.r, "guest", side_effect=idle):
                self.r.retire()
            primitive.assert_called_once_with("retire", CURRENT[:12], self.r.operation)
        self.assertEqual((waits / self.r.container(CURRENT)).read_text(), "300 drained\n")

    def test_recovery_retire_stops_after_the_bound_with_bytes_still_queued(self):
        self.r.link(NEXT)
        self.r.state.update(verified=True, quiesced_previous=True)
        running = {"status": "Running", "config": {"boot.autostart": "false"}}
        waits = self.r.root / "waits"
        waits.mkdir()
        # The workflow's wait recorded its first probe long ago; recovery keeps that start instead of a fresh one.
        (waits / self.r.container(CURRENT)).write_text("300 waiting 1\n")
        busy = guest_answers("FIN-WAIT-1 0 2048 a:8080 b:1\n")
        with patch.object(phase, "RESPONSE_WAITS", waits), patch.object(self.r, "identity", return_value=running), \
                patch.object(self.r, "discard"), patch.object(self.r, "route_files"), patch.object(self.r, "free_capacity"), \
                patch.object(self.r, "primitive") as primitive, patch.object(self.r, "guest", side_effect=busy):
            self.r.retire()
        primitive.assert_called_once()
        self.assertEqual((waits / self.r.container(CURRENT)).read_text(), "300 timeout\n")

    def test_a_failed_probe_with_a_running_container_stays_busy(self):
        self.r.link(NEXT)
        self.r.state.update(verified=True, quiesced_previous=True)
        waits = self.r.root / "waits"
        failed = CompletedProcess([], 1, stdout="", stderr="exec: ss: not found")
        self.r.ctl["instance"] = lambda name, **kwargs: {"name": name, "status": "Running"}
        with patch.object(phase, "RESPONSE_WAITS", waits), patch.object(self.r, "guest", return_value=failed), \
                self.assertRaisesRegex(phase.Refusal, "queued response bytes"):
            self.r.response_gate(self.r.container(CURRENT))
        # The start was saved before the probe and the lookup.
        self.assertTrue((waits / self.r.container(CURRENT)).read_text().startswith("300 waiting "))

        def lookup_fails(name, **kwargs):
            raise subprocess.TimeoutExpired(["incus", "list"], 15)
        self.r.ctl["instance"] = lookup_fails
        with patch.object(phase, "RESPONSE_WAITS", waits), patch.object(self.r, "guest", return_value=failed), \
                self.assertRaisesRegex(phase.Refusal, "queued response bytes"):
            self.r.response_gate(self.r.container(CURRENT))
        self.r.ctl["instance"] = lambda name, **kwargs: {"name": name, "status": "Stopped"}
        with patch.object(phase, "RESPONSE_WAITS", waits), patch.object(self.r, "guest", return_value=failed):
            self.r.response_gate(self.r.container(CURRENT))
        self.assertEqual((waits / self.r.container(CURRENT)).read_text(), "300 drained\n")

    def test_a_reversed_candidate_that_may_have_served_drains_before_it_is_removed(self):
        self.r.state.update(action="deploy", candidate_attempted=True, switch_started=True, artifact="sha256:" + "f" * 64)
        self.r.ctl["instance"] = lambda name, **kwargs: {"name": name, "status": "Running"}
        order = []
        with patch.object(self.r, "reverse"), patch.object(self.r, "check_selection"), patch.object(self.r, "park"), \
                patch.object(self.r, "free_capacity"), patch.object(self.r, "guest", side_effect=guest_answers("")), \
                patch.object(self.r, "response_gate", side_effect=lambda name: order.append("gate " + name)), \
                patch.object(self.r, "discard", side_effect=lambda sha, owned_build: order.append("discard")), \
                patch.object(self.r, "incus", return_value=CompletedProcess([], 0, stdout="[]")):
            self.r.release_unused()
        self.assertEqual(order, ["gate " + self.r.name, "discard"])

    def test_a_rollback_target_that_served_drains_before_it_stops_again(self):
        self.r.state.update(action="rollback", rollback_awake=True, sha=OLD)
        self.r.sha = OLD
        running = {"status": "Running", "config": {"boot.autostart": "false"}}
        order = []
        with patch.object(self.r, "reverse"), patch.object(self.r, "check_selection"), patch.object(self.r, "park"), \
                patch.object(self.r, "free_capacity"), patch.object(self.r, "identity", return_value=running), \
                patch.object(self.r, "drain", side_effect=lambda name: order.append("drain " + name)), \
                patch.object(self.r, "primitive", side_effect=lambda *args: order.append(args[0])):
            self.r.release_unused()
        self.assertEqual(order, ["drain " + self.r.name, "retire"])

    def test_the_web_server_must_stop_before_the_response_gate(self):
        def still_running(machine, *args, **kwargs):
            return CompletedProcess([], 0, stdout="")
        with patch.object(self.r, "guest", side_effect=still_running), patch.object(self.r, "response_gate") as gate, \
                self.assertRaisesRegex(phase.Refusal, "still running"):
            self.r.drain(self.r.name)
        gate.assert_not_called()

    def test_rollback_cleanup_leaves_an_already_parked_old_alone(self):
        self.r.state.update(action="rollback", rollback_awake=True, sha=OLD)
        self.r.sha = OLD
        stopped = {"status": "Stopped", "config": {"boot.autostart": "false"}}
        with patch.object(self.r, "reverse"), patch.object(self.r, "check_selection"), patch.object(self.r, "park"), \
                patch.object(self.r, "free_capacity"), patch.object(self.r, "identity", return_value=stopped), \
                patch.object(self.r, "guest") as guest, patch.object(self.r, "primitive") as primitive:
            self.r.release_unused()
        guest.assert_not_called()
        primitive.assert_not_called()

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
            self.assertEqual(subprocess.check_output([*commands, "rev-parse", "refs/orbit/admitted"], text=True).strip(), tip)
        with self.assertRaises(guest.CommitOutsideBranch):
            guest.select_commit(commands, str(source), "main", side, os.environ)
        with self.assertRaises(subprocess.CalledProcessError):
            guest.select_commit(commands, str(source), "invalid:ref", tip, os.environ)

    def test_preparation_fetches_incrementally_and_converges_one_worktree(self):
        source = self.r.root / "upstream"
        cache = self.r.root / "private-git"
        store = self.r.root / "shared-files"
        source.mkdir()
        cache.mkdir()
        store.mkdir()
        git = ["git", "-C", source, "-c", "user.name=fixture", "-c", "user.email=fixture@example.test"]
        subprocess.run([*git, "init", "-qb", "main"], check=True)
        (source / "unchanged").write_text("keep this inode")
        (source / "removed").write_text("removed in next commit")
        (source / "composer.lock").write_text("lock v1")
        (source / "composer.json").write_text("{}")
        (source / "package.json").write_text("{}")
        (source / "package-lock.json").write_text("{}")
        (source / ".node-version").write_text("22.14.0")
        (source / "packages/a").mkdir(parents=True)
        (source / "packages/a/package.json").write_text("{}")
        (source / "uploads").mkdir()
        (source / "uploads/seed").write_text("seed")
        (source / ".gitignore").write_text("vendor/\n.env\npublic/build/\n")
        def commit():
            subprocess.run([*git, "add", "-A"], check=True)
            subprocess.run([*git, "commit", "-qm", "revision"], check=True)
            return subprocess.check_output([*git, "rev-parse", "HEAD"], text=True).strip()
        plan = {"repository": "https://github.com/example/app", "branch": "main", "sha": commit(),
                "environment": "APP_ENV=test\n", "application_suffix": "", "routes": [], "shared_paths": ["uploads"],
                "scope": "admitted build context"}
        tree = self.r.root / "current"
        env = {**guest.git_environment(), "GIT_ALLOW_PROTOCOL": "file"}
        with patch.object(guest, "WORK", self.r.root), patch.object(guest, "SHARED", store), \
                patch.object(guest, "SOURCE", cache), patch.object(guest, "repository", return_value=str(source)), \
                patch.object(guest, "git_environment", return_value=env):
            guest.fetch(plan)
            inputs = guest.checkout(plan)
            (tree / "vendor").mkdir()
            installed = tree / "vendor/installed"
            installed.write_text("installed dependencies")
            (tree / "public/build").mkdir(parents=True)
            (tree / "public/build/stale.js").write_text("generated output")
            plan["prepared"] = {"scope": plan["scope"], "artifact": guest.content_digest(), "inputs": inputs}
            inodes = [p.stat().st_ino for p in (tree, tree / "unchanged", installed)]
            old_objects = {p:p.stat().st_ino for p in (cache / "repository/objects").glob("[0-9a-f][0-9a-f]/*")}
            self.assertTrue(old_objects)
            (store / "uploads/seed").write_text("new live upload")
            # Earlier application-owned Git config must not participate in the next credentialed fetch or checkout.
            poison = self.r.root / "credential-or-hook-was-used"
            subprocess.run(["git", "-C", tree, "config", "core.fsmonitor", f"touch {poison}"], check=True)
            (source / "removed").unlink()
            (source / "new").write_text("next revision")
            plan["sha"] = commit()
            guest.fetch(plan)
            self.assertEqual(guest.checkout(plan), inputs)
            self.assertEqual([p.stat().st_ino for p in (tree, tree / "unchanged", installed)], inodes)
            self.assertTrue(all(p.stat().st_ino == inode for p, inode in old_objects.items()))
            self.assertEqual((tree / "uploads/seed").read_text(), "new live upload")
            self.assertFalse((tree / "removed").exists())
            self.assertFalse((tree / "public/build/stale.js").exists())
            self.assertFalse(poison.exists())
            self.assertEqual(subprocess.check_output(["git", "-C", tree, "rev-parse", "HEAD"], text=True).strip(), plan["sha"])
            self.assertEqual(subprocess.run(["git", "-C", tree, "symbolic-ref", "-q", "HEAD"], check=False).returncode, 1)
            plan["prepared"]["artifact"] = guest.content_digest()
            (source / "composer.lock").write_text("lock v2")
            plan["sha"] = commit()
            guest.fetch(plan)
            next_inputs = guest.checkout(plan)
            self.assertNotEqual(inputs, next_inputs)
            self.assertFalse(installed.exists())
            (tree / "vendor").mkdir()
            installed.write_text("partial interrupted install")
            plan["prepared"] = {}
            guest.checkout(plan)
            self.assertFalse(installed.exists())

            # Native modules at both the root and workspace are reusable only under the original Node pin.
            modules = (tree / "node_modules", tree / "packages/a/node_modules")
            for directory in modules:
                directory.mkdir()
                (directory / "native-addon").write_text("node-22")
            plan["prepared"] = {"scope": plan["scope"], "artifact": guest.content_digest(), "inputs": next_inputs}
            self.assertEqual(guest.checkout(plan), next_inputs)
            self.assertTrue(all((directory / "native-addon").is_file() for directory in modules))
            plan["prepared"]["artifact"] = guest.content_digest()
            (source / ".node-version").write_text("24.0.0")
            plan["sha"] = commit()
            guest.fetch(plan)
            self.assertNotEqual(guest.checkout(plan), next_inputs)
            self.assertTrue(all(not directory.exists() for directory in modules))

            # Change branch, then rewrite its history. Neither path may ship a former commit's objects.
            previous = plan["sha"]
            subprocess.run([*git, "switch", "--orphan", "other"], check=True, capture_output=True)
            (source / "other").write_text("other history")
            plan.update(branch="other", sha=commit())
            guest.fetch(plan)
            bare = ["git", "--git-dir", str(cache / "repository")]
            self.assertNotEqual(subprocess.run([*bare, "cat-file", "-e", previous], check=False).returncode, 0)
            previous = plan["sha"]
            subprocess.run([*git, "switch", "--orphan", "rewrite"], check=True, capture_output=True)
            (source / "replacement").write_text("rewritten history")
            plan["sha"] = commit()
            subprocess.run([*git, "branch", "-f", "other", plan["sha"]], check=True)
            guest.fetch(plan)
            self.assertNotEqual(subprocess.run([*bare, "cat-file", "-e", previous], check=False).returncode, 0)

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

    def first_move(self, prep=None):
        self.r.ctl["instance"] = MagicMock(return_value=prep)
        self.r.ctl["owned"] = MagicMock()
        self.r.ctl["memory_use"] = MagicMock(return_value=(10, 10, 0))
        self.r.ctl["capacity_estimate"] = MagicMock(return_value={
            "available_bytes": {"ram": 3 * 1024**3, "swap": 0, "pool": 2000}, "required_bytes": {"ram": 2 * 1024**3, "swap": 0, "pool": 1500}})
        self.r.inventory = {"live": None, "old": None}
        home = "/home/orbit-app-2"
        sizes = {home + "/current/": 700, home + "/storage": 300, home + "/database.sqlite": 40, home + "/database.sqlite-wal": 10}
        du = lambda argv, **kwargs: CompletedProcess(argv, 0, stdout=f"{sum(sizes[path] for path in argv[argv.index('--', 4) + 1:])}\ttotal\n"
                                                     if "du" in argv else "")
        self.stack.enter_context(patch.object(phase, "run", side_effect=du))
        self.stack.enter_context(patch("os.path.isdir", return_value=True))
        self.stack.enter_context(patch("os.path.isfile", return_value=True))
        return {**self.plan, "legacy": {"project_id": 2, "home": home, "units": [], "commit": NEXT}}

    def test_the_first_move_reserves_the_checkout_and_the_storage_and_sqlite_copy(self):
        plan = self.first_move()
        self.r.reserve(plan)
        self.assertEqual(self.r.ctl["capacity_estimate"].call_args.kwargs["release"], 1050)
        self.assertEqual(phase.read(phase.BASE / ".capacity" / "instance-21.json")["pool"], 1050)

    def test_an_unbuilt_preparer_never_lowers_the_known_release_size(self):
        plan = self.first_move({"config": {}, "state": {"disk": {"root": {"usage": 100}}}})
        self.r.reserve(plan, measured=True)
        self.assertEqual(self.r.ctl["capacity_estimate"].call_args.kwargs["release"], 1050)
        self.r.state["build_bytes"] = 900
        self.r.reserve(plan, measured=True)
        self.assertEqual(self.r.ctl["capacity_estimate"].call_args.kwargs["release"], 1250)

    def test_the_copied_storage_leaves_the_reservation_at_once(self):
        plan = self.first_move()
        self.r.reserve(plan)
        self.r.copied_shared()
        self.assertEqual(phase.read(phase.BASE / ".capacity" / "instance-21.json")["pool"], 700)
        self.assertTrue(phase.read(self.r.operation_file)["shared_copied"])
        # A parallel deploy no longer subtracts the copy that the pool already counts.
        self.r.reserve(plan, measured=True)
        self.assertEqual(self.r.ctl["capacity_estimate"].call_args.kwargs["release"], 700)
        with patch.object(self.r, "route_files"), patch.object(self.r, "copied_shared") as copied, \
                patch.object(self.r, "reserve"), patch.object(self.r, "primitive"), patch.object(self.r, "sql"), \
                patch.object(self.r, "converge_checkout", side_effect=phase.Refusal("stop")), \
                patch.object(self.r, "legacy_commit", return_value=NEXT), patch.object(self.r, "check_selection"), \
                patch.object(phase, "legacy_schedule_lock", return_value=nullcontext(Path("/nonexistent"))), self.assertRaisesRegex(phase.Refusal, "stop"):
            self.r.state = {}
            self.r.inventory = {"live": None, "old": None}
            self.r.build({**plan, "expected_live": None})
        copied.assert_called_once_with()

    def test_a_node_without_swap_stops_preparation_instead_of_freezing_it(self):
        self.r.state["preparer_awake"] = True
        prep = {"config": {"user.orbit.release.operation": "300"}}
        for swap, action in ((True, "sleep"), (False, "rest")):
            with self.subTest(swap=swap):
                (self.r.private / "parked.json").unlink(missing_ok=True)
                self.r.ctl["instance"] = MagicMock(return_value=prep)
                self.r.ctl["has_swap"] = MagicMock(return_value=swap)
                with patch.object(self.r, "primitive") as primitive:
                    self.r.park()
                primitive.assert_called_once_with(action, 300)

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
