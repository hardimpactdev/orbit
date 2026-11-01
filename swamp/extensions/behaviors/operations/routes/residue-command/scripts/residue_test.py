"""F9 residue order and failure scope; certificate filesystem parity is tested separately."""

import importlib.util
import sys
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

sys.modules["canonical_certificate"] = SimpleNamespace(remove=lambda _: None)
spec = importlib.util.spec_from_file_location(
    "residue_phase", Path(__file__).with_name("residue.py")
)
phase = importlib.util.module_from_spec(spec)
spec.loader.exec_module(phase)


class ResiduePhase(unittest.TestCase):
    def setUp(self):
        self.calls = []
        self.failure = None
        self.data = {
            "rows": [
                {"route_id": 7, "steps": ["certificates", "firewall"]},
                {"route_id": 8, "steps": ["certificates", "firewall"]},
            ],
            "user": "orbit",
            "php": {
                "8.5": "group=__orbit_converge_group__\nhome=/__orbit_converge_home__"
            },
            "certificates": ["route-7", "route-7-router", "route-8"],
            "firewall": [
                {"operation": "remove", "route_id": 7},
                {"operation": "remove", "route_id": 8},
            ],
            "ingress": {"desired": [], "remove": [], "enable": False},
        }

        def record(name, value):
            self.calls.append((name, value))
            if name == self.failure:
                raise ValueError("route.cleanup_refused")
            return {"changed": True}

        patch.object(
            phase,
            "policy",
            side_effect=lambda name, value: record(
                name + str(value.get("route_id", "")), value
            ),
        ).start()
        patch.object(
            phase, "remove", side_effect=lambda value: record(value["scope"], value)
        ).start()
        patch.object(
            phase.pwd,
            "getpwnam",
            return_value=SimpleNamespace(pw_gid=20, pw_dir="/srv/orbit"),
        ).start()
        patch.object(
            phase.grp, "getgrgid", return_value=SimpleNamespace(gr_name="nodegroup")
        ).start()
        self.addCleanup(patch.stopall)

    def test_pools_then_exact_scopes_and_rules_per_row_then_ingress(self):
        self.assertEqual({"changed": True}, phase.clean(self.data))
        self.assertEqual(
            [
                "php",
                "route-7",
                "route-7-router",
                "firewall7",
                "route-8",
                "firewall8",
                "firewall",
            ],
            [x[0] for x in self.calls],
        )
        self.assertEqual(
            {"configurations": {"8.5": "group=nodegroup\nhome=/srv/orbit"}},
            self.calls[0][1],
        )
        self.assertEqual(
            {
                "scope": "route-7",
                "user": "orbit",
                "home": "/srv/orbit",
                "in_use": False,
            },
            self.calls[1][1],
        )

    def test_empty_final_php_set_is_converged(self):
        self.data.update(php={}, certificates=[], firewall=[], ingress=None)
        phase.clean(self.data)
        self.assertEqual([("php", {"configurations": {}})], self.calls)

    def test_empty_rows_have_no_effect(self):
        self.data["rows"] = []
        self.assertEqual({"changed": False}, phase.clean(self.data))
        self.assertEqual([], self.calls)

    def test_every_failure_stops_later_cleanup(self):
        for name in [
            "php",
            "route-7",
            "route-7-router",
            "firewall7",
            "route-8",
            "firewall8",
            "firewall",
        ]:
            with self.subTest(name=name):
                self.calls = []
                self.failure = name
                with self.assertRaisesRegex(ValueError, "route.cleanup_refused"):
                    phase.clean(self.data)
                self.assertEqual(name, self.calls[-1][0])

    def test_first_row_firewall_failure_preserves_second_row_certificates(self):
        self.failure = "firewall7"
        with self.assertRaises(ValueError):
            phase.clean(self.data)
        self.assertNotIn("route-8", [x[0] for x in self.calls])

    def test_scope_prefix_does_not_match_another_route_id(self):
        self.data["rows"] = self.data["rows"][:1]
        self.data["certificates"] = ["route-70", "route-7-ingress"]
        phase.clean(self.data)
        self.assertNotIn("route-70", [x[0] for x in self.calls])
        self.assertIn("route-7-ingress", [x[0] for x in self.calls])


if __name__ == "__main__":
    unittest.main()
