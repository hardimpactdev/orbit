import base64
import os
import urllib.error
import urllib.request

password = os.environ.pop("ORBIT_GRAFANA_PASSWORD")
address = os.environ["ORBIT_METRICS_ADDRESS"]
request = urllib.request.Request(
    "http://" + address + ":3000/api/user",
    headers={
        "Authorization": "Basic "
        + base64.b64encode(("admin:" + password).encode()).decode()
    },
)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


try:
    with urllib.request.build_opener(
        urllib.request.ProxyHandler({}), NoRedirect()
    ).open(request, timeout=30) as response:
        if response.status != 200:
            raise ValueError()
except Exception:
    raise SystemExit("metrics.credentials_unverified") from None
print('{"verified":true}')
