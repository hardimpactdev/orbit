import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location("liveness", Path(__file__).parents[1] / "bin/swamp-liveness.py")
liveness = importlib.util.module_from_spec(spec)
spec.loader.exec_module(liveness)


class LivenessTest(unittest.TestCase):
    def test_malformed_probe_responses_are_refused(self):
        for value in [None, [], {}, {"data": []}, {"data": {"kinds": [None]}},
                      {"data": {"kinds": [{"kind": "test.kind", "claimable": 1, "oldest_at": None}]}},
                      {"data": {"kinds": [{"kind": "test.kind", "claimable": True}]}}]:
            with self.subTest(value=value), self.assertRaises(ValueError):
                liveness.claimable_heads(value)
        for value in [None, [], {}, {"status": "starting"}]:
            self.assertFalse(liveness.health_available(value))
        self.assertTrue(liveness.health_available({"status": "ok"}))

    def test_duplicate_summary_kinds_are_refused(self):
        entry = {"kind": "test.kind", "claimable": 0, "oldest_at": None}
        with self.assertRaises(ValueError):
            liveness.claimable_heads({"data": {"kinds": [entry, entry]}})

    def test_partial_or_invalid_state_is_preserved_and_rebaselines_without_restart(self):
        with tempfile.TemporaryDirectory() as temp:
            path = Path(temp) / 'state.json'
            for malformed in ['{"last_restart":', '{"last_restart":"oops"}', '{"last_restart":NaN}', '{"claimable_heads":[]}']:
                path.write_text(malformed)
                state = liveness.read_state(path, 2000)
                self.assertEqual(state, {"last_restart": 2000})
                self.assertFalse(path.exists())
            self.assertEqual(len(list(Path(temp).glob('state-invalid-*'))), 4)

    def test_failed_state_publication_keeps_previous_cooldown(self):
        with tempfile.TemporaryDirectory() as temp:
            path = Path(temp) / 'state.json'
            liveness.write_state(path, {"last_restart": 1000})
            with patch.object(liveness.os, 'replace', side_effect=OSError('ENOSPC')):
                with self.assertRaises(OSError):
                    liveness.write_state(path, {"last_restart": 2000})
            self.assertEqual(json.loads(path.read_text()), {"last_restart": 1000})
            self.assertEqual(list(Path(temp).iterdir()), [path])

    def evaluate(self, previous=None, **changes):
        values = dict(now=2000, healthy=True, heads={"fleet.peer_dns": 1000}, free_inodes=200, total_inodes=1000, serve_age=1000)
        values.update(changes)
        return liveness.evaluate(previous or {}, **values)

    def test_old_but_draining_backlog_does_not_restart(self):
        _, state = self.evaluate()
        result, _ = self.evaluate(state, now=2200, heads={"fleet.peer_dns": 1100})
        self.assertEqual(result["action"], "healthy")

    def test_three_minutes_without_claim_progress_restarts(self):
        _, state = self.evaluate()
        self.assertEqual(self.evaluate(state, now=2180)[0]["action"], "restart")

    def test_transient_health_failure_is_not_a_restart(self):
        _, state = self.evaluate(healthy=False)
        self.assertEqual(self.evaluate(state, healthy=False, now=2179)[0]["action"], "healthy")
        self.assertEqual(self.evaluate(state, healthy=False, now=2180)[0]["action"], "restart")

    def test_inode_exhaustion_alerts_and_does_not_restart(self):
        _, state = self.evaluate()
        result, _ = self.evaluate(state, now=2180, free_inodes=0)
        self.assertEqual(result["action"], "inode_exhaustion")
        self.assertEqual(result["inode_alert"], "critical")

    def test_inode_warning_is_visible_before_exhaustion(self):
        self.assertEqual(self.evaluate(free_inodes=80)[0]["inode_alert"], "warning")

    def test_unavailable_gateway_does_not_prove_a_stall(self):
        result, state = self.evaluate({"unchanged_since": 1, "claimable_heads": {"fleet.peer_dns": 1000}}, heads=None)
        self.assertEqual(result["action"], "healthy")
        self.assertNotIn("unchanged_since", state)

    def test_startup_and_restart_cooldown(self):
        _, state = self.evaluate()
        self.assertEqual(self.evaluate(state, now=2180, serve_age=30)[0]["action"], "startup_grace")
        state["last_restart"] = 2100
        self.assertEqual(self.evaluate(state, now=2180)[0]["action"], "restart_cooldown")
