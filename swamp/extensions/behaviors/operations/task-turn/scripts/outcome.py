import json
import os

raise SystemExit(1 if json.loads(os.environ["ORBIT_RESULT"]).get("error_code") else 0)
