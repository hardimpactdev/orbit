"""Read one bounded public artifact and timer snapshot; never emit private file bytes."""

import grp
import json
import os

from schedule_common import (
    ROOT_GID,
    Refused,
    command_matches,
    directories,
    intent,
    manager,
    owned,
    paths,
    require,
    timer_state,
)

result = {
    "ok": False,
    "error_code": None,
    "present": False,
    "needs_install": True,
    "obsolete": False,
    "has_service": False,
    "has_timer": False,
    "has_script": False,
    "has_callback": False,
    "timer_enabled": False,
    "timer_active": False,
}
try:
    value = intent()
    directories()
    p = paths(value)
    group = grp.getgrnam(value["schedule"]["group"])
    current = {
        name: owned(
            path,
            0o750 if name == "script" else 0o700 if name == "callback" else 0o644,
            group.gr_gid if name == "script" else ROOT_GID,
        )
        for name, path in p.items()
    }
    for name, content in current.items():
        result["has_" + name] = content is not None
    result["present"] = any(v is not None for v in current.values())
    result["obsolete"] = (
        current["script"] is not None
        or current["callback"] is not None
        or (
            current["service"] is not None
            and current["service"] != value["units"]["service"]
        )
    )
    if current["service"] is not None:
        require(
            current["service"]
            in [
                value["units"]["service"],
                value["legacy"]["agentService"],
                value["legacy"]["directService"],
            ]
        )
    if current["timer"] is not None:
        require(current["timer"] == value["units"]["timer"])
    if current["script"] is not None:
        require(
            command_matches(
                current["script"],
                value["legacy"]["script_prefix"],
                value["schedule"]["command_hash"],
            )
        )
    if current["callback"] is not None:
        require(current["callback"] == value["legacy"]["callback"])
    if current["service"] in [
        value["legacy"]["agentService"],
        value["legacy"]["directService"],
    ]:
        require(current["script"] is not None)
    result["needs_install"] = (
        result["obsolete"] or current["service"] is None or current["timer"] is None
    )
    if result["has_timer"]:
        enabled, active = timer_state(p["timer"].name)
        result.update(timer_enabled=enabled, timer_active=active)
    removing = os.environ["ORBIT_SCHEDULE_ACTION"] == "destroy"
    if result["present"] and (
        (removing and not value["cascade"]) or (not removing and result["obsolete"])
    ):
        active = manager("is-active", p["service"].name).stdout.strip()
        require(active in ["inactive", "failed", "unknown"], "schedule.run_active")
    result["ok"] = True
except (Refused, OSError, ValueError, KeyError) as error:
    result["error_code"] = (
        error.code if isinstance(error, Refused) else "schedule.target_unavailable"
    )
print(json.dumps(result))
