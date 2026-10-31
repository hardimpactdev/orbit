import base64
import json
import os
import re

from common import Refusal, run


def identity(row):
    goal = row["goal"]
    return json.dumps(
        [
            row["goal_type"],
            goal.get("event_name", goal.get("path")),
            goal.get("currency"),
            sorted(goal.get("custom_props", {}).items()),
        ],
        sort_keys=True,
    )


def action(c):
    goals = json.loads(os.environ["ORBIT_ANALYTICS_GOALS"])
    if not 1 <= len(goals) <= 8:
        raise Refusal("analytics.goals_invalid")
    plugins = c.credentials().get("plugins", {})
    token = plugins.get("token", "")
    if plugins.get("domain") != c.site["domain"] or not re.fullmatch(r"[!-~]+", token):
        raise Refusal("analytics.goals_credentials_missing")
    authorization = "Basic " + base64.b64encode((":" + token).encode()).decode()
    status, _, body = c.call(
        "PUT", "/api/plugins/v1/goals", {"goals": goals}, authorization
    )
    if status != 201:
        raise Refusal("analytics.goals_update_failed")
    try:
        observed = json.loads(body)["goals"]
        if any(
            type(row["goal"]["id"]) is not int or row["goal"]["id"] < 1
            for row in observed
        ) or sorted(map(identity, observed)) != sorted(map(identity, goals)):
            raise ValueError()
    except (KeyError, ValueError, TypeError):
        raise Refusal("analytics.goals_unproven") from None


if __name__ == "__main__":
    run(action)
