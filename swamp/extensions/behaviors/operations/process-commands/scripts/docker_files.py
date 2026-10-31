"""Docker request construction. Native steps own creation, replacement and recovery."""

import http.client
import ipaddress
import json
import re
import socket

from process_common import Refused

ENGINE_SOCKET = "/var/run/docker.sock"


class EngineConnection(http.client.HTTPConnection):
    def __init__(self):
        super().__init__("localhost", timeout=900)

    def connect(self):
        self.sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        self.sock.settimeout(self.timeout)
        self.sock.connect(ENGINE_SOCKET)


def request_create(name, body):
    connection = EngineConnection()
    try:
        connection.request(
            "POST",
            "/containers/create?name=" + name,
            body=json.dumps(body).encode(),
            headers={"Content-Type": "application/json"},
        )
        response = connection.getresponse()
        data = response.read(65537)
        if response.status != 201:
            raise Refused("process.create_failed")
        if len(data) > 65536:
            raise Refused("process.candidate_invalid")
        value = json.loads(data)
        if not isinstance(value, dict) or not re.fullmatch(
            "[a-f0-9]{64}", value.get("Id", "")
        ):
            raise Refused("process.candidate_invalid")
    finally:
        connection.close()


def absolute(value):
    return (
        isinstance(value, str)
        and value.startswith("/")
        and not any(ord(char) < 32 or ord(char) == 127 for char in value)
        and not any(part in (".", "..") for part in value.split("/"))
    )


def ports(values):
    result = {}
    for value in values:
        if not isinstance(value, str):
            raise Refused("process.invalid")
        binding, slash, protocol = value.partition("/")
        if slash and protocol not in ("tcp", "udp", "sctp"):
            raise Refused("process.invalid")
        host = ""
        if binding.startswith("["):
            match = re.fullmatch(r"\[([a-fA-F0-9:]+)\]:(.*)", binding)
            if match is None:
                raise Refused("process.invalid")
            host, binding = match.groups()
            try:
                ipaddress.IPv6Address(host)
            except ValueError:
                raise Refused("process.invalid") from None
        parts = binding.split(":")
        if len(parts) == 3 and not host:
            host = parts.pop(0)
            try:
                ipaddress.IPv4Address(host)
            except ValueError:
                raise Refused("process.invalid") from None
        if not 1 <= len(parts) <= 2:
            raise Refused("process.invalid")
        container_port = parts.pop()
        host_port = parts.pop() if parts else ""
        if (
            not re.fullmatch("[0-9]+", container_port)
            or not 1 <= int(container_port) <= 65535
            or host_port
            and (
                not re.fullmatch("[0-9]+", host_port)
                or not 1 <= int(host_port) <= 65535
            )
        ):
            raise Refused("process.invalid")
        result.setdefault(container_port + "/" + (protocol or "tcp"), []).append(
            {"HostIp": host, "HostPort": host_port}
        )
    return result


def definition(data):
    value = data.get("definition")
    keys = {
        "image",
        "command",
        "restart",
        "working_directory",
        "ports",
        "mounts",
        "environment",
    }
    if not isinstance(value, dict) or set(value) != keys:
        raise Refused("process.invalid")
    if (
        not isinstance(value["image"], str)
        or not re.fullmatch("[A-Za-z0-9][A-Za-z0-9._/:@-]*", value["image"])
        or value["restart"] not in ("no", "on-failure", "always", "unless-stopped")
        or not isinstance(value["command"], list)
        or not value["command"]
        or len(value["command"]) > 4096
        or any(not isinstance(arg, str) or "\0" in arg for arg in value["command"])
        or value["working_directory"] is not None
        and not absolute(value["working_directory"])
        or not isinstance(value["mounts"], list)
        or len(value["mounts"]) > 4096
        or not isinstance(value["ports"], list)
        or len(value["ports"]) > 4096
        or not isinstance(value["environment"], dict)
    ):
        raise Refused("process.invalid")
    for key, item in value["environment"].items():
        if (
            not re.fullmatch("[A-Za-z_][A-Za-z0-9_]*", key)
            or not isinstance(item, str)
            or any(char in item for char in "\0\r\n")
        ):
            raise Refused("process.invalid")
    for mount in value["mounts"]:
        if (
            not isinstance(mount, dict)
            or set(mount) != {"source", "target", "read_only"}
            or not isinstance(mount["source"], str)
            or not mount["source"]
            or any(ord(char) < 32 or char in ",=" for char in mount["source"])
            or not absolute(mount["target"])
            or "," in mount["target"]
            or "=" in mount["target"]
            or type(mount["read_only"]) is not bool
        ):
            raise Refused("process.invalid")
    ports(value["ports"])
    return value


def create_body(value, admitted, private):
    binding = ports(private["ports"])
    return {
        "Image": private["image"],
        "Cmd": private["command"],
        "Env": [key + "=" + item for key, item in private["environment"].items()],
        **(
            {"WorkingDir": private["working_directory"]}
            if private["working_directory"] is not None
            else {}
        ),
        "Labels": {
            "orbit.managed": "true",
            "orbit.container.kind": "process",
            "orbit.process.id": str(value["process_id"]),
            "orbit.process.spec": admitted["spec"],
        },
        "ExposedPorts": {key: {} for key in binding},
        "HostConfig": {
            "RestartPolicy": {"Name": private["restart"], "MaximumRetryCount": 0},
            "PortBindings": binding,
            "Mounts": [
                {
                    "Type": "bind" if mount["source"].startswith("/") else "volume",
                    "Source": mount["source"],
                    "Target": mount["target"],
                    "ReadOnly": mount["read_only"],
                }
                for mount in private["mounts"]
            ],
        },
    }
