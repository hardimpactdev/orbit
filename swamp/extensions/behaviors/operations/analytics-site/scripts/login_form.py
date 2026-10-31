from common import Refusal, run


def action(c):
    status, _, body = c.call("GET", "/login")
    if status != 200:
        raise Refusal("analytics.management_unavailable")
    c.token(body)


if __name__ == "__main__":
    run(action)
