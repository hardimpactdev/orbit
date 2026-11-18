#!/usr/bin/python3
"""Run private SQL grant regressions without creating a host listener or exposing login data."""
import json
import runpy
import unittest
from pathlib import Path

SQL = runpy.run_path(str(Path(__file__).resolve().parents[1] / "ansible/files/instance-release-sql.py"))
MACHINE = "instance-7-" + "a"*12
ROW = {"target_id": 1, "prefix": "DB", "host": "10.45.0.3", "listen": "127.64.0.1", "port": 5432,
       "driver":"pgsql", "database":"app", "username":"app", "password":"private-fixture"}


class ProductionSql(unittest.TestCase):
    def setUp(self):
        self.config = {"config": {"user.orbit.release.target":"instance-7"}, "devices": {}}
        self.calls = []

    def run_incus(self, *args, data=None, timeout=30):
        self.calls.append((args, data))
        if args[:2] == ("config", "set"):
            key, value = args[3].split("=", 1)
            self.config["config"][key] = value
        if args[0] == "query":
            return json.dumps(self.config)
        if args[:3] == ("config", "device", "add"):
            self.config["devices"][args[4]] = {"type":args[5], **dict(item.split("=", 1) for item in args[6:])}
        if args[:3] == ("config", "device", "remove"):
            del self.config["devices"][args[4]]
        return ""

    def test_convert_two_grants_with_coincident_ports_without_exposing_host_or_login(self):
        rows = [ROW, {**ROW,"target_id":2, "prefix":"REPORT","listen":"127.64.0.2"}]
        SQL["reconcile"](7, MACHINE, "8.5", rows, run=self.run_incus)
        self.assertEqual(len(self.config["devices"]), 2)
        for args, data in self.calls:
            self.assertNotIn(ROW["password"], repr(args))
            if args[0] == "exec":
                self.assertEqual(json.loads(data)["password"], ROW["password"])
            if args[:3] == ("config","device","add"):
                self.assertIn("bind=instance", args)
                self.assertIn("connect=tcp:10.45.0.3:5432", args)
        self.calls.clear()
        SQL["reconcile"](7, MACHINE, "8.5", rows, verify_only=True, run=self.run_incus)
        self.assertEqual([args[0] for args, _ in self.calls], ["query", "exec", "exec"])
        SQL["reconcile"](7, MACHINE, "8.5", rows[1:], run=self.run_incus)
        self.assertEqual(list(self.config["devices"]), ["orbit-sql-2"])

    def test_deny_wrong_owner_loopback_broad_or_unadmitted_database_before_guest_commands(self):
        for row in [{**ROW,"host":"127.0.0.1"}, {**ROW,"host":"0.0.0.0"}, {**ROW,"listen":"0.0.0.0"},
                    {**ROW,"host":"8.8.8.8"}, {**ROW,"database":"app;host=other"}, {**ROW,"password":""}]:
            with self.subTest(row=row["host"]), self.assertRaises(ValueError):
                SQL["reconcile"](7, MACHINE, "8.5", [row], run=self.run_incus)
        self.assertEqual(self.calls, [])
        self.config["config"]["user.orbit.release.target"] = "instance-6"
        with self.assertRaises(ValueError):
            SQL["reconcile"](7, MACHINE, "8.5", [ROW], run=self.run_incus)
        self.assertEqual([args[0] for args, _ in self.calls], ["query"])

    def test_unowned_or_modified_proxy_cannot_be_adopted_as_an_existing_grant(self):
        SQL["reconcile"](7, MACHINE, "8.5", [ROW], run=self.run_incus)
        key = SQL["GRANTS"]
        authorization = self.config["config"].pop(key)
        with self.assertRaises(ValueError):
            SQL["approved_devices"](self.config)
        self.config["config"][key] = authorization
        self.assertEqual(SQL["approved_devices"](self.config), self.config["devices"])
        self.config["devices"]["orbit-sql-1"]["bind"] = "host"
        with self.assertRaises(ValueError):
            SQL["approved_devices"](self.config)

    def test_checking_candidate_or_live_environment_never_installs_missing_or_changed_devices(self):
        with self.assertRaisesRegex(ValueError, "database_endpoint_changed"):
            SQL["reconcile"](7, MACHINE, "8.5", [ROW], verify_only=True, run=self.run_incus)
        self.assertFalse(self.config["devices"])


if __name__ == "__main__":
    unittest.main()
