"""Docker creation gap: exact argv, working directory, labels and private stdin.

cfgmgmt/docker_container has a single command string and puts env in argv.
This existing shipped asset runs in memory through SSH exec, never from a Node file.
"""

import http.client
import json
import socket
import sys
from urllib.parse import quote


class Engine(http.client.HTTPConnection):
    def __init__(self):
        super().__init__("localhost", timeout=15)

    def connect(self):
        self.sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        self.sock.settimeout(self.timeout)
        self.sock.connect("/var/run/docker.sock")


def request(method, path, body=None, accepted=(200,)):
    connection = Engine()
    try:
        connection.request(
            method,
            path,
            body=None if body is None else json.dumps(body),
            headers={"Content-Type": "application/json"},
        )
        response = connection.getresponse()
        data = response.read()
        if response.status not in accepted:
            raise RuntimeError("Docker creation request failed")
        return json.loads(data) if data else None
    finally:
        connection.close()


def bindings(values):
    result = {}
    for value in values:
        binding, _, protocol = value.partition("/")
        parts = binding.rsplit(":", 2)
        container = parts.pop()
        port = parts.pop() if parts else ""
        host = parts.pop().strip("[]") if parts else ""
        result.setdefault(container + "/" + (protocol or "tcp"), []).append(
            {"HostIp": host, "HostPort": port}
        )
    return result


def create(installation, environment):
    definition = installation["definition"]
    path = "/containers/" + quote(installation["name"], safe="")
    current = request("GET", path + "/json", accepted=(200, 404))
    # The spec hash skips finished creation on reruns; it proves no ownership.
    if (
        current
        and current.get("Config", {}).get("Labels", {}).get("orbit.process.spec")
        == installation["spec"]
    ):
        return
    if current and "Config" in current:
        request("DELETE", path + "?force=true&v=false", accepted=(204,))
    ports = bindings(definition["ports"])
    body = {
        "Image": definition["image"],
        "Cmd": definition["command"],
        "Env": [key + "=" + value for key, value in environment.items()],
        "WorkingDir": definition["working_directory"] or "",
        "Labels": {
            "orbit.managed": "true",
            "orbit.container.kind": "process",
            "orbit.process.id": str(installation["process_id"]),
            "orbit.process.spec": installation["spec"],
        },
        "ExposedPorts": {key: {} for key in ports},
        "HostConfig": {
            "RestartPolicy": {"Name": definition["restart"], "MaximumRetryCount": 0},
            "PortBindings": ports,
            "Mounts": [
                {
                    "Type": "bind" if mount["source"].startswith("/") else "volume",
                    "Source": mount["source"],
                    "Target": mount["target"],
                    "ReadOnly": mount["read_only"],
                }
                for mount in definition["mounts"]
            ],
        },
    }
    request(
        "POST",
        "/containers/create?name=" + quote(installation["name"], safe=""),
        body,
        (201,),
    )


if __name__ == "__main__":
    try:
        create(
            json.loads(sys.argv[1])["installation"],
            json.loads(sys.stdin.read() or "{}"),
        )
    except (OSError, ValueError, KeyError, RuntimeError, http.client.HTTPException):
        sys.exit("process.create_failed")
