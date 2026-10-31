"""One readiness read; native resume owns later attempts."""

import os

from upcloud_common import intent, owned, ready, run


def action():
    value = intent()
    return ready(value, owned(value, os.environ["ORBIT_SERVER_ID"]))


if __name__ == "__main__":
    run(action)
