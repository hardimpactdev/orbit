"""Plausible CE requests with a memory-only session and public diagnostics."""

import http.client
import http.cookiejar
import json
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request


class Refusal(Exception):
    def __init__(self, code, detail):
        super().__init__(code)
        self.code, self.detail = code, detail


def expect(condition, code, detail):
    if not condition:
        raise Refusal(code, detail)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class Client:
    def __init__(self):
        self.intent = json.loads(os.environ["ORBIT_ANALYTICS_INPUT"])
        self.site = self.intent["site"]
        self.base = self.intent["base_url"]
        url = urllib.parse.urlsplit(self.base)
        expect(
            url.scheme in ("http", "https")
            and url.hostname
            and not url.username
            and not url.password
            and url.path in ("", "/")
            and not url.query
            and not url.fragment,
            "analytics.address_invalid",
            "Plausible base URL must be an HTTP(S) origin.",
        )
        self.jar = http.cookiejar.CookieJar()
        self.csrf = None
        self.opener = urllib.request.build_opener(
            NoRedirect(), urllib.request.HTTPCookieProcessor(self.jar)
        )
        self.secret = json.loads(os.environ["ORBIT_ANALYTICS_CREDENTIALS"])

    def call(self, method, path, body=None, authorization=None):
        context = f"Plausible {method} {path}"
        headers = {"accept": "application/json" if authorization else "text/html"}
        if body is not None:
            headers["content-type"] = "application/json"
            if authorization is None:
                headers["x-csrf-token"] = self.csrf or ""
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
            expect(
                len(content) <= 2 * 1024 * 1024,
                "analytics.management_unreadable",
                context + ": response exceeds 2 MiB.",
            )
            expect(
                status not in (401, 403)
                and not (path != "/logout" and location.startswith("/login")),
                "analytics.management_unauthorized",
                context + f": authentication refused (HTTP {status}).",
            )
            return status, location, content.decode("utf-8")
        except (
            OSError,
            UnicodeError,
            urllib.error.URLError,
            http.client.HTTPException,
        ):
            raise Refusal(
                "analytics.management_unavailable",
                context + ": response unavailable or unreadable.",
            ) from None

    def token(self, body):
        match = re.search(
            r'<meta\s+name="csrf-token"\s+content="([^"]+)"', body, re.IGNORECASE
        ) or re.search(
            r'<input[^>]*name="_csrf_token"[^>]*value="([^"]+)"', body, re.IGNORECASE
        )
        expect(
            match is not None,
            "analytics.management_unreadable",
            "Plausible management page has no CSRF token.",
        )
        self.csrf = match[1]

    def inspect(self, domain):
        status, location, body = self.call(
            "GET", "/" + urllib.parse.quote(domain, safe="") + "/settings/general"
        )
        if status == 404 or (status == 302 and location == "/sites"):
            return None
        expect(
            status == 200,
            "analytics.management_unreadable",
            f"Plausible site inspection returned HTTP {status}; expected 200.",
        )
        self.token(body)
        select = re.search(
            r'<select[^>]*name="site\[timezone\]"[^>]*>([\s\S]*?)</select>',
            body,
            re.IGNORECASE,
        )
        option = select and re.search(
            r'<option\b(?=[^>]*\bselected(?:="selected")?)(?=[^>]*\bvalue="([^"]+)")[^>]*>',
            select[1],
            re.IGNORECASE,
        )
        expect(
            option is not None,
            "analytics.management_unreadable",
            "Plausible site inspection has no selected timezone.",
        )
        return option[1]


def run(action):
    try:
        result = action(Client())
    except Refusal as error:
        # Only code-owned public diagnostics; never provider bodies or exception values.
        print(error.detail, file=sys.stderr)
        print(json.dumps({"error_code": error.code}))
        sys.exit(1)
    except Exception as error:  # noqa: BLE001 - Provider-owned exception text must never escape.
        print(
            f"Plausible site action failed ({type(error).__name__}).", file=sys.stderr
        )
        print('{"error_code":"analytics.provider_failed"}')
        sys.exit(1)
    print(json.dumps(result, separators=(",", ":")))
