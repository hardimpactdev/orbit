"""One observed service, local-health and pinned-CA TLS read after activation."""

import json
import urllib.request

from common import control, plan, ready, run, tls_custody

if __name__ == "__main__":
    data = plan()
    tls_custody(data)
    for unit in data["services"]:
        control("is-active", "--quiet", unit)
    with urllib.request.urlopen("http://127.0.0.1:9418/health", timeout=5) as response:
        if response.status != 200 or json.load(response).get("status") != "ok":
            raise ValueError("swamp.role_health_unverified")
    response = run(
        [
            "/usr/bin/curl",
            "--fail",
            "--silent",
            "--show-error",
            "--max-time",
            "10",
            "--cacert",
            "/usr/local/share/ca-certificates/orbit-root.crt",
            "--resolve",
            "swamp.orbit:9417:" + data["intent"]["address"],
            "https://swamp.orbit:9417/health",
        ]
    )
    if json.loads(response.stdout).get("status") != "ok":
        raise ValueError("swamp.role_tls_health_unverified")
    ready(
        node_id=data["intent"]["node_id"],
        source_sha=data["intent"]["source_sha"],
        tls=True,
    )
