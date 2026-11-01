"""Legacy closure parity for a disposable reproduction of the two adopted servers."""

import argparse
import json
import os
import shlex
import subprocess
from pathlib import Path


def require(value, message):
    if not value:
        raise AssertionError(message)


class Topology:
    def __init__(self):
        self.prefix = shlex.split(os.environ["PROOF_EXEC"])
        require(bool(self.prefix), "Missing topology executor")

    def execute(self, node, argv):
        reply = subprocess.run([*self.prefix, node, "--timeout=1800", "--argv=" + json.dumps(argv)],
                               capture_output=True, text=True, timeout=1830, check=False)
        try:
            value = json.loads(reply.stdout)
        except ValueError:
            raise AssertionError("Topology returned no public JSON") from None
        require(isinstance(value, dict), "Topology returned a non-object")
        return reply.returncode, value

    def cli(self, *argv):
        return self.execute("operator", ["orbit", *argv, "--json"])

    def observe(self, action, slug, process_id, ip, run, *operations):
        body = Path(__file__).with_name("observe.py").read_text()
        code, value = self.execute("app-dev", ["sudo", "-n", "python3", "-I", "-c", body, action,
                                               slug, str(process_id), ip, run, *map(str, operations)])
        keys = {"owned", "running", "private_binding", "data_mount", "acl_matches", "anonymous_matches",
                "marker_retained", "policy_unchanged", "scratch_absent"}
        require(code == 0 and set(value) == keys and all(v is True for v in value.values()),
                "Workload state differs")


def success(reply):
    code, value = reply
    require(code == 0, "Public CLI failed")
    return value


def private_free(value):
    if isinstance(value, dict):
        require(not ({"password", "root_password", "private_material", "acl", "payload"} & set(value)),
                "Public result exposes private material")
        for child in value.values():
            private_free(child)
    elif isinstance(value, list):
        for child in value:
            private_free(child)


def server(topology, slug, server_id, process_id, node_id):
    value = success(topology.cli("database:server:show", slug))
    expected = {"id": server_id, "slug": slug, "driver": "redis", "process_id": process_id,
                "node_id": node_id, "port": 63983, "status": "active", "databases_count": 0, "databases": []}
    require(all(value.get(key) == item and type(value.get(key)) is type(item) for key, item in expected.items()),
            "Server identity or state changed")
    private_free(value)


def operation(reply, server_id, expected_id=None):
    value = success(reply)
    require(type(value.get("id")) is int and value["id"] > 0, "Missing Operation identity")
    require(expected_id is None or value["id"] == expected_id, "Wrong proof Operation")
    require(value.get("kind") == "database_server.close" and value.get("status") == "succeeded"
            and value.get("target_type") == "database_server" and value.get("target_id") == server_id,
            "Wrong closure identity or terminal")
    require(value.get("input", {}).get("server_id") == server_id
            and value["input"].get("driver") == "redis" and value.get("result") == {"backend": "active"},
            "Wrong closure intent or result")
    private_free(value["input"])
    return value


def run(topology, action, slug, server_id, process_id, node_id, ip, run_id, operation_id=None, outputs=None):
    require(all(type(x) is int and x > 0 for x in [server_id, process_id, node_id]), "Invalid resource identity")
    require(slug == "proof-close-" + run_id, "Not an owned disposable server")
    server(topology, slug, server_id, process_id, node_id)
    code, absent = topology.cli("database:show", "proof-close-client-" + run_id)
    require(code != 0 and absent.get("error", {}).get("code") == "http.404",
            "Adopted passwordless connection remains or absence is unproved")
    if action == "before":
        topology.observe("before", slug, process_id, ip, run_id)
        return ["adopted-server-open-before-close"]
    require(type(operation_id) is int and operation_id > 0, "Missing proof Operation")
    proof = operation(topology.cli("operation:show", str(operation_id)), server_id, operation_id)
    require(isinstance(outputs, dict) and type(outputs.get("exitCode")) is int and outputs["exitCode"] == 0
            and json.loads(outputs["stdout"]) == proof["result"], "Native/public result differs")
    topology.observe("closed", slug, process_id, ip, run_id, operation_id)
    repeat = operation(topology.cli("database:server:close", slug), server_id)
    require(repeat["id"] != operation_id, "Repeat reused the original Operation")
    server(topology, slug, server_id, process_id, node_id)
    topology.observe("repeat", slug, process_id, ip, run_id, operation_id, repeat["id"])
    return ["exact-native-public-closure", "anonymous-refused-saved-hashed-acl",
            "same-server-process-volume-marker", "already-closed-preserves-policy", "scratch-cleaned"]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("action", choices=["before", "parity"])
    parser.add_argument("slug")
    for name in ["server-id", "process-id", "node-id"]:
        parser.add_argument("--" + name, type=int, required=True)
    parser.add_argument("--ip", required=True)
    args = vars(parser.parse_args())
    try:
        cases = run(Topology(), **args, run_id=os.environ["PROOF_RUN"],
                    operation_id=int(os.environ["PROOF_OPERATION_ID"]) if args["action"] == "parity" else None,
                    outputs=json.loads(Path(os.environ["PROOF_OUTPUTS"]).read_text()) if args["action"] == "parity" else None)
    except (AssertionError, KeyError, OSError, ValueError, TypeError, subprocess.TimeoutExpired):
        print('{"error":"Database closure parity failed"}')
        return 1
    print(json.dumps({"passed": cases}))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
