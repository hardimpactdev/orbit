import json
import math
import os
import urllib.request

value = json.loads(os.environ["ORBIT_TURN_INPUT"])


class RefuseRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *_args, **_kwargs):
        raise RuntimeError("tasks.pi_redirect_refused")


opener = urllib.request.build_opener(RefuseRedirect)
token = os.environ.pop("ORBIT_PI_TOKEN")
if len(token) < 32:
    raise SystemExit("tasks.pi_credential_unavailable")

request = urllib.request.Request(
    os.environ["ORBIT_PI_URL"]
    + "/sessions/"
    + value["session_id"]
    + "/stream?max_bytes=1048576",
    headers={"Authorization": "Bearer " + token},
)
key = "operation-" + str(value["operation_id"])
with opener.open(request, timeout=1800) as response:
    for line in response:
        if len(line) > 1048576:
            raise SystemExit("tasks.pi_event_too_large")
        event = json.loads(line)
        if event.get("turnId") == key and event.get("state") in ["done", "failed"]:
            usage = {
                name: event.get("usage", {}).get(name, 0)
                for name in [
                    "input",
                    "output",
                    "cacheRead",
                    "cacheWrite",
                    "total",
                    "calls",
                    "peakContext",
                ]
            }
            if any(
                type(number) not in (int, float)
                or not math.isfinite(number)
                or number < 0
                for number in usage.values()
            ):
                raise SystemExit("tasks.pi_usage_invalid")
            print(json.dumps({"state": event["state"], "turnId": key, "usage": usage}))
            break
    else:
        raise SystemExit("tasks.pi_completion_unconfirmed")
