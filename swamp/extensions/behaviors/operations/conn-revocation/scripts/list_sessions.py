"""One T3 session-list request; emit public identities and ownership only."""

import json
import os
import re
import sys
import urllib.error
import urllib.request


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *_args):
        return None


def observe():
    intent = json.loads(os.environ["ORBIT_CONN_INTENT"])
    request = urllib.request.Request(
        intent["url"].rstrip("/") + "/api/auth/clients",
        headers={"Authorization": "Bearer " + os.environ["ORBIT_CONN_ADMIN_SESSION"]},
    )
    with urllib.request.build_opener(NoRedirect).open(request, timeout=10) as response:
        body = response.read(4 * 1024 * 1024 + 1)
    if len(body) > 4 * 1024 * 1024:
        raise ValueError
    sessions = json.loads(body)
    if not isinstance(sessions, list) or len(sessions) > 4096:
        raise ValueError
    result = []
    seen = set()
    for session in sessions:
        identity = session["sessionId"]
        if (
            not isinstance(identity, str)
            or not re.fullmatch(r"[a-zA-Z0-9_-]{1,1024}", identity)
            or identity in seen
        ):
            raise ValueError
        seen.add(identity)
        current = session.get("current", False)
        if not isinstance(current, bool):
            raise TypeError
        client = session.get("client") or {}
        result.append(
            {
                "session_id": identity,
                "current": current,
                "owned": client.get("label") in intent["labels"],
            }
        )
    return result


if __name__ == "__main__":
    try:
        print(json.dumps(observe(), separators=(",", ":")))
    except urllib.error.HTTPError as error:
        print(
            json.dumps(
                {
                    "error_code": "conn.session_rejected"
                    if error.code == 401
                    else "conn.request_refused"
                }
            )
        )
        sys.exit(1)
    except (OSError, urllib.error.URLError):
        print('{"error_code":"conn.server_unreachable"}')
        sys.exit(1)
    except Exception:  # noqa: BLE001 - never expose server bodies or bearer data.
        print('{"error_code":"conn.response_invalid"}')
        sys.exit(1)
