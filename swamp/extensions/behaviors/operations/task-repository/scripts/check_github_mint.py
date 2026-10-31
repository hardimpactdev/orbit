"""Use the existing mint-only Gateway primitive; stdout contains descriptor names only."""

import json
import os
import re
import ssl
import urllib.request

from publication_common import NoRedirect
from repository_common import Refusal, emit


def mint():
    operation = os.environ["ORBIT_OPERATION_ID"]
    if not re.fullmatch("[1-9][0-9]*", operation):
        raise Refusal("tasks.check_invalid")
    request = urllib.request.Request(
        "https://gateway.orbit/api/v1/direct-operations/" + operation + "/github-token",
        data=json.dumps({"run_id": os.environ["ORBIT_RUN_ID"]}).encode(),
        method="POST",
        headers={"Content-Type": "application/json", "Accept": "application/json"},
    )
    context = ssl.create_default_context(
        cafile="/usr/local/share/ca-certificates/orbit-managed-root-ca.crt"
    )
    try:
        with urllib.request.build_opener(
            NoRedirect(), urllib.request.HTTPSHandler(context=context)
        ).open(request, timeout=60) as response:
            if response.status != 200:
                raise Refusal("tasks.handoff_ci_unavailable")
            raw = response.read(65537)
            if len(raw) > 65536:
                raise Refusal("tasks.handoff_ci_unavailable")
        received = json.loads(raw)["data"]["execution_secrets"]
        expected = json.loads(os.environ["ORBIT_EXECUTION_REFERENCES"])
        if received != expected:
            raise Refusal("tasks.handoff_ci_unavailable")
        return {"minted": True}
    except (OSError, ValueError, KeyError, TypeError):
        raise Refusal("tasks.handoff_ci_unavailable") from None


if __name__ == "__main__":
    emit(mint)
