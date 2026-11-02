"""Ask the Gateway to sign one Node-made CSR from 209: the one step that calls the Gateway.

Public env: ORBIT_CERTIFICATE_OPERATION_ID, ORBIT_CERTIFICATE_RUN_ID, ORBIT_CERTIFICATE_NODE_ID, ORBIT_CERTIFICATE_SCOPE,
ORBIT_CERTIFICATE_REQUEST (the PEM CSR the Node printed) and, for a restore, ORBIT_CERTIFICATE_RESTORING=true.
Prints the signed leaf, then the Orbit root, as PEM; official file steps install them. A refused or failed call fails
the step, with the reason on stderr.
"""

import base64
import json
import os
import re
import ssl
import sys
import urllib.request


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


try:
    operation = os.environ["ORBIT_CERTIFICATE_OPERATION_ID"]
    if not re.fullmatch(r"[1-9][0-9]*", operation):
        raise ValueError("Invalid operation")
    node_id = int(os.environ["ORBIT_CERTIFICATE_NODE_ID"])
    scope = os.environ["ORBIT_CERTIFICATE_SCOPE"]
    record = {
        "node_id": node_id,
        "scope": scope,
        "state": "request",
        "request_base64": base64.b64encode(
            os.environ["ORBIT_CERTIFICATE_REQUEST"].strip().encode() + b"\n"
        ).decode(),
    }
    body = {"run_id": os.environ["ORBIT_CERTIFICATE_RUN_ID"], "requests": [record]}
    if os.environ.get("ORBIT_CERTIFICATE_RESTORING") == "true":
        body["restoring"] = True
    url = (
        "https://gateway.orbit/api/v1/direct-operations/"
        + operation
        + "/certificates/sign"
    )
    request = urllib.request.Request(
        url,
        data=json.dumps(body).encode(),
        method="POST",
        headers={"Content-Type": "application/json", "Accept": "application/json"},
    )
    context = ssl.create_default_context(
        cafile="/usr/local/share/ca-certificates/orbit-managed-root-ca.crt"
    )
    opener = urllib.request.build_opener(
        NoRedirect(), urllib.request.HTTPSHandler(context=context)
    )
    with opener.open(request, timeout=60) as response:
        signed = json.loads(response.read(16_777_216))["data"]["certificates"]
    leaf = next(
        item for item in signed if item["node_id"] == node_id and item["scope"] == scope
    )
    for field in ("certificate_base64", "root_certificate_base64"):
        sys.stdout.write(base64.b64decode(leaf[field], validate=True).decode())
except (OSError, ValueError, KeyError, TypeError, StopIteration) as error:
    code = "app-dev.certificate_sign_failed"
    sys.exit(code + ": " + str(getattr(error, "code", type(error).__name__)))
