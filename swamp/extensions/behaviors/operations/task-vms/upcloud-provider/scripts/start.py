"""Start only the exact existing owned server, without a readiness loop."""

import os

from upcloud_common import intent, owned, request, run


def action():
    value = intent()
    server = owned(value, os.environ["ORBIT_SERVER_ID"])
    if server["state"] == "stopped":
        request("POST", "server/" + server["uuid"] + "/start", {"server": {}})
    return {"provider_id": server["uuid"]}


if __name__ == "__main__":
    run(action)
