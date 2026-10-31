"""Verify the admitted timer state or complete removal without exposing file contents."""

import os

from schedule_common import intent, paths, report, require, timer_state


def verify():
    value = intent()
    p = paths(value)
    if os.environ["ORBIT_SCHEDULE_ACTION"] == "destroy":
        require(
            not any(path.exists() or path.is_symlink() for path in p.values()),
            "schedule.remove_failed",
        )
    else:
        actual = timer_state(p["timer"].name)
        expected = (
            os.environ["ORBIT_SCHEDULE_ENABLED"] == "true",
            os.environ["ORBIT_SCHEDULE_ACTIVE"] == "true",
        )
        require(actual == expected, "schedule.install_failed")


report(verify)
