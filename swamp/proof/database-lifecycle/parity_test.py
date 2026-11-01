"""Checker regressions only; these tests allocate no topology or runtime."""

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


parity, observe = load("parity"), load("observe")
SLUG, SERVER, MARKER, IP = "proof-db-mysql-fixture", "proof-managed-mysql-fixture", "fixture", "10.44.0.10"
NODE, PORT, PROCESS, SERVER_ID = 10, 33171, 21, 31


class FakeTopology(parity.Topology):
    def __init__(self, mode="create", driver="mysql"):
        self.mode, self.driver, self.counter, self.calls = mode, driver, 0, []
        self.slug = SLUG if mode == "create" else SERVER
        self.server = {"id": SERVER_ID, "slug": SERVER, "driver": driver, "node_id": NODE,
                       "process_id": PROCESS, "port": PORT, "status": "active"}
        self.connection = {"id": 41, "slug": SLUG, "driver": driver, "server": SERVER,
                           "node_id": NODE, "host": IP, "port": PORT, "has_password": True,
                           "owner_instance_id": None, "test_database": None,
                           "username": SLUG if driver == "redis" else SLUG.replace("-", "_"),
                           "database": "0" if driver == "redis" else SLUG.replace("-", "_")}
        self.operation = {"id": 51, "kind": {"create": "database.create", "server-create": "database_server.create",
                                             "server-destroy": "database_server.destroy"}[mode],
                          "target_type": "database_server", "target_id": SERVER_ID, "status": "succeeded",
                          "result": {"backend": "absent" if mode == "server-destroy" else "active"},
                          "input": {"server_id": SERVER_ID, "process_id": PROCESS, "connection_id": 41,
                                    "container": f"orbit-process-{PROCESS}-{SERVER}"}}
        self.observed = {"valid": True}
        self.readback = [{"proof_database": self.connection["database"],
                          "proof_user": self.connection["username"] + ("@%" if driver == "mysql" else "")}]
        self.wrong_refusal, self.changed_retry = False, False

    def execute(self, node, argv):
        self.calls.append((node, argv))
        if node == "app-dev":
            return 0, self.observed
        command, *args = argv[1:-1]
        self.counter += 1
        if command == "operation:show":
            return 0, self.operation
        if command == "database:show":
            return 0, {**self.connection, "request_id": str(self.counter)}
        if command == "database:server:show":
            if self.mode == "server-destroy":
                return 1, {"error": {"code": "http.404"}}
            return 0, {**self.server, "request_id": str(self.counter)}
        if command == "database:server:list":
            return 0, {"servers": []}
        if command == "database:query":
            return 0, {"id": 61, "kind": "database.read", "status": "succeeded",
                       "target_type": "database_connection", "target_id": 41,
                       "result": {"rows": self.readback}}
        if command == "database:server:create" and "--port=" + str(PORT) in args:
            if self.changed_retry:
                self.server["process_id"] += 1
            return 0, {**self.operation, "id": 52}
        code = "database.server_in_use" if command == "database:server:destroy" else "database.slug_conflict"
        return 1, {"error": {"code": "other" if self.wrong_refusal else code}}


def check(topology, outputs=None):
    return parity.run(topology, topology.mode, topology.slug, SERVER, topology.driver, PORT, NODE,
                      IP, MARKER, SERVER_ID, 51, outputs or {"exitCode": 0, "stdout": json.dumps(topology.operation["result"])})


class ParityTests(unittest.TestCase):
    def test_each_kind_and_engine(self):
        for mode in ["create", "server-create", "server-destroy"]:
            for driver in ["mysql", "pgsql", "redis"]:
                with self.subTest(mode=mode, driver=driver):
                    topology = FakeTopology(mode, driver)
                    self.assertTrue(check(topology))
                    self.assertTrue(any(node == "app-dev" for node, _ in topology.calls))

    def test_rejects_wrong_operation_identity_or_terminal(self):
        for key, value in [("id", 52), ("kind", "database.write"), ("status", "running"),
                           ("target_type", "database_connection"), ("target_id", 99)]:
            with self.subTest(key=key):
                topology = FakeTopology()
                topology.operation[key] = value
                with self.assertRaises(AssertionError):
                    check(topology)

    def test_rejects_native_failure_or_private_result(self):
        for outputs in [{"exitCode": 1, "stdout": '{"backend":"active"}'},
                        {"exitCode": False, "stdout": '{"backend":"active"}'},
                        {"exitCode": 0, "stdout": '{"backend":"active","password":"private"}'}]:
            with self.subTest(outputs=outputs), self.assertRaises(AssertionError):
                check(FakeTopology(), outputs)

    def test_rejects_wrong_connection_identity_credential_or_placement(self):
        for key, value in [("id", 99), ("driver", "redis"), ("node_id", 99), ("has_password", False),
                           ("host", "192.0.2.1"), ("port", 9999), ("server", "other"),
                           ("database", "other"), ("owner_instance_id", 99)]:
            with self.subTest(key=key):
                topology = FakeTopology()
                topology.connection[key] = value
                with self.assertRaises(AssertionError):
                    check(topology)

    def test_rejects_wrong_sql_readback(self):
        topology = FakeTopology()
        topology.readback = [{"proof_database": "other"}]
        with self.assertRaisesRegex(AssertionError, "authenticate"):
            check(topology)

    def test_rejects_sql_readback_authenticated_as_root(self):
        topology = FakeTopology()
        topology.readback[0]["proof_user"] = "root@localhost"
        with self.assertRaisesRegex(AssertionError, "SQL identity"):
            check(topology)

    def test_sql_readback_queries_pass_actual_product_read_policy(self):
        root = Path(__file__).resolve().parents[3]
        policy = root / "swamp/extensions/behaviors/operations/database-read-commands/scripts/read_policy.php"
        for driver in ["mysql", "pgsql"]:
            reply = subprocess.run(
                ["php", "-r", 'require $argv[1]; assertReadSql($argv[2], $argv[3]);',
                 str(policy), parity.sql_readback_query(driver), driver],
                capture_output=True, text=True, timeout=10, check=False,
            )
            with self.subTest(driver=driver):
                self.assertEqual(reply.returncode, 0, "Public parity query fails the actual SQL policy")

    def test_rejects_refusal_for_wrong_reason(self):
        topology = FakeTopology()
        topology.wrong_refusal = True
        with self.assertRaisesRegex(AssertionError, "refusal"):
            check(topology)

    def test_request_id_changes_do_not_change_resource_identity(self):
        topology = FakeTopology("server-create")
        self.assertIn("exact-create-retains-identities", check(topology))
        self.assertGreater(topology.counter, 3)

    def test_rejects_create_retry_replacing_process(self):
        topology = FakeTopology("server-create")
        topology.changed_retry = True
        with self.assertRaisesRegex(AssertionError, "changed server/Process"):
            check(topology)

    def test_destroy_checks_captured_id_and_exact_container(self):
        for key, value in [("server_id", 99), ("container", "orbit-process-99-other"), ("process_id", None)]:
            topology = FakeTopology("server-destroy")
            topology.operation["input"][key] = value
            with self.subTest(key=key), self.assertRaises(AssertionError):
                check(topology)

    def test_rejects_bad_workload_state(self):
        for mode in ["server-create", "server-destroy"]:
            topology = FakeTopology(mode)
            topology.observed = {"volume_retained": False}
            with self.subTest(mode=mode), self.assertRaisesRegex(AssertionError, "Workload"):
                check(topology)


class WorkloadTests(unittest.TestCase):
    def setUp(self):
        self.expected = {"driver": "mysql", "process_id": PROCESS, "image": "mysql:8.4",
                         "port": PORT, "ip": IP, "volume": "orbit-" + SERVER + "-data"}
        self.raw = {"Config": {"Image": "mysql:8.4", "Env": ["LANG=C"],
                               "Labels": {"orbit.managed": "true", "orbit.container.kind": "process",
                                          "orbit.process.id": str(PROCESS)}},
                    "HostConfig": {"PortBindings": {"3306/tcp": [{"HostIp": IP, "HostPort": str(PORT)}]}},
                    "State": {"Status": "running"},
                    "Mounts": [{"Type": "volume", "Name": self.expected["volume"],
                                "Destination": "/var/lib/mysql", "RW": True}]}

    def test_running_owned_private_server(self):
        self.assertTrue(all(observe.summary(self.raw, self.expected).values()))

    def test_secret_values_never_returned(self):
        for key in ["MYSQL_ROOT_PASSWORD", "POSTGRES_PASSWORD"]:
            self.raw["Config"]["Env"] = [key + "=private-sentinel"]
            value = observe.summary(self.raw, self.expected)
            self.assertFalse(value["environment_clean"])
            self.assertNotIn("private-sentinel", json.dumps(value))

    def test_public_loopback_wildcard_wrong_port_and_wrong_private_ip(self):
        for ip in ["0.0.0.0", "::", "127.0.0.1", "1.1.1.1", "192.0.2.1", "10.44.0.99"]:
            raw = copy.deepcopy(self.raw)
            raw["HostConfig"]["PortBindings"]["3306/tcp"][0]["HostIp"] = ip
            with self.subTest(ip=ip):
                self.assertFalse(observe.summary(raw, self.expected)["private_bindings"])
        self.raw["HostConfig"]["PortBindings"]["3306/tcp"][0]["HostPort"] = "3333"
        self.assertFalse(observe.summary(self.raw, self.expected)["private_bindings"])
        self.raw["HostConfig"]["PortBindings"] = {"3307/tcp": [{"HostIp": IP, "HostPort": str(PORT)}]}
        self.assertFalse(observe.summary(self.raw, self.expected)["private_bindings"])

    def test_rejects_foreign_process_or_data_mount(self):
        self.raw["Config"]["Labels"]["orbit.process.id"] = "99"
        self.raw["Mounts"][0]["Name"] = "foreign-data"
        value = observe.summary(self.raw, self.expected)
        self.assertFalse(value["owned"])
        self.assertFalse(value["data_mount"])

    def test_acl_checks_user_hash_rules_and_pattern_without_returning_hash(self):
        text = "user client on #" + "a" * 64 + " ~proof:* -@all +get -@admin -@dangerous\n"
        self.assertTrue(all(observe.acl_summary(text, "client").values()))
        for source, replacement in [("#" + "a" * 64, ">plain"), (" on ", " off "),
                                    ("~proof:*", "~*"), ("-@admin", "+@admin")]:
            with self.subTest(source=source):
                value = observe.acl_summary(text.replace(source, replacement), "client")
                self.assertFalse(all(value.values()))
                self.assertNotIn("a" * 64, json.dumps(value))

    def test_canonical_get_acl_tolerates_only_redundant_denials(self):
        text = "user client on #" + "a" * 64 + " ~proof:* resetchannels -@all +get"
        self.assertTrue(all(observe.acl_summary(text, "client").values()))
        for extra in [" +@all", " +set", " ~*", " &*", " allchannels", " -get", " -@read", " nopass"]:
            with self.subTest(extra=extra):
                self.assertFalse(all(observe.acl_summary(text + extra, "client").values()))

    def test_absent_means_containers_gone_and_volume_marker_preserved(self):
        with tempfile.TemporaryDirectory() as folder:
            Path(folder, ".orbit-proof-" + MARKER).write_text(MARKER)
            volume = json.dumps([{"Name": self.expected["volume"], "Mountpoint": folder}])
            with patch.object(observe, "docker", side_effect=["", "", volume]):
                self.assertTrue(all(observe.observe("absent", SERVER, PROCESS, "mysql", PORT, IP, MARKER).values()))
            with patch.object(observe, "docker", side_effect=["residual\n", "", volume]):
                self.assertFalse(observe.observe("absent", SERVER, PROCESS, "mysql", PORT, IP, MARKER)["containers_absent"])
            with patch.object(observe, "docker", side_effect=["", "unlabelled-residual\n", volume]):
                self.assertFalse(observe.observe("absent", SERVER, PROCESS, "mysql", PORT, IP, MARKER)["containers_absent"])
            Path(folder, ".orbit-proof-" + MARKER).write_text("changed")
            with patch.object(observe, "docker", side_effect=["", "", volume]):
                self.assertFalse(observe.observe("absent", SERVER, PROCESS, "mysql", PORT, IP, MARKER)["marker_retained"])

    def test_marker_is_exclusive_and_requires_owned_container(self):
        with tempfile.TemporaryDirectory() as folder:
            volume = json.dumps([{"Name": self.expected["volume"], "Mountpoint": folder}])
            names = f"orbit-process-{PROCESS}-{SERVER}\n"
            replies = [names, names, volume, json.dumps([self.raw])]
            with patch.object(observe, "docker", side_effect=replies):
                self.assertEqual(observe.observe("mark", SERVER, PROCESS, "mysql", PORT, IP, MARKER), {"marked": True})
            marker = Path(folder, ".orbit-proof-" + MARKER)
            self.assertEqual(marker.read_text(), MARKER)
            self.assertEqual(marker.stat().st_mode & 0o777, 0o600)
            with patch.object(observe, "docker", side_effect=replies), self.assertRaises(FileExistsError):
                observe.observe("mark", SERVER, PROCESS, "mysql", PORT, IP, MARKER)

    def test_valkey_checks_default_admin_and_persisted_client(self):
        with tempfile.TemporaryDirectory() as folder:
            raw = copy.deepcopy(self.raw)
            raw["Config"]["Image"] = "valkey/valkey:8.1"
            raw["HostConfig"]["PortBindings"] = {"6379/tcp": [{"HostIp": IP, "HostPort": str(PORT)}]}
            raw["Mounts"][0]["Destination"] = "/data"
            volume = json.dumps([{"Name": self.expected["volume"], "Mountpoint": folder}])
            names = f"orbit-process-{PROCESS}-{SERVER}\n"
            text = "user default on #" + "a" * 64 + " ~* +@all\n"
            text += "user client on #" + "b" * 64 + " ~proof:* -@all +get -@admin -@dangerous\n"
            Path(folder, "users.acl").write_text(text)
            with patch.object(observe, "docker", side_effect=[names, names, volume, json.dumps([raw])]):
                value = observe.observe("active", SERVER, PROCESS, "redis", PORT, IP, MARKER, "client")
                self.assertTrue(all(value.values()))


class FixtureTests(unittest.TestCase):
    def test_all_nine_fixtures_bind_real_source_steps_and_frozen_contract(self):
        root = Path(__file__).resolve().parents[2]
        flows = {flow["name"]: flow for path in (root / "workflows").glob("*.yaml")
                 if isinstance(flow := yaml.safe_load(path.read_text()), dict)}
        fixtures = [path for kind in ["database.create", "database_server.create", "database_server.destroy"]
                    for path in (root / "proof" / kind).glob("*.json")]
        self.assertEqual(len(fixtures), 9)
        for path in fixtures:
            with self.subTest(path=path):
                fixture = json.loads(path.read_text())
                flow = flows[fixture["workflow"]]
                self.assertEqual(fixture["kind"], path.parent.name)
                self.assertEqual(fixture["topology"], ["app-dev+database"])
                self.assertEqual(fixture["queue_timeout_seconds"], flow.get("queueTimeout"))
                self.assertEqual(fixture["request"]["operation"], "$.id")
                self.assertFalse(set(fixture) & {"secrets", "target_setup"})
                for call in [fixture["request"], *fixture.get("prepare", [])]:
                    self.assertEqual(call["argv"][0], "orbit")
                    self.assertIn("--json", call["argv"])
                    self.assertFalse(any("--password" in arg for arg in call["argv"]))
                for step in [*fixture["phases"], fixture["expect"]["outputs"]]:
                    job = next(job for job in flow["jobs"] if job["name"] == step["job"])
                    self.assertIn(step["step"], [entry["name"] for entry in job["steps"]])
                for hook in fixture.get("before_request", []):
                    self.assertTrue((root.parent / hook["argv"][2]).resolve().is_file())


if __name__ == "__main__":
    unittest.main()
