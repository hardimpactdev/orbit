"""Select queue consumers from the latest host Process records at a Gateway handover."""
import json
from pathlib import Path
import re
import sys

FALLBACK = "orbit-gateway-horizon.service"
MANAGED = re.compile(r"orbit-process-[1-9][0-9]*-horizon\.service")


def select(admitted, active, definitions):
    known = set(active) | ({admitted} if admitted else set())
    managed = set()
    for unit in known | definitions.keys():
        record = definitions.get(unit, {})
        if MANAGED.fullmatch(unit) and not record.get("removed"):
            managed.add(unit)
    if managed:
        return sorted(unit for unit in managed if definitions.get(unit, {}).get("desired", "running") == "running")
    # A removed managed Process returns to the Node's queue-backed fallback. An
    # empty initial handover keeps a deliberately inactive host fallback stopped.
    return [FALLBACK] if known else []


if __name__ == "__main__":
    root = Path("/var/lib/orbit/gateway-processes")
    records = {p.stem: json.loads(p.read_bytes()) for p in root.glob("orbit-process-*-horizon.service.json")}
    for name in select(sys.argv[1], sys.stdin.read().splitlines(), records):
        print(name)
