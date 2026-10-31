import json
import os

ids = [int(value) for value in os.environ["ORBIT_NODE_IDS"].split(",")]
print(json.dumps({"ready": True, "verified_node_ids": ids, "runtime_committed": True}))
