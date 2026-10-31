"""Publish public certificates using the private key already on this Node."""

import base64
import json
import os

from leaf import admitted, publish, roots, valid
from public_input import emit, intent


def main():
    data, account = admitted(intent())
    root, caddy = roots(data)
    # A resumed publication observes its original completed pair.
    if valid(root / "current", caddy / "current", data):
        return {"applied": True}
    signed = json.loads(os.environ["ORBIT_CERTIFICATE_SIGNED"])
    for source, target in (
        ("certificate_base64", "certificate"),
        ("root_certificate_base64", "root_certificate"),
    ):
        pem = base64.b64decode(signed[source], validate=True).decode()
        if not pem.startswith("-----BEGIN CERTIFICATE-----") or "PRIVATE KEY" in pem:
            raise ValueError("certificate.signature_invalid")
        data[target] = pem
    publish(data, account)
    return {"applied": True}


if __name__ == "__main__":
    emit(main)
