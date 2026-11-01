"""One read-only managed Valkey index check using the official client."""

import ipaddress
import re

from create_valkey import index
from server_common import Refused, main


def check(intent, material):
    try:
        assert set(intent) <= {"validate_index", "host", "port", "index", "ssh"}
        assert intent["validate_index"] is True
        assert ipaddress.ip_address(intent["host"]).version == 4
        assert type(intent["port"]) is int and 1 <= intent["port"] <= 65535
        assert re.fullmatch(r"[0-9]{1,255}", intent["index"])
        assert set(material) == {"password"}
        assert (
            isinstance(material["password"], str)
            and 1 <= len(material["password"]) <= 16384
        )
        assert not any(c in material["password"] for c in "\0\r\n")
    except (AssertionError, KeyError, TypeError, ValueError):
        raise Refused("database.server_command_failed") from None
    index({**intent, "driver": "redis", "index": int(intent["index"])}, material, {})
    return {"backend": "active"}


if __name__ == "__main__":
    main(check, error_code="database.server_command_failed")
