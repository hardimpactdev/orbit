"""Stage one canonical public Schedule unit for systemd verification."""

import sys

from schedule_common import atomic, intent, paths, report, require, staging


def stage():
    value = intent()
    kind = sys.argv[1]
    require(kind in ["service", "timer"])
    atomic(staging(value) / paths(value)[kind].name, value["units"][kind])


report(stage)
