import json
import os
import urllib.request

v = json.loads(os.environ["ORBIT_EXPORTER_INTENT"])
try:
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
    with opener.open(
        "http://" + v["address"] + ":" + v["port"] + "/metrics", timeout=30
    ) as response:
        if response.status != 200 or not response.read(1):
            raise ValueError()
except Exception:
    raise SystemExit("metrics.exporter_unverified") from None
print('{"verified":true}')
