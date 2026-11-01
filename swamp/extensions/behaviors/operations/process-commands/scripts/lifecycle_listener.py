"""One Vite listener ownership observation; native resume owns another attempt."""

import os
import re
from pathlib import Path

from process_common import Refused, command, main, owned


def check(value):
    owned(value)
    port = int(os.environ["ORBIT_PROCESS_PORT"])
    if not 1 <= port <= 65535:
        raise Refused("process.invalid")
    reply = command(
        [
            "/usr/bin/systemctl",
            "show",
            "--property=ControlGroup",
            "--value",
            value["name"],
        ]
    )
    group = reply.stdout.strip()
    listening = command(["/usr/bin/ss", "-H", "-ltnp", f"sport = :{port}"])
    if (
        reply.returncode
        or listening.returncode
        or not group.startswith("/")
        or not listening.stdout.strip()
    ):
        raise Refused("vite.listener_unavailable")
    for row in listening.stdout.strip().splitlines():
        pids = re.findall(r"pid=([0-9]+)", row)
        if not pids:
            raise Refused("vite.listener_unowned")
        for pid in pids:
            memberships = [
                line.split(":", 2)[-1]
                for line in Path(f"/proc/{pid}/cgroup").read_text().splitlines()
            ]
            if not any(
                path == group or path.startswith(group + "/") for path in memberships
            ):
                raise Refused("vite.listener_unowned")
    return {"ready": True}


if __name__ == "__main__":
    main(check)
