"""One local Reverb apply through official SSH; private credential arrives on stdin."""

import http.client
import json
import sys
import urllib.error
import urllib.request
import uuid


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def apply(intent, credential):
    if (
        not credential
        or not isinstance(intent.get("port"), int)
        or not 1 <= intent["port"] <= 65535
    ):
        return {"error_code": "websocket.registry_invalid"}
    body = intent["request"]
    request = urllib.request.Request(
        f"http://127.0.0.1:{intent['port']}/internal/orbit/registry/apply",
        data=json.dumps(body).encode(),
        method="POST",
        headers={
            "Authorization": "Bearer " + credential,
            "Content-Type": "application/json",
        },
    )
    try:
        response = urllib.request.build_opener(
            urllib.request.ProxyHandler({}), NoRedirect()
        ).open(request, timeout=10)
    except urllib.error.HTTPError as error:
        with error:
            try:
                code = json.loads(error.read(4097)).get("error_code")
            except (ValueError, AttributeError):
                code = None
        allowed = {
            "websocket.registry_pending",
            "websocket.registry_superseded",
            "websocket.registry_mismatch",
            "websocket.registry_unauthorized",
            "websocket.registry_invalid",
        }
        return {
            "error_code": code if code in allowed else "websocket.registry_unavailable"
        }
    with response:
        result = json.loads(response.read(4097))
    runtime = result.get("runtime", {})
    boot = str(uuid.UUID(runtime["boot_id"]))
    if (
        result.get("outcome") != "succeeded"
        or result.get("data") != {"backend": "applied"}
        or runtime.get("process_id") != body["process_id"]
        or runtime.get("epoch") != body["epoch"]
        or type(runtime.get("revision")) is not int
        or runtime["revision"] < body["revision"]
        or runtime.get("state") != "serving"
    ):
        return {"error_code": "websocket.registry_mismatch"}
    return {
        "process_id": body["process_id"],
        "boot_id": boot,
        "epoch": body["epoch"],
        "revision": runtime["revision"],
    }


def main():
    try:
        result = apply(json.loads(sys.argv[1]), sys.stdin.read(16385))
    except (
        OSError,
        ValueError,
        KeyError,
        TypeError,
        AttributeError,
        http.client.HTTPException,
    ):
        result = {"error_code": "websocket.registry_unavailable"}
    except Exception:  # noqa: BLE001 - Provider exception values must never reach native logs.
        result = {"error_code": "websocket.registry_unavailable"}
    print(json.dumps(result, separators=(",", ":")))
    if "error_code" in result:
        print("Reverb registry application did not confirm.", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
