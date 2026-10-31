import json
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
    os.environ["ORBIT_PI_URL"] + "/sessions",
    data=os.environ["ORBIT_PI_BODY"].encode(),
    headers={"Authorization": "Bearer " + token, "Content-Type": "application/json"},
)
with opener.open(request, timeout=30) as response:
    response.read(65536)
print(json.dumps({"created": True, "session_id": value["session_id"]}))
