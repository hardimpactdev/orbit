import os
from urllib.parse import quote

from common import Refusal, run


def action(c):
    domain = os.environ["ORBIT_ANALYTICS_CURRENT_DOMAIN"]
    status, location, _ = c.call(
        "PUT",
        "/" + quote(domain, safe="") + "/settings",
        {"site": {"domain": c.site["domain"], "timezone": c.site["timezone"]}},
    )
    if (
        status != 302
        or location != "/" + quote(c.site["domain"], safe="") + "/settings/general"
    ):
        raise Refusal("analytics.site_update_failed")


if __name__ == "__main__":
    run(action)
