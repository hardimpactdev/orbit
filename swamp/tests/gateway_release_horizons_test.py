"""A release consumes the Process edits that arrived after it was admitted."""
import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location("horizons", Path(__file__).parents[1] / "scripts/gateway-release-horizons.py")
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class HorizonSelection(unittest.TestCase):
    def test_new_managed_process_suppresses_the_admitted_fallback(self):
        unit = "orbit-process-20-horizon.service"
        self.assertEqual(module.select(module.FALLBACK, [], {unit: {"desired": "running"}}), [unit])
        self.assertEqual(module.select(module.FALLBACK, [], {unit: {"desired": "stopped"}}), [])

    def test_removal_returns_to_fallback_unless_a_replacement_exists(self):
        old = "orbit-process-10-horizon.service"
        new = "orbit-process-11-horizon.service"
        records = {old: {"removed": True}}
        self.assertEqual(module.select(old, [old], records), [module.FALLBACK])
        records[new] = {"desired": "running"}
        self.assertEqual(module.select(old, [old], records), [new])

    def test_inactive_host_fallback_stays_inactive_at_first_move(self):
        self.assertEqual(module.select("", [], {}), [])
