"""Check the retained host release directly through its PHP-FPM Unix socket."""

import json
import socket
import struct
import sys
from functools import partial
from pathlib import Path

HEADER = struct.Struct("!BBHHBB")
SOCKET = "/run/php/orbit-gateway.sock"


def request(public, uri):
    def record(kind, body=b""):
        return HEADER.pack(1, kind, 1, len(body), 0, 0) + body

    parameters = {
        "DOCUMENT_ROOT": str(public),
        "SCRIPT_FILENAME": str(public / "index.php"),
        "SCRIPT_NAME": "/index.php",
        "REQUEST_URI": uri,
        "REQUEST_METHOD": "GET",
        "SERVER_PROTOCOL": "HTTP/1.1",
        "SERVER_NAME": "gateway.orbit",
        "SERVER_PORT": "443",
        "HTTP_HOST": "gateway.orbit",
        "HTTPS": "on",
        "REMOTE_ADDR": "127.0.0.1",
    }
    encoded = b""
    for name, value in parameters.items():
        name, value = name.encode(), value.encode()
        for item in (name, value):
            size = len(item)
            encoded += bytes([size]) if size < 128 else struct.pack("!I", size | (1 << 31))
        encoded += name + value

    output = bytearray()
    with socket.socket(socket.AF_UNIX) as connection:
        connection.settimeout(5)
        connection.connect(SOCKET)
        connection.sendall(record(1, b"\x00\x01" + bytes(6)) + record(4, encoded) + record(4) + record(5))

        with connection.makefile("rb") as stream:
            def read(size):
                data = stream.read(size)
                if len(data) != size:
                    raise ValueError("incomplete FastCGI response")
                return data

            remaining = 1024 * 1024
            for header in iter(partial(stream.read, HEADER.size), b""):
                if len(header) != HEADER.size:
                    raise ValueError("incomplete FastCGI response")
                version, kind, request_id, size, padding, _ = HEADER.unpack(header)
                if (version, request_id) != (1, 1):
                    raise ValueError("unexpected FastCGI response")
                remaining -= HEADER.size + size + padding
                if remaining < 0:
                    raise ValueError("oversized FastCGI response")
                body = read(size)
                read(padding)
                if kind == 3:
                    if len(body) != 8 or any(body[:5]):
                        raise ValueError("unsuccessful FastCGI request")
                    break
                if kind == 6:
                    output.extend(body)
            else:
                raise ValueError("incomplete FastCGI response")

    headers, delimiter, body = output.partition(b"\r\n\r\n")
    if not delimiter or any(
        line.lower().startswith(b"status:") and line[7:].split()[:1] != [b"200"]
        for line in headers.splitlines()
    ):
        raise ValueError(f"{uri} did not return HTTP 200")
    return body


def check(checkout, sha):
    checkout = checkout.resolve(strict=True)
    if (checkout / "REVISION").read_text().strip() != sha:
        raise ValueError("host checkout changed")
    public = checkout / "apps/gateway/public"
    request(public, "/up")
    response = json.loads(request(public, "/api/v1/gateway/status"))
    status = response.get("data", response)
    if status.get("status") != "ok" or status.get("version") not in (sha, sha[:12]):
        raise ValueError("host PHP-FPM did not report the selected release")


if __name__ == "__main__":
    try:
        check(Path(sys.argv[1]), sys.argv[2])
    except (AttributeError, OSError, ValueError) as error:
        sys.exit(f"Gateway host is not ready: {error}")
