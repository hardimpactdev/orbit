"""Confirm one model through the copy's Orbit-managed Codex Responses provider."""

import http.client
import json
import sys
import tomllib
from pathlib import Path
from urllib.parse import urlsplit


def probe(model):
    home = Path.home()
    config = tomllib.loads((home / ".codex/config.toml").read_text())
    provider = config["model_providers"][config["model_provider"]]
    environment = dict(
        line.split("=", 1)
        for line in (home / ".config/orbit/model.env").read_text().splitlines()
        if "=" in line
    )
    origin = environment["ANTHROPIC_BASE_URL"]
    if (
        provider["base_url"] != origin + "/v1"
        or provider["env_key"] != "ORBIT_MODEL_KEY"
        or provider["wire_api"] != "responses"
    ):
        return 1

    address = urlsplit(origin)
    if address.scheme != "http" or not address.hostname:
        return 1

    # Use the same forwarder and placeholder as Codex. Send no workspace data or tools.
    payload = {"model": model, "input": "Reply OK.", "max_output_tokens": 16, "store": False}
    connection = http.client.HTTPConnection(address.hostname, address.port, timeout=20)
    try:
        connection.request(
            "POST",
            "/v1/responses",
            json.dumps(payload),
            {"Authorization": "Bearer " + environment["ORBIT_MODEL_KEY"], "Content-Type": "application/json"},
        )
        response = connection.getresponse()
        body = json.loads(response.read(65536))
        if response.status in (400, 403, 404) and body.get("error", {}).get("code") == "model_not_found":
            return 2
        if response.status != 200 or body.get("object") != "response" or body.get("model") != model:
            return 1
        if body.get("status") == "completed":
            return 0
        if body.get("status") == "incomplete" and body.get("incomplete_details", {}).get("reason") == "max_output_tokens":
            return 0
        return 1
    finally:
        connection.close()


if __name__ == "__main__":
    try:
        sys.exit(probe(sys.argv[1]))
    except (AttributeError, http.client.HTTPException, KeyError, OSError, TypeError, ValueError):
        # Return only an exit code. Configuration, credentials and provider responses stay in the copy.
        sys.exit(1)
