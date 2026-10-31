from urllib.parse import quote

from common import Refusal, run


def action(c):
    status, location, _ = c.call(
        "POST",
        "/sites",
        {"site": {"domain": c.site["domain"], "timezone": c.site["timezone"]}},
    )
    if status != 302 or not location.startswith(
        "/" + quote(c.site["domain"], safe="") + "/installation"
    ):
        raise Refusal("analytics.site_create_failed")


if __name__ == "__main__":
    run(action)
