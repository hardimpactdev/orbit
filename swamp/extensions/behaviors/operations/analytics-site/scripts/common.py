"""Plausible CE HTTP and private session storage; never export provider bodies."""

import http.cookiejar
import json
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path


class Refusal(Exception):
    pass


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class Client:
    def __init__(self):
        self.intent = json.loads(os.environ["ORBIT_ANALYTICS_INPUT"])
        self.site = self.intent["site"]
        self.base = self.intent["base_url"]
        url = urllib.parse.urlsplit(self.base)
        if (
            url.scheme not in ("http", "https")
            or not url.hostname
            or url.username
            or url.password
            or url.path not in ("", "/")
            or url.query
            or url.fragment
        ):
            raise Refusal("analytics.address_invalid")
        self.path = Path(os.environ["ORBIT_ANALYTICS_SESSION"])
        self.jar = http.cookiejar.LWPCookieJar(str(self.path / "cookies"))
        if (self.path / "cookies").exists():
            self.jar.load(ignore_discard=True, ignore_expires=True)
        self.opener = urllib.request.build_opener(
            NoRedirect(), urllib.request.HTTPCookieProcessor(self.jar)
        )

    def credentials(self):
        return json.loads(os.environ["ORBIT_ANALYTICS_CREDENTIALS"])

    def call(self, method, path, body=None, authorization=None):
        headers = {"accept": "application/json" if authorization else "text/html"}
        if body is not None:
            headers["content-type"] = "application/json"
            if authorization is None:
                headers["x-csrf-token"] = (self.path / "csrf").read_text()
        if authorization:
            headers["authorization"] = authorization
        request = urllib.request.Request(
            self.base.rstrip("/") + path,
            data=None if body is None else json.dumps(body).encode(),
            headers=headers,
            method=method,
        )
        try:
            try:
                response = self.opener.open(request, timeout=15)
            except urllib.error.HTTPError as error:
                response = error
            with response:
                status, location = response.code, response.headers.get("Location", "")
                content = response.read(2 * 1024 * 1024 + 1)
            if len(content) > 2 * 1024 * 1024:
                raise Refusal("analytics.management_unreadable")
            if authorization is None:
                self.jar.save(ignore_discard=True, ignore_expires=True)
                os.chmod(self.path / "cookies", 0o600)
            if status in (401, 403) or (
                path != "/logout" and location.startswith("/login")
            ):
                raise Refusal("analytics.management_unauthorized")
            return status, location, content.decode("utf-8")
        except (OSError, UnicodeError, urllib.error.URLError):
            raise Refusal("analytics.management_unavailable") from None

    def token(self, body):
        match = re.search(
            r'<meta\s+name="csrf-token"\s+content="([^"]+)"', body, re.I
        ) or re.search(r'<input[^>]*name="_csrf_token"[^>]*value="([^"]+)"', body, re.I)
        if not match:
            raise Refusal("analytics.management_unreadable")
        target = self.path / "csrf"
        target.touch(mode=0o600, exist_ok=True)
        target.write_text(match[1])

    def inspect(self, domain):
        status, location, body = self.call(
            "GET", "/" + urllib.parse.quote(domain, safe="") + "/settings/general"
        )
        if status == 404 or (status == 302 and location == "/sites"):
            return None
        if status != 200:
            raise Refusal("analytics.management_unreadable")
        self.token(body)
        select = re.search(
            r'<select[^>]*name="site\[timezone\]"[^>]*>([\s\S]*?)</select>', body, re.I
        )
        option = select and re.search(
            r'<option\b(?=[^>]*\bselected(?:="selected")?)(?=[^>]*\bvalue="([^"]+)")[^>]*>',
            select[1],
            re.I,
        )
        if not option:
            raise Refusal("analytics.management_unreadable")
        return option[1]


def run(action):
    os.umask(0o077)
    try:
        result = action(Client())
    except Refusal as error:
        print(json.dumps({"error_code": str(error)}))
        sys.exit(1)
    except Exception:
        print('{"error_code":"analytics.provider_failed"}')
        sys.exit(1)
    print(json.dumps(result or {"ready": True}, separators=(",", ":")))
