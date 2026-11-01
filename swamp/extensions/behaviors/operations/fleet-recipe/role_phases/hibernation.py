"""Keep hibernation directory policy before Caddy ordering and publication."""

import grp
import json

from phase_common import inputs, module


def converge(data):
    outcome = module("hibernation").main(
        {
            "marker_directory": "/dev/shm/orbit/hibernation",
            "log_directory": "/data/caddy/orbit/hibernation",
            "owner": [0, grp.getgrnam("caddy").gr_gid],
        }
    )
    if "error_code" in outcome:
        raise ValueError(outcome["error_code"])
    ordering = module("caddy_ordering").converge_ordering()
    if not ordering["completed"]:
        raise ValueError("service.ordering_failed")
    return {"ready": True}


if __name__ == "__main__":
    print(json.dumps(converge(inputs())))
