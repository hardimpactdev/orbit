"""One Grafana authentication observation with the existing execution copy on stdin."""

import base64
import json
import sys
import urllib.request

METRICS_INPUT = None


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, message, headers, url):
        return None


def verify(data):
    material = json.load(sys.stdin)
    password = material["password"]
    if not isinstance(password, str) or not password:
        raise ValueError("metrics.credentials_unverified")
    request = urllib.request.Request("http://" + data["address"] + ":3000/api/user", headers={
        "Authorization": "Basic " + base64.b64encode(("admin:" + password).encode()).decode(),
    })
    with urllib.request.build_opener(NoRedirect()).open(request, timeout=30) as response:
        if not 200 <= response.status < 300:
            raise ValueError("metrics.credentials_unverified")
    return {"ready": True}


if __name__ == "__main__":
    try:
        print(json.dumps(verify(METRICS_INPUT)))
    except (ValueError, KeyError, OSError):
        print('{"error_code":"metrics.credentials_unverified"}')
        sys.exit(1)
