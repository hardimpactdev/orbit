"""Closure checker regressions; no infrastructure or live Operations."""

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


parity, observer = load("parity"), load("observe")
SLUG, IP, RUN = "proof-close-run", "10.44.0.7", "run"
SERVER, PROCESS, NODE, OP = 17, 23, 7, 31
ACL = "user default on #" + "a" * 64 + " ~* &* +@all\n"


class FakeTopology(parity.Topology):
    def __init__(self):
        self.proof = {"id": OP, "kind": "database_server.close", "status": "succeeded",
                      "target_type": "database_server", "target_id": SERVER,
                      "input": {"server_id": SERVER, "driver": "redis"}, "result": {"backend": "active"}}
        self.record = {"id": SERVER, "slug": SLUG, "driver": "redis", "node_id": NODE,
                       "process_id": PROCESS, "port": 63983, "status": "active", "databases_count": 0, "databases": []}
        self.observations, self.calls = {}, []
        self.absence = {"error": {"code": "http.404"}}
        self.repeat_id = OP + 1

    def execute(self, node, argv):
        self.calls.append((node, argv))
        if node == "app-dev":
            keys = ["owned", "running", "private_binding", "data_mount", "acl_matches", "anonymous_matches",
                    "marker_retained", "policy_unchanged", "scratch_absent"]
            return 0, {key: self.observations.get(key, True) for key in keys}
        command = argv[1]
        if command == "database:server:show":
            return 0, {**self.record, "request_id": "changing-transport-id"}
        if command == "database:show":
            return 1, self.absence
        if command == "operation:show":
            return 0, self.proof
        if command == "database:server:close":
            return 0, {**self.proof, "id": self.repeat_id}
        raise AssertionError("Unexpected proof command")


def check(topology, action="parity", outputs=None):
    return parity.run(topology, action, SLUG, SERVER, PROCESS, NODE, IP, RUN, OP,
                      {"exitCode": 0, "stdout": json.dumps(topology.proof["result"])} if outputs is None else outputs)


def raw_container():
    return {"Config": {"Labels": {"orbit.managed": "true", "orbit.container.kind": "process",
                                  "orbit.process.id": str(PROCESS)}, "Image": "valkey/valkey:8.1"},
            "HostConfig": {"PortBindings": {"6379/tcp": [{"HostIp": IP, "HostPort": "63983"}]}},
            "Mounts": [{"Destination": "/data", "Type": "volume", "Name": "orbit-" + SLUG + "-data", "RW": True}],
            "State": {"Status": "running"}}


class CheckerTests(unittest.TestCase):
    def test_precondition_and_full_parity(self):
        topology = FakeTopology()
        self.assertEqual(check(topology, "before"), ["adopted-server-open-before-close"])
        self.assertIn("already-closed-preserves-policy", check(topology))
        observed = [call for node, call in topology.calls if node == "app-dev"]
        self.assertEqual([call[6] for call in observed], ["before", "closed", "repeat"])

    def test_wrong_operation_identity_intent_or_terminal_fails(self):
        for key, value in [("id", OP + 8), ("status", "running"), ("kind", "database_server.create"),
                           ("target_id", SERVER + 1), ("target_type", "process"),
                           ("input", {"server_id": SERVER + 1, "driver": "redis"}),
                           ("input", {"server_id": SERVER, "driver": "redis", "password": "private"})]:
            topology = FakeTopology()
            topology.proof[key] = value
            with self.subTest(key=key), self.assertRaises(AssertionError):
                check(topology)

    def test_server_changes_and_unproved_connection_absence_fail(self):
        for key, value in [("process_id", PROCESS + 1), ("node_id", NODE + 1), ("databases_count", True),
                           ("databases", [{"id": 3}]), ("password", "private")]:
            topology = FakeTopology()
            topology.record[key] = value
            with self.subTest(key=key), self.assertRaises(AssertionError):
                check(topology)
        topology = FakeTopology()
        topology.absence = {"error": {"code": "gateway.unavailable"}}
        with self.assertRaises(AssertionError):
            check(topology)

    def test_wrong_native_output_and_nonfresh_repeat_fail(self):
        for outputs in [{"exitCode": False, "stdout": '{}'}, {"exitCode": 0, "stdout": '{}'}]:
            with self.subTest(outputs=outputs), self.assertRaises(AssertionError):
                check(FakeTopology(), outputs=outputs)
        topology = FakeTopology()
        topology.repeat_id = OP
        with self.assertRaises(AssertionError):
            check(topology)

    def test_each_workload_invariant_must_be_true_boolean(self):
        for key in ["owned", "running", "private_binding", "data_mount", "acl_matches", "anonymous_matches",
                    "marker_retained", "policy_unchanged", "scratch_absent"]:
            for value in [False, 1]:
                topology = FakeTopology()
                topology.observations[key] = value
                with self.subTest(key=key, value=value), self.assertRaises(AssertionError):
                    check(topology)

    def test_transport_is_bounded_and_private_errors_are_not_echoed(self):
        with patch.dict("os.environ", {"PROOF_EXEC": "bin/e2e-topology exec TASK-proof"}), \
                patch.object(subprocess, "run", return_value=subprocess.CompletedProcess([], 0, "{}", "")) as call:
            parity.Topology().cli("database:server:show", SLUG)
            self.assertIn("--timeout=1800", call.call_args.args[0])
            self.assertEqual(call.call_args.kwargs["timeout"], 1830)
        with patch.dict("os.environ", {"PROOF_EXEC": "bin/e2e-topology exec TASK-proof"}), \
                patch.object(subprocess, "run", return_value=subprocess.CompletedProcess([], 1, "private", "private")):
            with self.assertRaisesRegex(AssertionError, "no public JSON") as error:
                parity.Topology().cli("database:server:show", SLUG)
            self.assertNotIn("private", str(error.exception))


class ObserverTests(unittest.TestCase):
    def test_acl_requires_correct_open_or_single_hashed_closed_default(self):
        self.assertTrue(observer.default_policy("user default on nopass ~* &* +@all\n", True))
        self.assertTrue(observer.default_policy(ACL, False))
        for acl in [ACL.replace("on", "off"), ACL.replace("#" + "a" * 64, ">private"),
                    ACL.replace("#" + "a" * 64, "#short"), ACL + "user stranger on nopass\n",
                    ACL.replace("~*", "nopass ~*"), ACL.replace("+@all", "-@all"),
                    ACL.replace("~*", "~restricted:*"), ACL.replace("&*", "&restricted:*"),
                    ACL.replace("+@all", "+@all -config")]:
            with self.subTest(acl=acl):
                self.assertFalse(observer.default_policy(acl, False))
        self.assertFalse(observer.default_policy("user default on nopass +ping", True))

    def test_container_ownership_mount_and_private_publication(self):
        raw = raw_container()
        self.assertTrue(all(observer.container_matches(raw, PROCESS, "orbit-" + SLUG + "-data", IP).values()))
        for address in ["0.0.0.0", "203.0.113.9"]:
            changed = copy.deepcopy(raw)
            changed["HostConfig"]["PortBindings"]["6379/tcp"].append({"HostIp": address, "HostPort": "63983"})
            self.assertFalse(observer.container_matches(changed, PROCESS, "orbit-" + SLUG + "-data", IP)["private_binding"])
        self.assertFalse(observer.container_matches(raw, PROCESS + 1, "orbit-" + SLUG + "-data", IP)["owned"])
        self.assertFalse(observer.container_matches(raw, PROCESS, "foreign", IP)["data_mount"])

    def test_saved_policy_and_real_marker_survive_repeat_without_private_output(self):
        with tempfile.TemporaryDirectory() as home:
            root = Path(home)
            acl = root / "users.acl"
            acl.write_text("user default on nopass ~* &* +@all\n")

            def docker(*argv):
                if argv[0] == "inspect":
                    return json.dumps([raw_container()])
                if argv[:2] == ("volume", "inspect"):
                    return json.dumps([{"Name": "orbit-" + SLUG + "-data", "Mountpoint": home}])
                if argv[0] == "exec":
                    return "PONG\n" if "nopass" in acl.read_text() else "NOAUTH Authentication required.\n"
                raise AssertionError("Unexpected Docker command")

            with patch.object(observer, "docker", side_effect=docker):
                self.assertTrue(all(observer.observe("before", SLUG, PROCESS, IP, RUN, []).values()))
                with self.assertRaises(FileExistsError):
                    observer.observe("before", SLUG, PROCESS, IP, RUN, [])
                acl.write_text(ACL)
                state = observer.observe("closed", SLUG, PROCESS, IP, RUN, [OP])
                self.assertTrue(all(state.values()))
                self.assertNotIn("a" * 64, json.dumps(state))
                self.assertTrue(all(observer.observe("repeat", SLUG, PROCESS, IP, RUN, [OP, OP + 1]).values()))
                acl.write_text(ACL.replace("a" * 64, "b" * 64))
                self.assertFalse(observer.observe("repeat", SLUG, PROCESS, IP, RUN, [OP])["policy_unchanged"])
                self.assertEqual((root / (".orbit-proof-close-" + RUN + ".closed")).stat().st_mode & 0o777, 0o600)

    def test_shared_names_and_boolean_ids_are_refused_before_docker(self):
        with patch.object(observer, "docker") as docker:
            for slug, identity in [("beast-valkey", PROCESS), (SLUG, True)]:
                with self.assertRaises(AssertionError):
                    observer.observe("before", slug, identity, IP, RUN, [])
            docker.assert_not_called()


class FixtureTests(unittest.TestCase):
    def test_source_bindings_and_disposable_prepare_chain(self):
        root = Path(__file__).resolve().parents[2]
        value = json.loads((root / "proof/database_server.close.json").read_text())
        flow = yaml.safe_load((root / "workflows/workflow-operation-database-server-close.yaml").read_text())
        self.assertEqual(value["workflow"], flow["name"])
        self.assertEqual(value["topology"], ["app-dev+database"])
        self.assertEqual(value["queue_timeout_seconds"], flow.get("queueTimeout"))
        self.assertIs(type(value["budget_seconds"]), int)
        self.assertIs(type(value["queue_timeout_seconds"]), int)
        self.assertLess(0, value["queue_timeout_seconds"])
        self.assertLess(value["queue_timeout_seconds"], value["budget_seconds"])
        self.assertTrue(all(type(phase["budget_seconds"]) is int and
                            0 < phase["budget_seconds"] <= value["budget_seconds"]
                            for phase in value["phases"]))
        self.assertEqual(value["request"]["operation"], "$.id")
        self.assertEqual([call["argv"][1] for call in value["prepare"]],
                         ["process:create", "database:create", "database:server:create", "database:destroy"])
        self.assertEqual(value["prepare"][0]["capture"]["process_id"], "$.target_id")
        for call in [*value["prepare"], value["request"]]:
            self.assertIn("--json", call["argv"])
            self.assertFalse(any("password" in item or "beast" in item or "services" in item for item in call["argv"]))
        for selected in [*value["phases"], value["expect"]["outputs"]]:
            job = next(j for j in flow["jobs"] if j["name"] == selected["job"])
            self.assertIn(selected["step"], [s["name"] for s in job["steps"]])
        for call in [*value["before_request"], {"argv": value["parity"]["command"]}]:
            self.assertTrue((root.parent / call["argv"][2]).is_file())


if __name__ == "__main__":
    unittest.main()
