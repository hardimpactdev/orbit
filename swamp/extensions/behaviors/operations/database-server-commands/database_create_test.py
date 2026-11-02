"""database:create phases: staged owner secret is root-only and removed; actions run in chart order per driver."""

import json
import stat
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).with_name("scripts")))
import database_create

OWNER = {"password": "A" * 40, "salt": "AAAAAAAAAAAAAAAAAAAAAA=="}
BASE = {
    "repair": False,
    "username": "shop",
    "databases": ["shop", "shop_test"],
    "test_prefix": None,
    "locale": None,
    "host": "10.0.0.5",
    "port": 3306,
    "container": "orbit-process-7-db",
    "index": None,
    "patterns": [],
    "allow": [],
    "stage": "/run/orbit-database-create-12",
}


class DatabaseCreate(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.stage = self.directory.name + "/stage"
        patcher = patch.object(database_create, "stage_path", lambda intent: self.stage)
        patcher.start()
        self.addCleanup(patcher.stop)
        self.addCleanup(self.directory.cleanup)

    def record(self, names):
        calls = []
        patches = [
            patch.object(
                database_create,
                name,
                side_effect=lambda *a, n=name: calls.append((n, a[2])),
            )
            for name in names
        ]
        for item in patches:
            item.start()
            self.addCleanup(item.stop)
        return calls

    def test_stage_writes_the_owner_secret_root_only_and_unstage_removes_it(self):
        intent = {**BASE, "driver": "mysql"}
        self.assertEqual(
            database_create.stage(intent, json.dumps(OWNER)), {"staged": True}
        )
        self.assertEqual(stat.S_IMODE(Path(self.stage).stat().st_mode), 0o700)
        self.assertEqual(stat.S_IMODE(Path(self.stage, "owner").stat().st_mode), 0o600)
        self.assertEqual(database_create.unstage(intent, ""), {"removed": True})
        self.assertFalse(Path(self.stage).exists())

    def test_stage_path_refuses_anything_but_the_run_directory(self):
        patch.stopall()
        with self.assertRaises(database_create.Refused):
            database_create.stage_path({**BASE, "stage": "/tmp/elsewhere"})

    def test_mysql_checks_names_then_creates_and_grants(self):
        intent = {**BASE, "driver": "mysql", "test_prefix": "shop_test"}
        database_create.stage(intent, json.dumps(OWNER))
        calls = self.record(
            [
                "probe_user",
                "probe_databases",
                "create_user",
                "create_database",
                "grant_mysql",
                "postgres_scope",
            ]
        )
        self.assertEqual(
            database_create.create(intent, "admin-secret"), {"created": True}
        )
        self.assertEqual(
            [name for name, _ in calls],
            [
                "probe_user",
                "probe_databases",
                "create_user",
                "create_database",
                "create_database",
                "grant_mysql",
                "grant_mysql",
                "grant_mysql",
            ],
        )
        self.assertEqual(calls[-1][1], {"name": "shop_test", "wildcard": True})

    def test_postgres_scopes_every_database(self):
        intent = {**BASE, "driver": "pgsql", "databases": ["shop"]}
        database_create.stage(intent, json.dumps(OWNER))
        calls = self.record(
            [
                "probe_user",
                "probe_databases",
                "create_user",
                "create_database",
                "grant_mysql",
                "postgres_scope",
            ]
        )
        database_create.create(intent, "admin-secret")
        self.assertEqual(
            [name for name, _ in calls],
            [
                "probe_user",
                "probe_databases",
                "create_user",
                "create_database",
                "postgres_scope",
                "postgres_scope",
                "postgres_scope",
            ],
        )

    def test_valkey_sets_and_saves_the_acl_user(self):
        intent = {
            **BASE,
            "driver": "redis",
            "databases": [],
            "container": None,
            "username": "cache",
        }
        database_create.stage(intent, json.dumps(OWNER))
        calls = self.record(["index", "user", "set_user", "save"])
        database_create.create(intent, "admin-secret")
        self.assertEqual(
            [name for name, _ in calls], ["index", "user", "set_user", "save"]
        )

    def test_check_logs_in_as_the_owner(self):
        intent = {
            **BASE,
            "driver": "redis",
            "databases": [],
            "container": None,
            "username": "cache",
        }
        with patch.object(database_create, "valkey", return_value="PONG") as client:
            self.assertEqual(
                database_create.check(intent, json.dumps(OWNER)), {"login": True}
            )
        self.assertEqual(client.call_args.kwargs, {"user": "cache", "denied_ok": True})
        with patch.object(
            database_create,
            "valkey",
            return_value="NOPERM User cache has no permissions to run the 'ping' command",
        ):
            self.assertEqual(
                database_create.check(intent, json.dumps(OWNER)), {"login": True}
            )

    def test_valkey_login_reads_noperm_after_auth_and_refuses_a_wrong_password(self):
        import subprocess

        import create_common

        intent = {"host": "127.0.0.1", "port": 6379, "driver": "redis"}
        replies = {
            "pw1": subprocess.CompletedProcess(
                [], 0, 'error:"NOPERM User cache has no permissions"\n', ""
            ),
            "bad": subprocess.CompletedProcess(
                [],
                0,
                '"PONG"\n',
                "AUTH failed: WRONGPASS invalid username-password pair\n",
            ),
        }
        for password, expected in (("pw1", "NOPERM"), ("bad", None)):
            with patch.object(create_common, "command", return_value=replies[password]):
                if expected:
                    self.assertEqual(
                        create_common.valkey(
                            intent,
                            {"password": password},
                            "PING",
                            user="cache",
                            denied_ok=True,
                        ),
                        expected,
                    )
                else:
                    with self.assertRaises(create_common.Refused):
                        create_common.valkey(
                            intent,
                            {"password": password},
                            "PING",
                            user="cache",
                            denied_ok=True,
                        )


if __name__ == "__main__":
    unittest.main()
