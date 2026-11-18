"""Select queue consumers from the latest host Process records at a Gateway handover."""
import json
import re
import sys
from pathlib import Path

FALLBACK = "orbit-gateway-horizon.service"
MANAGED = re.compile(r"orbit-process-[1-9][0-9]*-horizon\.service")


def select(admitted, active, definitions):
    known = set(active) | ({admitted} if admitted else set())
    managed = set()
    for unit in set(active) | definitions.keys():
        record = definitions.get(unit, {})
        if MANAGED.fullmatch(unit) and not record.get("removed"):
            managed.add(unit)
    if managed:
        # Admission identifies the Process. Only its current desired state or
        # a worker observed before this handover authorizes starting it.
        return sorted(unit for unit in managed
                      if definitions.get(unit, {}).get("desired", "running" if unit in active else "stopped") == "running")
    # A removed managed Process returns to the Node's queue-backed fallback. An
    # empty initial handover keeps a deliberately inactive host fallback stopped.
    removed = any(definitions.get(unit, {}).get("removed") for unit in known if MANAGED.fullmatch(unit))
    return [FALLBACK] if FALLBACK in active or removed else []


if __name__ == "__main__":
    root = Path("/var/lib/orbit/gateway-processes")
    records = {p.stem: json.loads(p.read_bytes()) for p in root.glob("orbit-process-*-horizon.service.json")}
    for name in select(sys.argv[1], Path(sys.argv[2]).read_text().splitlines(), records):
        print(name)
