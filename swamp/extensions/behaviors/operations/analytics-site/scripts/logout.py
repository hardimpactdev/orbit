from common import Refusal, run


def action(c):
    status, _, _ = c.call("GET", "/logout")
    if status not in (200, 302):
        raise Refusal("analytics.logout_failed")


if __name__ == "__main__":
    run(action)
