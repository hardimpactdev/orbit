"""database:destroy phases: blockers refuse before any drop; names validate before SQL; gone proves absence."""

import sys
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).with_name("scripts")))
import database_destroy
from create_common import Refused

M = {"password": "admin-secret"}
BASE = {
    "driver": "mysql",
    "container": "orbit-process-7-db",
    "host": "10.0.0.5",
    "port": 3306,
    "databases": ["shop"],
    "users": ["shop"],
    "test_prefixes": [],
}


class DatabaseDestroy(unittest.TestCase):
    def test_blockers_refuse_before_any_drop(self):
        statements = []
        with (
            patch.object(
                database_destroy, "blockers", side_effect=Refused("database.in_use")
            ),
            patch.object(
                database_destroy,
                "sql",
                side_effect=lambda i, m, s: statements.append(s),
            ),
            self.assertRaises(Refused) as caught,
        ):
            database_destroy.drop(BASE, M)
        self.assertEqual(caught.exception.code, "database.in_use")
        self.assertEqual(statements, [])

    def test_mysql_drops_found_test_databases_then_the_user(self):
        statements = []
        intent = {**BASE, "test_prefixes": ["shop_test"]}
        with (
            patch.object(
                database_destroy,
                "inventory",
                return_value={"databases": ["shop_test", "shop_test_1"]},
            ),
            patch.object(database_destroy, "blockers"),
            patch.object(
                database_destroy,
                "sql",
                side_effect=lambda i, m, s: statements.append(s),
            ),
        ):
            self.assertEqual(database_destroy.drop(intent, M), {"dropped": True})
        self.assertEqual(
            statements,
            [
                "DROP DATABASE IF EXISTS `shop`;\n",
                "DROP DATABASE IF EXISTS `shop_test`;\n",
                "DROP DATABASE IF EXISTS `shop_test_1`;\n",
                "DROP USER IF EXISTS 'shop'@'%';\n",
            ],
        )

    def test_a_found_name_outside_the_admitted_prefixes_is_refused(self):
        intent = {**BASE, "test_prefixes": ["shop_test"]}
        with (
            patch.object(
                database_destroy, "inventory", return_value={"databases": ["other"]}
            ),
            self.assertRaises(Refused) as caught,
        ):
            database_destroy.drop(intent, M)
        self.assertEqual(caught.exception.code, "database.server_admin_invalid")

    def test_valkey_deletes_users_but_never_default_and_saves(self):
        calls = []
        intent = {
            **BASE,
            "driver": "redis",
            "container": None,
            "databases": [],
            "users": ["cache", "default"],
        }

        def valkey(i, m, *argv):
            calls.append(argv)
            return "OK" if argv[1] == "SAVE" else 1

        with patch.object(database_destroy, "valkey", side_effect=valkey):
            database_destroy.drop(intent, M)
        self.assertEqual(calls, [("ACL", "DELUSER", "cache"), ("ACL", "SAVE")])

    def test_gone_fails_while_a_name_is_left(self):
        with patch.object(database_destroy, "sql", return_value="shop\n"), self.assertRaises(Refused) as caught:
            database_destroy.gone(BASE, M)
        self.assertEqual(caught.exception.code, "database.destroy_incomplete")
        with patch.object(database_destroy, "sql", return_value=""):
            self.assertEqual(database_destroy.gone(BASE, M), {"absent": True})


if __name__ == "__main__":
    unittest.main()
