import hashlib
import json
import os
import subprocess

v = json.loads(os.environ["ORBIT_CONTAINER_INTENT"])
expected = hashlib.sha256(os.environ["ORBIT_CONTAINER_INTENT"].encode()).hexdigest()
found = {}
for kind, name in [
    ("container", v["name"]),
    ("container", v["backup"]),
    ("volume", v["volume"]),
]:
    r = subprocess.run(
        ["/usr/bin/docker", kind, "inspect", "--", name],
        capture_output=True,
        text=True,
        check=False,
    )
    if r.returncode:
        listing = subprocess.run(
            [
                "/usr/bin/docker",
                kind,
                "ls",
                *(["--all"] if kind == "container" else []),
                "--format",
                "{{.Names}}" if kind == "container" else "{{.Name}}",
            ],
            capture_output=True,
            text=True,
            check=True,
        )
        if name in listing.stdout.splitlines():
            raise SystemExit("metrics.container_inspection_failed")
        found[name] = None
        continue
    item = json.loads(r.stdout)[0]
    labels = item.get("Labels") if kind == "volume" else item["Config"].get("Labels")
    required = {
        "com.orbit.managed": "metrics",
        "com.orbit.metrics." + ("volume" if kind == "volume" else "service"): v[
            "service"
        ],
    }
    if not isinstance(labels, dict) or any(
        labels.get(k) != value for k, value in required.items()
    ):
        raise SystemExit("metrics.container_ownership_drift")
    found[name] = {
        "hash": labels.get("com.orbit.metrics.spec-hash"),
        "running": item.get("State", {}).get("Running", False),
    }
print(
    json.dumps(
        {
            "current": found[v["name"]],
            "backup": found[v["backup"]],
            "volume": found[v["volume"]],
            "expected_hash": expected,
        }
    )
)
