"""One HTTP readiness observation. The native parent owns resume and restoration."""

import os
import urllib.parse
import urllib.request

from process_common import Refused, main, owned


def check(value):
    owned(value)
    url = os.environ["ORBIT_PROCESS_HEALTH_URL"]
    parsed = urllib.parse.urlsplit(url)
    if (
        parsed.scheme not in ("http", "https")
        or not parsed.hostname
        or parsed.username is not None
        or parsed.password is not None
        or any(ord(char) < 32 or ord(char) == 127 for char in url)
    ):
        raise Refused("process.invalid")
    try:
        with urllib.request.urlopen(url, timeout=3) as response:
            if not 200 <= response.status < 300:
                raise Refused("process.health_unavailable")
    except OSError:
        raise Refused("process.health_unavailable") from None
    return {"ready": True}


if __name__ == "__main__":
    main(check)
