"""Existing scoped GitHub credential use; no Gateway requests or token output."""

import base64
import json
import os
import ssl
import urllib.error
import urllib.request

from repository_common import Refusal


def intent():
    return json.loads(os.environ["ORBIT_PUBLICATION_INPUT"])


def token():
    return os.environ.pop("ORBIT_GITHUB_TOKEN")


def git_environment(secret):
    env = {
        key: os.environ[key] for key in ("PATH", "HOME", "LANG") if key in os.environ
    }
    env.update(
        GIT_TERMINAL_PROMPT="0",
        GIT_CONFIG_GLOBAL="/dev/null",
        GIT_CONFIG_NOSYSTEM="1",
        GIT_ALLOW_PROTOCOL="https",
        GIT_CONFIG_COUNT="4",
        GIT_CONFIG_KEY_0="http.https://github.com/.extraHeader",
        GIT_CONFIG_VALUE_0="Authorization: Basic "
        + base64.b64encode(("x-access-token:" + secret).encode()).decode(),
        GIT_CONFIG_KEY_1="credential.helper",
        GIT_CONFIG_VALUE_1="",
        GIT_CONFIG_KEY_2="core.hooksPath",
        GIT_CONFIG_VALUE_2="/dev/null",
        GIT_CONFIG_KEY_3="http.followRedirects",
        GIT_CONFIG_VALUE_3="false",
    )
    return env


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *_args, **_kwargs):
        return None


def request(path, method="GET", body=None):
    secret = token()
    req = urllib.request.Request(
        "https://api.github.com/" + path.lstrip("/"),
        method=method,
        data=json.dumps(body).encode() if body is not None else None,
        headers={
            "Authorization": "Bearer " + secret,
            "Accept": "application/vnd.github+json",
            "Content-Type": "application/json",
            "X-GitHub-Api-Version": "2022-11-28",
        },
    )
    try:
        with urllib.request.build_opener(
            NoRedirect(),
            urllib.request.HTTPSHandler(context=ssl.create_default_context()),
        ).open(req, timeout=30) as response:
            return None if response.status == 204 else json.load(response)
    except (OSError, ValueError, urllib.error.HTTPError):
        raise Refusal("tasks.publish_failed") from None
