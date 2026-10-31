"""Apply one admitted Router firewall policy using the canonical UFW tool transaction."""

import json
import os

import firewall

if __name__ == "__main__":
    result = firewall.main(
        {"mode": "rules", **json.loads(os.environ["ORBIT_ROUTER_INPUT"])}
    )
    print(json.dumps(result))
    if result.get("error_code") or result.get("matches") is not True:
        raise SystemExit(1)
