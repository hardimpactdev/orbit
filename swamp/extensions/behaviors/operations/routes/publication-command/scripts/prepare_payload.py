"""Prepare the root-owned directory for one immutable public delta payload."""

import json
import os
import tempfile

from payload_common import ROOT, directory, metadata, stage_for

value = metadata()
directory(ROOT)
base = ROOT / ".orbit-deltas"
base.mkdir(mode=0o700, exist_ok=True)
directory(base)
(base / value["run_id"]).mkdir(mode=0o700, exist_ok=True)
stage = stage_for(value)
record = stage / "input.json"
if record.exists():
    if json.loads(record.read_text()) != value:
        raise ValueError("caddy.delta_stage_conflict")
else:
    fd, temporary = tempfile.mkstemp(prefix=".input-", dir=stage)
    try:
        with os.fdopen(fd, "w") as output:
            output.write(json.dumps(value))
            output.flush()
            os.fsync(output.fileno())
        os.replace(temporary, record)
    finally:
        if os.path.lexists(temporary):
            os.unlink(temporary)
print('{"prepared":true}')
