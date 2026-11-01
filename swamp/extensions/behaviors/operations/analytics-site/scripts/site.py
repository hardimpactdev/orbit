"""Reconcile one Plausible site and close its management session in one action group."""

import base64
import json
import re
import sys
from urllib.parse import quote

from common import Refusal, expect, run


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


def goals(c):
    desired = c.site.get("goals", [])
    if not desired:
        return
    plugin = c.secret.get("plugins", {})
    token = plugin.get("token", "")
    expect(
        plugin.get("domain") == c.site["domain"] and re.fullmatch(r"[!-~]+", token),
        "analytics.goals_credentials_missing",
        "Plausible goals require the site's Plugins API credential.",
    )
    authorization = "Basic " + base64.b64encode((":" + token).encode()).decode()
    # CE accepts at most eight goals per request; this is one site's action group.
    for offset in range(0, len(desired), 8):
        batch = desired[offset : offset + 8]
        status, _, body = c.call(
            "PUT", "/api/plugins/v1/goals", {"goals": batch}, authorization
        )
        expect(
            status == 201,
            "analytics.goals_update_failed",
            f"Plausible goals returned HTTP {status}; expected 201.",
        )
        try:
            observed = json.loads(body)["goals"]
            valid = all(
                type(row["goal"]["id"]) is int and row["goal"]["id"] > 0
                for row in observed
            )
            expect(
                valid
                and sorted(map(identity, observed)) == sorted(map(identity, batch)),
                "analytics.goals_unproven",
                "Plausible goals receipt differs from the admitted batch.",
            )
        except (KeyError, ValueError, TypeError):
            raise Refusal(
                "analytics.goals_unproven", "Plausible goals receipt is invalid."
            ) from None


def reconcile(c):
    status, _, body = c.call("GET", "/login")
    expect(
        status == 200,
        "analytics.management_unavailable",
        f"Plausible login form returned HTTP {status}; expected 200.",
    )
    c.token(body)
    try:
        status, location, _ = c.call(
            "POST",
            "/login",
            {
                "email": c.secret["email"],
                "password": c.secret["password"],
            },
        )
        expect(
            status == 302 and location == "/sites",
            "analytics.management_unauthorized",
            f"Plausible login did not open the site list (HTTP {status}).",
        )
        domain = c.site["domain"]
        current = c.inspect(domain)
        previous = c.site.get("previous_domain")
        if current is None and previous and previous != domain:
            domain, current = previous, c.inspect(previous)
        if current is None:
            status, _, body = c.call("GET", "/sites/new")
            expect(
                status == 200,
                "analytics.management_unreadable",
                f"Plausible new-site form returned HTTP {status}; expected 200.",
            )
            c.token(body)
            status, location, _ = c.call(
                "POST",
                "/sites",
                {
                    "site": {
                        "domain": c.site["domain"],
                        "timezone": c.site["timezone"],
                    }
                },
            )
            expect(
                status == 302
                and location.startswith(
                    "/" + quote(c.site["domain"], safe="") + "/installation"
                ),
                "analytics.site_create_failed",
                f"Plausible site creation did not reach installation (HTTP {status}).",
            )
        elif domain != c.site["domain"] or current != c.site["timezone"]:
            status, location, _ = c.call(
                "PUT",
                "/" + quote(domain, safe="") + "/settings",
                {
                    "site": {
                        "domain": c.site["domain"],
                        "timezone": c.site["timezone"],
                    }
                },
            )
            expect(
                status == 302
                and location
                == "/" + quote(c.site["domain"], safe="") + "/settings/general",
                "analytics.site_update_failed",
                f"Plausible site update did not reach settings (HTTP {status}).",
            )
        goals(c)
        expect(
            c.inspect(c.site["domain"]) == c.site["timezone"],
            "analytics.site_unproven",
            "Plausible site timezone differs from admitted intent.",
        )
        status, _, _ = c.call(
            "POST",
            "/api/v2/query",
            {
                "site_id": c.site["domain"],
                "metrics": ["visitors"],
                "date_range": "7d",
            },
            "Bearer " + c.secret["apiKey"],
        )
        expect(
            status == 200,
            "analytics.stats_unauthorized",
            f"Plausible Stats API returned HTTP {status}; expected 200.",
        )
    finally:
        primary = sys.exception()
        if c.jar:
            try:
                status, _, _ = c.call("GET", "/logout")
                expect(
                    status in (200, 302),
                    "analytics.logout_failed",
                    f"Plausible logout returned HTTP {status}; expected 200 or 302.",
                )
            except Refusal as error:
                if primary is None:
                    raise
                print("Cleanup: " + error.detail, file=sys.stderr)
    return {
        "ready": True,
        "domain": c.site["domain"],
        "timezone": c.site["timezone"],
        "readable": True,
    }


if __name__ == "__main__":
    run(reconcile)
