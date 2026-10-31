import json
import os

before = json.loads(os.environ["ORBIT_CONTAINER_BEFORE"])
if before["backup"] is not None:
    raise SystemExit("metrics.runtime_recovery_required")
print('{"ready":true}')
