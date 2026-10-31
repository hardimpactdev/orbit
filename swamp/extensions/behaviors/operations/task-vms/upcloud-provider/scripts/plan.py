"""Read the exact admitted plan once."""

import re

from upcloud_common import Refused, intent, request, run


def action():
    value = intent()
    rows = request("GET", "plan")["plans"]["plan"]
    if not isinstance(rows, list) or len(rows) > 4096:
        raise Refused()
    plans = [row for row in rows if row["name"] == value["upcloud"]["plan"]]
    if len(plans) != 1:
        raise Refused()
    tier = plans[0].get("storage_tier")
    if tier is not None and (
        not isinstance(tier, str) or re.fullmatch("[a-z]{1,16}", tier) is None
    ):
        raise Refused()
    return {"tier": tier}


if __name__ == "__main__":
    run(action)
