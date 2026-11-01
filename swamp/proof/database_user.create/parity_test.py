"""SQL-user fixture checker and secret-delivery tests; no topology or Operations."""

import copy
import importlib.util
import json
import subprocess
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import yaml


def load(name):
    spec = importlib.util.spec_from_file_location(name, Path(__file__).with_name(name + ".py"))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


parity, login = load("parity"), load("login")
SECRET = {"user_password": "a1" * 32}
CID, SID, PID, NODE, OP = 17, 23, 27, 7, 31
IP, SERVER = "10.44.0.7", "proof-user-mysql-reader-run"


class FakeTopology:
    def __init__(self, driver="mysql", mode="reader"):
        self.driver, self.reader = driver, mode == "reader"
        self.username = "proof_reader" if self.reader else "proof_writer"
        self.proof = {"id": OP, "kind": "database_user.create", "status": "succeeded", "target_type": "database_server",
                      "target_id": SID, "result": {"backend": "active"},
                      "input": {"connection_id": CID, "driver": driver, "database": "proof_users",
                                "username": self.username, "read_only": self.reader, "replication": False,
                                "known_user": False, "container": f"orbit-process-{PID}-{SERVER}"}}
        self.connection = {"id": CID, "server": SERVER, "driver": driver, "node_id": NODE,
                           "database": "proof_users", "port": 33481, "host": IP, "has_password": True}
        self.server = {"id": SID, "node_id": NODE, "driver": driver, "port": 33481, "status": "active", "process_id": PID}
        grants = (("SELECT" if self.reader else "ALL PRIVILEGES") + " ON `proof_users`.*" if driver == "mysql" else
                  ("CONNECT, USAGE, SELECT" if self.reader else "DATA WRITE") + ' ON "proof_users"')
        self.users = {"users": [{"database_connection_id": CID, "username": self.username,
                                 "privileges": grants, "replication": False}]}
        self.login_result = {"authenticated": True, "password_enforced": True, "grants_match": True, "write_allowed": not self.reader,
                             "rows_match": True, "credential_file_absent": True, "scratch_absent": True}
        rows = [{"id": 1, "label": "before"}] + ([] if self.reader else [{"id": 2, "label": "user-proof"}])
        self.read = {"kind": "database.read", "status": "succeeded", "target_type": "database_connection", "target_id": CID,
                     "input": {"connection_id": CID}, "result": {"columns": ["id", "label"], "rows": rows,
                     "row_count": len(rows), "truncated": False}}

    def cli(self, *argv):
        return 0, {"operation:show": self.proof, "database:show": self.connection,
                   "database:server:show": self.server, "database:user:list": self.users, "database:query": self.read}[argv[0]]

    def login(self, *args):
        return 0, self.login_result


def check(topology, mode="reader", outputs=None):
    return parity.run(topology, topology.driver, mode, SERVER, SERVER, CID, SID, NODE, 33481, IP, OP,
                      {"exitCode": 0, "stdout": '{"backend":"active"}'} if outputs is None else outputs, SECRET)


class ParityTests(unittest.TestCase):
    def test_four_variants(self):
        for driver in ["mysql", "pgsql"]:
            for mode in ["reader", "writer"]:
                with self.subTest(driver=driver, mode=mode):
                    self.assertIn("generated-password-authenticates", check(FakeTopology(driver, mode), mode))

    def test_wrong_operation_identity_and_intent_fail(self):
        for key, value in [("id", OP + 1), ("kind", "database.create"), ("status", "running"),
                           ("target_id", SID + 1), ("target_type", "database_connection")]:
            topology = FakeTopology()
            topology.proof[key] = value
            with self.subTest(key=key), self.assertRaises(AssertionError):
                check(topology)
        for key, value in [("read_only", 1), ("username", "foreign"), ("connection_id", 99), ("replication", True)]:
            topology = FakeTopology()
            topology.proof["input"][key] = value
            with self.subTest(key=key), self.assertRaises(AssertionError):
                check(topology)

    def test_private_material_cannot_appear_in_any_public_output(self):
        for record in ["proof", "connection", "server", "users", "read"]:
            topology = FakeTopology()
            getattr(topology, record)["hidden"] = SECRET["user_password"]
            with self.subTest(record=record), self.assertRaises(AssertionError):
                check(topology)
        with self.assertRaises(AssertionError):
            parity.safe({"nested": [{"password": "[REDACTED]"}]}, SECRET)

    def test_wrong_grants_login_identity_cleanup_or_owner_rows_fail(self):
        for area, key, value in [("connection", "server", "foreign"), ("server", "node_id", 99),
                                 ("login_result", "grants_match", False), ("login_result", "authenticated", 1),
                                 ("login_result", "scratch_absent", False), ("login_result", "write_allowed", True),
                                 ("read", "target_id", 99)]:
            topology = FakeTopology()
            getattr(topology, area)[key] = value
            with self.subTest(area=area, key=key), self.assertRaises(AssertionError):
                check(topology)
        for key, value in [("rows", []), ("row_count", True), ("truncated", 0)]:
            topology = FakeTopology()
            topology.read["result"][key] = value
            with self.subTest(key=key), self.assertRaises(AssertionError):
                check(topology)
        topology = FakeTopology()
        topology.users["users"][0]["privileges"] = "ALL PRIVILEGES ON *.*"
        with self.assertRaises(AssertionError):
            check(topology)

    def test_native_output_must_match_and_have_integer_exit_code(self):
        for value in [{"exitCode": False, "stdout": '{}'}, {"exitCode": 0, "stdout": '{}'}]:
            with self.assertRaises(AssertionError):
                check(FakeTopology(), outputs=value)

    def test_secret_file_must_be_private_and_generated(self):
        with tempfile.TemporaryDirectory() as home:
            path = Path(home, "secrets.json")
            path.write_text(json.dumps(SECRET))
            path.chmod(0o600)
            self.assertEqual(parity.read_secret(path), SECRET)
            path.chmod(0o644)
            with self.assertRaises(AssertionError):
                parity.read_secret(path)
            path.chmod(0o600)
            path.write_text('{"user_password":"not-generated"}')
            with self.assertRaises(AssertionError):
                parity.read_secret(path)

    def test_canonical_executor_stdin_file_is_private_and_removed(self):
        files = []

        def execute(argv, **kwargs):
            self.assertIn("--timeout=1800", argv)
            self.assertEqual(kwargs["timeout"], 1830)
            self.assertNotIn(SECRET["user_password"], json.dumps(argv))
            path = Path(next(arg.split("=", 1)[1] for arg in argv if arg.startswith("--argv-file=")))
            files.append(path)
            self.assertEqual(path.stat().st_mode & 0o777, 0o600)
            payload = json.loads(path.read_text())
            self.assertEqual(list(payload), ["argv", "stdin"])
            self.assertNotIn(SECRET["user_password"], json.dumps(payload["argv"]))
            self.assertEqual(json.loads(payload["stdin"]), SECRET)
            return subprocess.CompletedProcess(argv, 0, "{}", "")

        with patch.dict("os.environ", {"PROOF_EXEC": "bin/e2e-topology exec TASK-proof"}), \
                patch.object(subprocess, "run", side_effect=execute):
            parity.Topology().execute("app-dev", ["public-command"], json.dumps(SECRET))
        self.assertTrue(files and all(not path.exists() for path in files))

    def test_private_executor_file_is_removed_on_transport_failure(self):
        files = []

        def failed(argv, **kwargs):
            files.append(Path(next(a.split("=", 1)[1] for a in argv if a.startswith("--argv-file="))))
            raise subprocess.TimeoutExpired(argv, kwargs["timeout"])

        with patch.dict("os.environ", {"PROOF_EXEC": "bin/e2e-topology exec TASK-proof"}), \
                patch.object(subprocess, "run", side_effect=failed), self.assertRaises(subprocess.TimeoutExpired):
            parity.Topology().execute("app-dev", ["public-command"], json.dumps(SECRET))
        self.assertTrue(files and all(not path.exists() for path in files))


class LoginTests(unittest.TestCase):
    def test_mysql_grants_refuse_global_extra_or_grant_option(self):
        grants = "GRANT USAGE ON *.* TO `proof_reader`@`%`\nGRANT SELECT ON `proof\\_users`.* TO `proof_reader`@`%`\n"
        self.assertTrue(login.mysql_grants(grants, "proof_reader", True))
        for changed in [grants.replace("USAGE", "ALL PRIVILEGES"), grants.rstrip() + " WITH GRANT OPTION\n",
                        grants + "GRANT SELECT ON `other`.* TO `proof_reader`@`%`\n", grants.replace("\\_", "_")]:
            self.assertFalse(login.mysql_grants(changed, "proof_reader", True))

    def test_mysql_scope_matches_the_actual_native_compiler(self):
        root = Path(__file__).resolve().parents[2]
        scripts = root / "extensions/behaviors/operations/database-server-commands/scripts"
        result = subprocess.run(["python3", "-I", "-c",
                                 'import sys; sys.path.insert(0,sys.argv[1]); from create_common import grant_name; print(grant_name("proof_users"))',
                                 str(scripts)], capture_output=True, text=True, timeout=10, check=True)
        scope = result.stdout.strip()
        self.assertEqual(scope, r"proof\_users")
        self.assertTrue(login.mysql_grants("GRANT USAGE ON *.* TO `proof_reader`@`%`\nGRANT SELECT ON `" + scope
                                          + "`.* TO `proof_reader`@`%`", "proof_reader", True))

    def test_four_real_client_plans_keep_password_only_in_private_config(self):
        for driver in ["mysql", "pgsql"]:
            for reader in [True, False]:
                records, writes, rejected = [], [], []
                username = "proof_reader" if reader else "proof_writer"

                def command(argv):
                    self.assertNotIn(SECRET["user_password"], json.dumps(argv))
                    if argv[0] == "inspect":
                        raw = {"Config": {"Labels": {"orbit.managed": "true", "orbit.container.kind": "process",
                                                     "orbit.process.id": str(PID)},
                                          "Image": "mysql:8.4" if driver == "mysql" else "postgres:18"},
                               "HostConfig": {"PortBindings": {"3306/tcp" if driver == "mysql" else "5432/tcp":
                                   [{"HostIp": IP, "HostPort": "33481"}]}},
                               "State": {"Status": "running"}}
                        return subprocess.CompletedProcess(argv, 0, json.dumps([raw]), "")
                    private = Path(argv[argv.index("--env-file") + 1])
                    records.append(private)
                    self.assertEqual(private.stat().st_mode & 0o777, 0o600)
                    self.assertNotIn("127.0.0.1", argv)
                    self.assertTrue(IP in argv or "--host=" + IP in argv)
                    if SECRET["user_password"] not in private.read_text():
                        rejected.append(True)
                        return subprocess.CompletedProcess(argv, 1, "", "Access denied for user" if driver == "mysql"
                                                           else "password authentication failed")
                    sql = argv[-1].removeprefix("--execute=")
                    if sql.startswith("INSERT"):
                        writes.append(sql)
                        return subprocess.CompletedProcess(argv, int(reader), "", ("INSERT command denied to user" if driver == "mysql"
                            else "permission denied for table proof_rows") if reader else "")
                    if sql.startswith("SHOW GRANTS"):
                        value = f"GRANT USAGE ON *.* TO `{username}`@`%`\nGRANT " + ("SELECT" if reader else "ALL PRIVILEGES") + f" ON `proof\\_users`.* TO `{username}`@`%`"
                    elif sql.startswith("SELECT rolsuper"):
                        value = "f|f|f|f|f"
                    else:
                        value = "before" if not writes or reader else "before\nuser-proof"
                    return subprocess.CompletedProcess(argv, 0, value, "")

                with self.subTest(driver=driver, reader=reader), patch.object(login, "command", side_effect=command):
                    state = login.check(driver, SERVER, PID, username, reader, OP, SECRET["user_password"], IP, 33481)
                    self.assertEqual(state["write_allowed"], not reader)
                    self.assertEqual(rejected, [True])
                self.assertTrue(records and all(not path.exists() for path in records))

    def test_passwordless_trust_cannot_pass_authentication_check(self):
        raw = {"Config": {"Labels": {"orbit.managed": "true", "orbit.container.kind": "process",
                                     "orbit.process.id": str(PID)}, "Image": "postgres:18"}, "State": {"Status": "running"},
               "HostConfig": {"PortBindings": {"5432/tcp": [{"HostIp": IP, "HostPort": "54481"}]}}}

        def trust(argv):
            return subprocess.CompletedProcess(argv, 0, json.dumps([raw]) if argv[0] == "inspect" else "before", "")

        with patch.object(login, "command", side_effect=trust), self.assertRaisesRegex(AssertionError, "enforce password"):
            login.check("pgsql", SERVER, PID, "proof_reader", True, OP, SECRET["user_password"], IP, 54481)


class FixtureTests(unittest.TestCase):
    def test_four_api_variants_use_real_response_path_and_body_only_secret(self):
        root = Path(__file__).resolve().parents[2]
        flow = yaml.safe_load((root / "workflows/workflow-operation-database-user-create.yaml").read_text())
        paths = list(Path(__file__).parent.glob("*.json"))
        self.assertEqual(len(paths), 4)
        for path in paths:
            value = json.loads(path.read_text())
            with self.subTest(path=path):
                self.assertEqual(value["topology"], ["app-dev+database"])
                self.assertEqual(value["request"]["operation"], "$.data.id")
                self.assertEqual(value["request"]["api"]["method"], "POST")
                self.assertEqual(value["secrets"], [{"name": "user_password", "value": "generate:hex32"}])
                self.assertEqual(value["request"]["api"]["body"]["password"], "${secret.user_password}")
                rest = copy.deepcopy(value)
                del rest["request"]["api"]["body"]
                self.assertNotIn("${secret.", json.dumps(rest))
                for call in value["prepare"]:
                    self.assertEqual(call["argv"][0], "orbit")
                    self.assertFalse(any("--password" in arg for arg in call["argv"]))
                self.assertEqual(value["queue_timeout_seconds"], flow.get("queueTimeout"))
                self.assertIs(type(value["budget_seconds"]), int)
                self.assertIs(type(value["queue_timeout_seconds"]), int)
                self.assertLess(0, value["queue_timeout_seconds"])
                self.assertLess(value["queue_timeout_seconds"], value["budget_seconds"])
                self.assertTrue(all(type(phase["budget_seconds"]) is int and
                                    0 < phase["budget_seconds"] <= value["budget_seconds"]
                                    for phase in value["phases"]))
                for selected in [*value["phases"], value["expect"]["outputs"]]:
                    job = next(j for j in flow["jobs"] if j["name"] == selected["job"])
                    self.assertIn(selected["step"], [step["name"] for step in job["steps"]])
        controller = (root.parent / "apps/gateway/app/Http/Controllers/Api/DatabaseConnectionsController.php").read_text()
        method = controller.split("public function storeUser(", 1)[1].split("public function update(", 1)[0]
        self.assertIn("'data' => OperationData::fromModel($user)->toArray()", method)


if __name__ == "__main__":
    unittest.main()
