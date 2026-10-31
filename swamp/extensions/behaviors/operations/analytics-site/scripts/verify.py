from common import Refusal, run


def action(c):
    if c.inspect(c.site["domain"]) != c.site["timezone"]:
        raise Refusal("analytics.site_unproven")


if __name__ == "__main__":
    run(action)
