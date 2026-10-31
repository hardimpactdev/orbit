"""Fixed UpCloud API and owned public records; no retries or diagnostic bodies."""

import http.client
import ipaddress
import json
import os
import re
import urllib.error
import urllib.request
import uuid

ORIGIN = "https://api.upcloud.com/1.3/"
LIMIT = 4 * 1024 * 1024


class Refused(Exception):
    pass


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, response, code, message, headers, new_url):
        return None


def identifier(value):
    if not isinstance(value, str) or str(uuid.UUID(value)) != value:
        raise Refused()
    return value


def intent():
    value = json.loads(os.environ["ORBIT_UPCLOUD_INTENT"])
    vm_id = value["task_vm_id"]
    if (
        type(vm_id) is not int
        or vm_id < 1
        or value["name"] != f"tvm-{vm_id}"
        or value["provider"] != "upcloud"
        or value["node_id"] != value["gateway_node_id"]
        or type(value["gateway_node_id"]) is not int
        or value["gateway_node_id"] < 1
        or identifier(value["image_fingerprint"]) != value["upcloud"]["source_template"]
    ):
        raise Refused()
    if value["provider_id"] is not None:
        identifier(value["provider_id"])
    return value


def request(method, path, body=None):
    token = os.environ["ORBIT_UPCLOUD_TOKEN"]
    if re.fullmatch(r"ucat_[A-Za-z0-9_-]+", token) is None:
        raise Refused()
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())
    headers = {"Authorization": "Bearer " + token, "Accept": "application/json"}
    data = None
    if body is not None:
        data = json.dumps(body).encode()
        headers["Content-Type"] = "application/json"
    call = urllib.request.Request(
        ORIGIN + path, data=data, headers=headers, method=method
    )
    try:
        with opener.open(call, timeout=60) as response:
            if not 200 <= response.status < 300:
                raise Refused()
            raw = response.read(LIMIT + 1)
            if len(raw) > LIMIT:
                raise Refused()
    except urllib.error.HTTPError as error:
        error.close()
        raise Refused() from None
    except (
        urllib.error.URLError,
        TimeoutError,
        ConnectionError,
        http.client.HTTPException,
    ):
        raise Refused() from None
    result = json.loads(raw) if raw.strip() else {}
    if not isinstance(result, dict):
        raise Refused()
    return result


def inventory(value):
    rows = request("GET", "server?label=orbit-task-vm")["servers"]["server"]
    if not isinstance(rows, list) or len(rows) > 4096:
        raise Refused()
    for row in rows:
        identifier(row["uuid"])
        if not isinstance(row["title"], str):
            raise Refused()
    matches = [row for row in rows if row["title"] == value["name"]]
    if len(matches) > 1:
        raise Refused()
    expected = value["provider_id"]
    if expected is not None and any(row["uuid"] != expected for row in matches):
        raise Refused()
    return {
        "count": len(rows),
        "existing_id": expected or (matches[0]["uuid"] if matches else ""),
    }


def owned(value, server_id):
    identifier(server_id)
    if value["provider_id"] is not None and server_id != value["provider_id"]:
        raise Refused()
    server = request("GET", "server/" + server_id)["server"]
    labels = [row for row in server["labels"]["label"] if row["key"] == "orbit-task-vm"]
    if (
        server["uuid"] != server_id
        or server["title"] != value["name"]
        or server["hostname"] != value["name"]
        or labels != [{"key": "orbit-task-vm", "value": str(value["task_vm_id"])}]
        or server["state"] not in ("started", "stopped", "maintenance")
    ):
        raise Refused()
    return server


def ready(value, server):
    addresses = [
        row["address"]
        for row in server["ip_addresses"]["ip_address"]
        if row["access"] == "public" and row["family"] == "IPv4"
    ]
    if server["state"] != "started" or len(addresses) != 1:
        raise Refused()
    address = ipaddress.IPv4Address(addresses[0])
    if not address.is_global:
        raise Refused()
    return {
        "provider_id": server["uuid"],
        "address": str(address),
        "ssh_host_key": value["provider_host_public_key"],
        "image_fingerprint": value["image_fingerprint"],
        "running": True,
        "settled": True,
    }


def run(action):
    try:
        print(json.dumps(action(), separators=(",", ":")))
    except Exception:  # noqa: BLE001 -- Fixed effect boundary drops credentials and provider diagnostics.
        print('{"error_code":"task_vm.provider_create_refused"}')
        raise SystemExit(1) from None
