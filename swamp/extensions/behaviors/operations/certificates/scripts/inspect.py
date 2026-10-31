"""Print the public presence result selected by shared terminal projection."""

import os

from leaf import admitted, roots
from public_input import emit, intent


def main():
    data, _account = admitted(intent())
    root, caddy = roots(data)
    present = (root / "current/cert.pem").is_file() and (
        caddy / "current/cert.pem"
    ).is_file()
    if present != (os.environ["ORBIT_CERTIFICATE_EXPECT_PRESENT"] == "true"):
        raise ValueError("certificate.presence_mismatch")
    return {"present": present}


if __name__ == "__main__":
    emit(main)
