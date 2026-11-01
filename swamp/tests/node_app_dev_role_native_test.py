"""AppDev F9 dependencies on native SSH; host policy has separate filesystem tests."""

import json

import yaml
from native_harness import ROOT, NativeTestCase
from node_database_role_native_test import DatabaseRoleNative


class AppDevRoleNative(NativeTestCase):
    native_timeout = 120
    run_role = DatabaseRoleNative.run_role
    ingress = DatabaseRoleNative.ingress

    def setUp(self):
        DatabaseRoleNative.setUp(self)
        self.ingress()
        intent = self.input["intent"]
        intent.update(
            role="app-dev",
            php={"versions": ["8.5"]},
            javascript={"fixture": True},
            storage={
                "root": "/srv/orbit/apps",
                "user": "orbit",
                "create_missing": True,
                "protected_roots": [],
                "managed_checkouts": [],
            },
        )
        intent["footprint"]["payloads"].update(
            {
                "php-runtime": {"configurations": [{"version": "8.5"}]},
                "tmpfiles": {"rule": "fixture"},
            }
        )
        intent["footprint"]["artifacts"].extend(
            [{"name": name, "required": True} for name in ["php-runtime", "tmpfiles"]]
        )
        intent["publications"] = [
            {
                "node_id": node,
                "control_node_id": 209,
                "connection": {},
                "host_keys": intent["ssh"]["target"]["host_keys"],
                "before": {"dns": {}},
                "after": {"dns": {}},
            }
            for node in [1, 2]
        ]
        # Preserve the real public child schema; only its host-effect boundary is audited here.
        flow = yaml.safe_load(
            (ROOT / "workflows/workflow-route-dns-publish.yaml").read_text()
        )
        flow["jobs"] = [
            {
                "name": "dns",
                "steps": [
                    {
                        "name": "result",
                        "task": {
                            "type": "behavior",
                            "behaviorType": "@swamp/ssh",
                            "behaviorName": "dns-fixture",
                            "methodName": "exec",
                            "globalArgs": {
                                "name": "dns-fixture",
                                "sshBinary": str(self.repo / "fixture-ssh"),
                                "transport": {
                                    "kind": "ssh",
                                    "user": "orbit",
                                    "port": 22,
                                    "identityFile": intent["ssh"]["identity_file"],
                                },
                                "hosts": [
                                    {
                                        "name": "target",
                                        "address": "dns.invalid",
                                        "transport": {
                                            "hostKeys": intent["ssh"]["target"][
                                                "host_keys"
                                            ]
                                        },
                                    }
                                ],
                            },
                            "inputs": {
                                "hosts": "name:target",
                                "argv": ["private-dns-fixture"],
                            },
                        },
                    }
                ],
            }
        ]
        (self.repo / "workflows/workflow-route-dns-publish.yaml").write_text(
            yaml.safe_dump(flow, sort_keys=False)
        )
        binary = self.repo / "fixture-ssh"
        source = binary.read_text().replace(
            "if '/usr/bin/env' in command:",
            "if 'private-dns-fixture' in command:finish('private_dns',{})\nif '/usr/bin/env' in command:",
        )
        source = (
            source.replace(
                "['ingress','websocket','app-prod']",
                "['ingress','websocket','app-prod','app-dev']",
            )
            .replace(
                "if intent['role']=='app-prod':",
                "if intent['role'] in ['app-prod','app-dev']:",
            )
            .replace(
                "   record('caddy_ordering')",
                "   if intent['role']=='app-prod':record('caddy_ordering')",
            )
        )
        source = source.replace(
            " if phase=='phase_caddy_service.py':",
            " if phase=='phase_storage.py':finish('storage',{'ready':True})\n if phase=='phase_hibernation.py':record('hibernation');finish('caddy_ordering',{'ready':True})\n if phase=='phase_tmpfiles.py':finish('tmpfiles',{'ready':True})\n if phase=='phase_caddy_service.py':",
        )
        binary.write_text(source)

    def calls(self):
        return [
            json.loads(row)["action"] for row in self.audit.read_text().splitlines()
        ]

    def test_storage_dns_packages_hibernation_caddy_firewall_and_footprint(self):
        _, result = self.run_role()
        self.assertTrue(result["role_proven"])
        self.assertTrue(result["publication_proven"])
        self.assertTrue(result["footprint"]["footprint_proven"])
        calls = self.calls()
        self.assertEqual(calls.count("private_dns"), 2)
        for before, after in [
            ("storage", "private_dns"),
            ("private_dns", "caddy_package"),
            ("javascript", "hibernation"),
            ("hibernation", "caddy_ordering"),
            ("caddy_ordering", "caddy_write"),
            ("caddy_write", "firewall"),
            ("firewall", "tmpfiles"),
        ]:
            self.assertLess(calls.index(before), calls.index(after))

    def test_storage_refusal_stops_dns_packages_and_publication(self):
        self.failure.write_text("storage")
        _, result = self.run_role(False)
        self.assertFalse(result["role_proven"])
        self.assertEqual(result["failed_step"], "storage")
        for action in [
            "private_dns",
            "caddy_package",
            "hibernation",
            "caddy_write",
            "firewall",
            "tmpfiles",
        ]:
            self.assertNotIn(action, self.calls())

    def test_dns_refusal_stops_all_packages_and_later_baseline(self):
        self.failure.write_text("private_dns")
        _, result = self.run_role(False)
        self.assertFalse(result["role_proven"])
        self.assertFalse(result["publication_proven"])
        self.assertEqual(result["failed_step"], "private-dns")
        for action in [
            "caddy_package",
            "hibernation",
            "caddy_write",
            "firewall",
            "tmpfiles",
        ]:
            self.assertNotIn(action, self.calls())

    def test_hibernation_refusal_stops_caddy_ordering_publication_and_firewall(self):
        self.failure.write_text("hibernation")
        _, result = self.run_role(False)
        self.assertFalse(result["role_proven"])
        self.assertEqual(result["failed_step"], "hibernation")
        for action in ["caddy_ordering", "caddy_write", "firewall", "tmpfiles"]:
            self.assertNotIn(action, self.calls())

    def test_tmpfiles_refusal_is_a_footprint_follow_up(self):
        self.failure.write_text("tmpfiles")
        _, result = self.run_role()
        self.assertTrue(result["role_proven"])
        self.assertFalse(result["footprint"]["footprint_proven"])
        self.assertEqual(result["footprint"]["failed_artifact"], "tmpfiles")
