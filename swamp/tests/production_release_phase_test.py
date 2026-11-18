"""Production releases isolate Instance state, restore failed Caddy reloads and retain recoverable builds."""
import importlib.util
import json
import os
import subprocess
import sys
import tempfile
import unittest
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
        step = {"command": "echo migrated", "timeout_seconds": 15}
        with patch.object(self.r, "user", return_value=CompletedProcess([], 0)) as run:
            self.r.steps(self.r.prep, [step], "before")
            self.r.steps(self.r.prep, [step], "before")
            run.assert_called_once()
            self.r.persist(executing="after-0")
            with self.assertRaisesRegex(phase.Refusal, "interrupted"):
                self.r.steps(self.r.name, [step], "after")
            run.assert_called_once()

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


if __name__ == "__main__":
    unittest.main()
