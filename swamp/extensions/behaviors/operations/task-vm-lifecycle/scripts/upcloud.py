"""One UpCloud HTTPS request per placed native step; no waits or retries."""
import json
import os
import sys
import urllib.error
import urllib.request

ORIGIN = "https://api.upcloud.com/1.3"


def request(intent, stage):
    uuid = intent["provider_id"]
    method = "GET"
    path = f"server/{uuid}"
    body = None
    if stage == "action":
        action = intent["action"]
        if action == "delete":
            method = "DELETE"
            path += "?storages=1&backups=delete"
        else:
            method = "POST"
            path += f"/{action}"
            body = {"server": {}} if action == "start" else {
                "stop_server": {"stop_type": "soft", "timeout": "120"}
            }
    req = urllib.request.Request(
        ORIGIN + "/" + path, method=method,
        data=None if body is None else json.dumps(body).encode(),
        headers={"Authorization": "Bearer " + os.environ["ORBIT_UPCLOUD_TOKEN"], "Content-Type": "application/json", "Accept": "application/json"},
    )
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, req, fp, code, msg, headers, newurl):
            return None
    try:
        with urllib.request.build_opener(NoRedirect).open(req, timeout=60) as response:
            raw = response.read(1048577)
            if len(raw) > 1048576:
                raise ValueError("oversize")
            if not raw.strip() or stage != "observe":
                return {}
            server = json.loads(raw)["server"]
            return {"server": {key: server[key] for key in (
                "uuid", "title", "hostname", "state", "labels", "storage_devices"
            )}}
    except urllib.error.HTTPError as error:
        if error.code == 404 and (stage == "observe" or intent["action"] == "delete"):
            return {}
        raise


def main():
    try:
        intent = json.loads(os.environ["ORBIT_PROVIDER_INTENT"])
        value = request(intent, sys.argv[1])
        print(json.dumps(value))
    except (OSError, ValueError, KeyError, IndexError, TypeError, urllib.error.URLError):
        print("task_vm.upcloud_failed", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
