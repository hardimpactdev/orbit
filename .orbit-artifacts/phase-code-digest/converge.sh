#!/bin/bash
# Converge nested app-dev (Node 3) through the nested Gateway's API with the default scope and print its phases and seconds.
set -euo pipefail
api() { env -u HTTPS_PROXY -u https_proxy -u HTTP_PROXY -u http_proxy curl -sSk -m 80 -H 'Host: gateway.orbit' -H 'Accept: application/json' "$@"; }
started=$(date +%s)
id=$(api --fail -X POST https://10.45.0.1/api/v1/nodes/3/converge -H 'Content-Type: application/json' -d '{"base":false}' | python3 -c 'import json,sys; print(json.load(sys.stdin)["data"]["id"])')
while :; do
  body=$(api --fail "https://10.45.0.1/api/v1/operations/$id")
  status=$(printf '%s' "$body" | python3 -c 'import json,sys; print(json.load(sys.stdin)["data"]["status"])')
  case "$status" in succeeded|failed|cancelled|interrupted) break ;; esac
  sleep 3
done
printf '%s' "$body" | python3 -c '
import json,sys
d=json.load(sys.stdin)["data"]; r=d.get("result") or {}
print("operation", d["id"], d["status"], "wall_seconds", '"$(( $(date +%s) - started ))"')
ph=r.get("phases")
if isinstance(ph, dict): ph=[dict(name=k, **(v if isinstance(v, dict) else {"value": v})) for k,v in ph.items()]
for p in ph or []: print(" ", {k: p.get(k) for k in ("name","phase","status","outcome","elapsed_seconds","changed") if k in p})
print("changed", r.get("changed"))'
