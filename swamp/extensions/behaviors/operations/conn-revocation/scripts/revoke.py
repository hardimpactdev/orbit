"""One idempotent T3 session or pairing-link revocation request."""

import json
import os
import sys
import urllib.error
import urllib.request


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *_args):
        return None


def revoke():
    intent = json.loads(os.environ["ORBIT_CONN_INTENT"])
    target = json.loads(os.environ["ORBIT_CONN_REVOKE"])
    path, field = {
        "session": ("clients/revoke", "sessionId"),
        "link": ("pairing-links/revoke", "id"),
    }[target["type"]]
    request = urllib.request.Request(
        intent["url"].rstrip("/") + "/api/auth/" + path,
        data=json.dumps({field: target["id"]}).encode(),
        headers={
            "Authorization": "Bearer " + os.environ["ORBIT_CONN_ADMIN_SESSION"],
            "Content-Type": "application/json",
        },
        method="POST",
    )
    with urllib.request.build_opener(NoRedirect).open(request, timeout=10) as response:
        body = response.read(4 * 1024 * 1024 + 1)
    if len(body) > 4 * 1024 * 1024:
        raise ValueError
    result = json.loads(body)
    if not isinstance(result.get("revoked"), bool):
        raise TypeError
    return {"revoked_sessions": int(target["type"] == "session" and result["revoked"])}


if __name__ == "__main__":
    try:
        print(json.dumps(revoke(), separators=(",", ":")))
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
