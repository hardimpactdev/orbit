"""Fixed, read-only ID map facts of an Instance host (docs/reference/development-containers.md#isolated-id-ranges).

The Gateway prepends BUDGET (seconds) and runs `python3 -` on the Node over SSH. It reads root's first subordinate range in
/etc/subuid and /etc/subgid, and the ID map keys of every container Incus holds, in every project, stopped ones included. It
prints one JSON object; it changes nothing.
"""
import json
import subprocess

KEYS = ("security.privileged", "security.idmap.isolated", "security.idmap.base", "security.idmap.size", "volatile.idmap.base")


def root(path):
    try:
        with open(path, encoding="utf-8") as lines:
            for line in lines:
                parts = line.strip().split(":")
                if len(parts) == 3 and parts[0] == "root" and parts[1].isdigit() and parts[2].isdigit():
                    return [int(parts[1]), int(parts[2])]
    except OSError:
        return None
    return None


def main():
    try:
        result = subprocess.run(["incus", "query", "/1.0/instances?all-projects=true&recursion=1"], stdin=subprocess.DEVNULL,
                                stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=BUDGET, check=False)
    except subprocess.TimeoutExpired:
        print(json.dumps({"error": "timeout"}))
        return
    if result.returncode != 0:
        print(json.dumps({"error": "incus"}))
        return
    containers = []
    for row in json.loads(result.stdout):
        if row.get("type") not in (None, "container"):
            continue
        config = {**(row.get("config") or {}), **(row.get("expanded_config") or {})}
        containers.append({"name": row.get("name"), "project": row.get("project"),
                           "config": {key: config[key] for key in KEYS if isinstance(config.get(key), str)}})
    print(json.dumps({"uid": root("/etc/subuid"), "gid": root("/etc/subgid"), "containers": containers}))


main()
