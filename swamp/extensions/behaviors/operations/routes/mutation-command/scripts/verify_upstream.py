import json
import sys

from firewall_snapshot import firewall

payload = json.load(sys.stdin)
observed = firewall({"stored": False, "expected": [], "exclusive_families": []})
if observed["backend"] != "active" or not any(
    all(actual.get(key) == value for key, value in expected.items())
    for actual in observed["live"]
    for expected in payload["expected"]
):
    raise RuntimeError("route.upstream_firewall_mismatch")
print('{"ready":true}')
