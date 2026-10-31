from common import Refusal, run


def action(c):
    status, _, _ = c.call(
        "POST",
        "/api/v2/query",
        {"site_id": c.site["domain"], "metrics": ["visitors"], "date_range": "7d"},
        "Bearer " + c.credentials()["apiKey"],
    )
    if status != 200:
        raise Refusal("analytics.stats_unauthorized")


if __name__ == "__main__":
    run(action)
