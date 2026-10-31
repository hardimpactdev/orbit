from common import Refusal, run


def action(c):
    secret = c.credentials()
    status, location, _ = c.call(
        "POST", "/login", {"email": secret["email"], "password": secret["password"]}
    )
    if status != 302 or location != "/sites":
        raise Refusal("analytics.management_unauthorized")


if __name__ == "__main__":
    run(action)
