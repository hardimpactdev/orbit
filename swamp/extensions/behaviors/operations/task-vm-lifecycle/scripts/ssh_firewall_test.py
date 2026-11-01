"""Model UFW ordering, repeat repair and retention of unrelated rules."""
import unittest
from types import SimpleNamespace

from ssh_firewall import ALLOW, DENY, converge


class FirewallTest(unittest.TestCase):
    def test_repeated_converge_places_209_before_deny_and_broad_wireguard(self):
        broad = ["allow", "in", "on", "orbit", "to", "10.44.0.129"]
        rules = [broad]

        def run(argv, **_kwargs):
            if argv[1:3] == ["--force", "delete"]:
                rule = argv[3:]
                if rule in rules:
                    rules.remove(rule)
            elif argv[1:3] == ["insert", "1"]:
                rules.insert(0, argv[3:])
            return SimpleNamespace(returncode=0)

        for _ in range(2):
            self.assertTrue(converge(run)["ssh_firewall_ready"])
            self.assertEqual(rules, [ALLOW, DENY, broad])
        self.assertIn("10.44.0.15", rules[0])
        self.assertNotIn("10.44.0.2", rules[0])

    def test_failure_is_not_reported_ready(self):
        def fail(*_args, **_kwargs):
            raise OSError("ufw unavailable")
        with self.assertRaises(OSError):
            converge(fail)
