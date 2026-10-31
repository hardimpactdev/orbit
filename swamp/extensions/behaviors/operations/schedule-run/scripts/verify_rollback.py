"""Verify the original timer state after native rollback, including an absent timer."""

from schedule_common import intent, paths, report, require, snapshot, timer_state


def verify():
    value = intent()
    original = snapshot(value)["timer"]
    require(
        timer_state(paths(value)["timer"].name)
        == (original["timer_enabled"], original["timer_active"]),
        "schedule.rollback_failed",
    )


report(verify)
