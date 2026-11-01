"""Use the existing mint-only Gateway primitive; expose descriptor names only."""
import json
import os
import ssl
import urllib.request


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


operation = os.environ["ORBIT_OPERATION_ID"]
if not operation.isdecimal() or int(operation) < 1:
    raise ValueError("instance.git_mint_invalid")
request = urllib.request.Request("https://gateway.orbit/api/v1/direct-operations/" + operation + "/github-token",
                                 data=json.dumps({"run_id": os.environ["ORBIT_RUN_ID"]}).encode(),
                                 headers={"Content-Type": "application/json", "Accept": "application/json"}, method="POST")
context = ssl.create_default_context(cafile="/usr/local/share/ca-certificates/orbit-managed-root-ca.crt")
try:
    with urllib.request.build_opener(NoRedirect(), urllib.request.HTTPSHandler(context=context)).open(request, timeout=60) as response:
        raw = response.read(65537)
        if response.status != 200 or len(raw) > 65536:
            raise ValueError("instance.git_mint_unavailable")
    references = json.loads(raw)["data"]["execution_secrets"]
    if references != json.loads(os.environ["ORBIT_GIT_REFERENCES"]):
        raise ValueError("instance.git_mint_unavailable")
except (OSError, ValueError, KeyError, TypeError):
    raise SystemExit("instance.git_mint_unavailable") from None
print(json.dumps({"minted": True}))
